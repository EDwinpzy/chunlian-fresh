"""Verify final signed APKs without printing signing secrets."""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import re
import subprocess
import zipfile
from prepare_download_page import render_download_page

parser = argparse.ArgumentParser()
parser.add_argument('--build-tools', required=True)
parser.add_argument('--release', help='Release identifier written into android-release.json')
parser.add_argument('--release-notes', default='', help='Short update notes shown inside both apps')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
build_tools = Path(args.build_tools)
checks = []
packages = []


def check(name, passed):
    checks.append({'name': name, 'passed': bool(passed)})
    print(('PASS ' if passed else 'FAIL ') + name)


for label, package in [('customer', 'com.chunlian.customer'), ('merchant', 'com.chunlian.merchant')]:
    apk = root / 'dist/release' / (label + '-release.apk')
    result = subprocess.run([str(build_tools / 'apksigner.bat'), 'verify', '--verbose', '--print-certs', str(apk)], capture_output=True, text=True, encoding='utf-8')
    check(label + ' APK signature verification', result.returncode == 0)
    signer = re.search(r'Signer #1 certificate SHA-256 digest: ([0-9a-f]+)', result.stdout)
    check(label + ' APK contains signer certificate', signer is not None)
    badging = subprocess.check_output([str(build_tools / 'aapt.exe'), 'dump', 'badging', str(apk)]).decode('utf-8')
    check(label + ' application ID', f"package: name='{package}'" in badging)
    version = re.search(r"package: name='[^']+' versionCode='(\d+)' versionName='([^']+)'", badging)
    check(label + ' APK version metadata', version is not None)
    gradle = (root / f'apps/{label}-android/app/build.gradle.kts').read_text(encoding='utf-8')
    configured_code = re.search(r'\bversionCode\s*=\s*(\d+)', gradle)
    configured_name = re.search(r'\bversionName\s*=\s*"([^"]+)"', gradle)
    check(label + ' APK version matches Gradle configuration', version is not None and configured_code is not None and configured_name is not None and version.group(1) == configured_code.group(1) and version.group(2) == configured_name.group(1))
    check(label + ' minimum Android version', "sdkVersion:'24'" in badging)
    check(label + ' release is not debuggable', 'application-debuggable' not in badging)
    resources = subprocess.check_output([str(build_tools / 'aapt.exe'), 'dump', '--values', 'resources', str(apk)]).decode('utf-8')
    # AAPT may shorten packaged resource filenames even with minification off.
    ca_resource = re.search(r'resource 0x[0-9a-f]+ [^\s]+:raw/chunlian_ca:[^\n]*\n\s*\(string\d+\) "([^"]+)"', resources)
    check(label + ' named CA resource resolves to a packaged file', ca_resource is not None)
    security_resource = re.search(r'resource (0x[0-9a-f]+) [^\s]+:xml/network_security_config:[^\n]*\n\s*\(string\d+\) "([^"]+)"', resources)
    manifest_tree = subprocess.check_output([str(build_tools / 'aapt.exe'), 'dump', 'xmltree', str(apk), 'AndroidManifest.xml']).decode('utf-8')
    check(label + ' manifest uses verified network security configuration', security_resource is not None and f'android:networkSecurityConfig(0x01010527)=@{security_resource.group(1)}' in manifest_tree)
    security_tree = subprocess.check_output([str(build_tools / 'aapt.exe'), 'dump', 'xmltree', str(apk), security_resource.group(2)]).decode('utf-8') if security_resource else ''
    cleartext_flags = re.findall(r'cleartextTrafficPermitted=\(type 0x12\)(0x[0-9a-f]+)', security_tree)
    check(label + ' packaged security configuration forbids cleartext', 'E: base-config' in security_tree and len(cleartext_flags) >= 2 and all(flag == '0x0' for flag in cleartext_flags))
    ca_id = re.search(r'resource (0x[0-9a-f]+) [^\s]+:raw/chunlian_ca:', resources)
    base_tree, _, domain_tree = security_tree.partition('E: domain-config')
    check(label + ' project CA is scoped to exact production IP', ca_id is not None and security_tree.count('E: domain (') == 1 and 'C: "103.236.97.137"' in domain_tree and 'includeSubdomains=(type 0x12)0x0' in domain_tree and f'src=@{ca_id.group(1)}' in domain_tree and 'src=@' not in base_tree)
    with zipfile.ZipFile(apk) as archive:
        dex_files = [archive.read(name) for name in archive.namelist() if re.fullmatch(r'classes\d*\.dex', name)]
        check(label + ' production HTTPS endpoint in compiled APK', any(b'https://103.236.97.137:8096' in data for data in dex_files))
        check(label + ' isolated QA endpoint absent', all(b'http://127.0.0.1:18096' not in data for data in dex_files))
        cert = archive.read(ca_resource.group(1)) if ca_resource and ca_resource.group(1) in archive.namelist() else b''
        check(label + ' trusted project CA matches delivery', cert == (root / 'dist/chunlian-ca.pem').read_bytes())
    packages.append({
        'file': apk.name,
        'application_id': package,
        'bytes': apk.stat().st_size,
        'sha256': hashlib.sha256(apk.read_bytes()).hexdigest(),
        'certificate_sha256': signer.group(1) if signer else None,
        'version_code': int(version.group(1)) if version else None,
        'version_name': version.group(2) if version else None,
    })

check('both APKs use the same project signing certificate', packages[0]['certificate_sha256'] is not None and packages[0]['certificate_sha256'] == packages[1]['certificate_sha256'])
passed = all(item['passed'] for item in checks)
report = {'checked_at': datetime.datetime.now(datetime.timezone.utc).isoformat(), 'scope': 'signed artifact verification; device installation and UI acceptance are recorded separately', 'packages': packages, 'checks': checks, 'passed': sum(item['passed'] for item in checks), 'total': len(checks)}
(root / 'docs/evidence/release-artifacts.json').write_text(json.dumps(report, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')

if passed:
    manifest_path = root / 'dist/release/android-release.json'
    previous = {}
    if manifest_path.is_file():
        try:
            previous = json.loads(manifest_path.read_text(encoding='utf-8'))
        except (OSError, json.JSONDecodeError):
            previous = {}
    release_id = args.release or previous.get('release') or 'chunlian-android-release'
    public_packages = []
    for label, package in zip(('customer', 'merchant'), packages):
        version_name = package['version_name']
        if not isinstance(version_name, str) or re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._-]{0,79}', version_name) is None:
            raise SystemExit('APK version name is invalid')
        public_file = f'{label}-release.apk'
        public_packages.append({
            'application_id': package['application_id'],
            'file': public_file,
            'version_name': package['version_name'],
            'version_code': package['version_code'],
            'sha256': package['sha256'],
            'bytes': package['bytes'],
            'release_notes': args.release_notes.strip(),
        })
    manifest = {'release': release_id, 'packages': public_packages}
    temporary_manifest = manifest_path.with_suffix('.json.tmp')
    temporary_manifest.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
    temporary_manifest.replace(manifest_path)
    render_download_page(root, manifest)
    print('PASS downloads index, compatibility copy, campaign image, and actual app icons generated')
    print('PASS android-release.json generated; upload both fixed-name APKs before the manifest to /downloads/')

raise SystemExit(0 if passed else 1)
