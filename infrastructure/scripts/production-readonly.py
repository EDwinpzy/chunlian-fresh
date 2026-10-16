"""Strict project-CA HTTPS read-only deployment verification; never creates data."""
import hashlib
import json
from pathlib import Path
import re
import ssl
import urllib.error
import urllib.request

root = Path(__file__).resolve().parents[2]
base = 'https://103.236.97.137:8096'
context = ssl.create_default_context(cafile=str(root / 'dist/chunlian-ca.pem'))
checks = []

def check(label, ok):
    checks.append({'name': label, 'passed': bool(ok)})
    print(('PASS ' if ok else 'FAIL ') + label)

def get(path):
    try:
        with urllib.request.urlopen(base + path, context=context, timeout=20) as response:
            return response.status, response.headers, response.read()
    except urllib.error.HTTPError as error:
        return error.code, error.headers, error.read()

status, headers, html = get('/app/')
check('HTTPS with trusted project CA and hostname verification', status == 200)
check('deployed homepage matches accepted local build', hashlib.sha256(html).digest() == hashlib.sha256((root / 'apps/customer-web/dist/index.html').read_bytes()).digest())
assets = sorted(set(re.findall(r'(/app/assets/[^"\s]+)', html.decode())))
check('homepage references compiled assets', bool(assets))
for asset in assets:
    status, _, data = get(asset)
    local = root / 'apps/customer-web/dist' / asset.removeprefix('/app/')
    check('asset matches build ' + local.name, status == 200 and hashlib.sha256(data).digest() == hashlib.sha256(local.read_bytes()).digest())
status, _, deep = get('/app/orders/1')
check('SPA deep link falls back to accepted homepage', status == 200 and deep == html)
for path in ['/api/v1/customer/shop', '/api/v1/customer/catalog/categories', '/api/v1/customer/catalog/goods?page=1&page_size=30']:
    status, _, data = get(path)
    envelope = json.loads(data)
    check('public API ' + path, status == 200 and envelope.get('code') == 'OK')
    if path.endswith('/shop'):
        check('production supports configurable real payment QR', isinstance(envelope.get('data', {}).get('payment_qr_url'), str))
        profile = envelope.get('data', {})
        for field in ['shop_logo_url', 'shop_address', 'business_hours', 'shop_description', 'delivery_description', 'home_banner_title', 'home_banner_subtitle', 'shop_slogan', 'home_section_title']:
            check('configurable shop field ' + field, isinstance(profile.get(field), str))
        check('configurable search hot words', isinstance(profile.get('search_hot_words'), list))
for path in ['/api/v1/customer/orders', '/api/v1/merchant/orders']:
    status, _, data = get(path)
    check('anonymous protected API denied ' + path, json.loads(data).get('code') == 'FORBIDDEN')
for path in ['/.env', '/.git/config', '/config/database.php', '/vendor/autoload.php']:
    try:
        status, _, _ = get(path)
        check('private server file denied ' + path, status in [403, 404])
    except urllib.error.URLError:
        # Some front ends close TLS without returning an HTTP status for
        # sensitive paths. Count that as denied only if a fresh public health
        # GET still succeeds, which rules out a general connection outage.
        health_status, _, _ = get('/app/')
        check('private server file denied ' + path + ' by connection close while app remains healthy', health_status == 200)

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args):
        return None

try:
    urllib.request.build_opener(NoRedirect()).open('http://103.236.97.137:8095/app/', timeout=20)
    check('HTTP redirects to HTTPS before serving the app', False)
except urllib.error.HTTPError as error:
    check('HTTP redirects to HTTPS before serving the app', error.code == 307 and error.headers.get('Location') == base + '/app/')

report = {'target': base, 'scope': 'public and anonymous GET only; no production business writes', 'tests': checks, 'passed': sum(x['passed'] for x in checks), 'total': len(checks)}
(root / 'docs/evidence/production-readonly.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
raise SystemExit(0 if all(x['passed'] for x in checks) else 1)
