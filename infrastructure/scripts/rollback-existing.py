"""Restore one retained project release without rolling back database state.

Default is read-only inspection. --apply requires the exact retained release ID.
Never imports a database or touches another service. It verifies the legacy
1.0.5 order columns still exist before switching code. A failed rollback leaves
maintenance enabled for a deliberate repair instead of serving mixed code.
"""
import argparse
import datetime
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import time

parser = argparse.ArgumentParser()
parser.add_argument('--release', required=True)
parser.add_argument('--apply', action='store_true')
args = parser.parse_args()
if not re.fullmatch(r'[a-zA-Z0-9_-]{1,80}', args.release):
    raise SystemExit('Invalid release identifier')
root = Path('/var/www/chunlian')
rollback = Path('/opt/chunlian/releases') / args.release / 'rollback'
nginx = Path('/etc/nginx/sites-available/chunlian.conf')
flag = root / 'maintenance.flag'
paths = [
    (root / 'backend-extension', rollback / 'backend-extension'),
    (root / 'shopxo/app/plugins/chunlianfresh', rollback / 'plugin'),
    (root / 'shopxo/public/app', rollback / 'web'),
]
if not all(source.is_dir() and dest.is_dir() for dest, source in paths) or not all((rollback / name).is_file() for name in ('app.php', 'nginx.conf')):
    raise SystemExit('Complete retained rollback is unavailable')
if (rollback / 'restored.json').exists():
    raise SystemExit('This rollback was already restored')
print(json.dumps({'release': args.release, 'rollback': str(rollback), 'apply': args.apply}), flush=True)
if not args.apply:
    raise SystemExit(0)

def run(*command):
    return subprocess.check_output(command, stderr=subprocess.STDOUT).decode().strip()

legacy_order_columns = run(
    'mysql', '--protocol=socket', '-uroot', '-N', '-e',
    "SELECT COUNT(*) FROM information_schema.COLUMNS "
    "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_order_delivery' "
    "AND COLUMN_NAME IN ('fulfillment','receipt_state');",
)
if legacy_order_columns != '2':
    raise SystemExit('Cannot safely restore 1.0.5 code: legacy order columns are missing')

for _, source in paths[:2]:
    for path in source.rglob('*.php'):
        run('php', '-l', str(path))
flag.touch(mode=0o644)
workers = [int(line.split(None, 1)[0]) for line in run('ps', '-eo', 'pid,args').splitlines() if 'php-fpm: pool ' in line]
run('systemctl', 'reload', 'php8.3-fpm')
deadline = time.monotonic() + 120
while any(Path('/proc', str(pid)).exists() for pid in workers):
    if time.monotonic() >= deadline:
        raise RuntimeError('Existing requests did not finish; maintenance remains active')
    time.sleep(0.25)
failed = rollback / ('replaced-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ'))
failed.mkdir(mode=0o700)
for dest, source in paths:
    os.replace(dest, failed / source.name)
    os.replace(source, dest)
    run('chown', '-R', 'root:www-data', str(dest))
    run('chmod', '-R', 'u=rwX,g=rX,o=', str(dest))
shutil.copy2(rollback / 'app.php', root / 'shopxo/config/app.php')
shutil.copy2(rollback / 'nginx.conf', nginx)
run('nginx', '-t')
run('systemctl', 'reload', 'php8.3-fpm')
run('systemctl', 'reload', 'nginx')
flag.unlink()
report = {'status': 'restored', 'release': args.release, 'database': 'preserved including compatible payment_qr_url column', 'replaced_code': str(failed)}
(rollback / 'restored.json').write_text(json.dumps(report, indent=2) + '\n')
print(json.dumps(report), flush=True)
