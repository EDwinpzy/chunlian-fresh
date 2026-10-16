"""Private production backup and an isolated socket-only restoration check.

Run on the configured server as root. Never emits customer rows or credentials.
The restoration database has no application grants and is dropped after inspection.
"""
import argparse
import datetime
import gzip
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile

parser = argparse.ArgumentParser()
parser.add_argument('--restore-check', action='store_true')
args = parser.parse_args()
root = Path('/var/www/chunlian')
if not (root / 'shopxo/config/database.php').is_file():
    raise SystemExit('Expected installed project missing')
stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
target = Path('/opt/chunlian/backups/chunlian-fresh') / stamp
target.mkdir(mode=0o700)

def mysql(sql):
    return subprocess.check_output(['mysql', '--protocol=socket', '-uroot', '-N', '-e', sql]).decode().strip()

before = mysql('SELECT COUNT(*) FROM chunlian_shop.shopxo_order; SELECT COUNT(*) FROM chunlian_shop.veg_payment_ledger;')
with (target / 'database.sql.gz').open('wb') as raw:
    (target / 'database.sql.gz').chmod(0o600)
    with gzip.GzipFile(fileobj=raw, mode='wb') as compressed:
        process = subprocess.Popen(['mysqldump', '--protocol=socket', '-uroot', '--single-transaction', '--routines', '--triggers', '--no-tablespaces', 'chunlian_shop'], stdout=subprocess.PIPE)
        while chunk := process.stdout.read(1024 * 1024):
            compressed.write(chunk)
        if process.wait() != 0:
            raise SystemExit('Database export failed')

for name, paths in {
    'code-config.tar.gz': [root / 'backend-extension', root / 'shopxo/app/plugins/chunlianfresh', root / 'shopxo/public/app', root / 'shopxo/config', Path('/etc/nginx/sites-available/chunlian.conf')],
    'uploads.tar.gz': [root / 'shopxo/public/static/upload'],
}.items():
    with tarfile.open(target / name, 'w:gz') as archive:
        for path in paths:
            archive.add(path, arcname=str(path).lstrip('/'))
    (target / name).chmod(0o600)

restore = 'not-run'
if args.restore_check:
    db = 'chunlian_restorecheck_' + stamp.lower()
    if mysql(f"SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='{db}';") != '0':
        raise SystemExit('Restoration target already exists')
    mysql(f'CREATE DATABASE `{db}` CHARACTER SET utf8mb4;')
    try:
        process = subprocess.Popen(['mysql', '--protocol=socket', '-uroot', db], stdin=subprocess.PIPE)
        with gzip.open(target / 'database.sql.gz', 'rb') as source:
            while chunk := source.read(1024 * 1024):
                process.stdin.write(chunk)
        process.stdin.close()
        if process.wait() != 0:
            raise SystemExit('Restoration import failed')
        restored = mysql(f'SELECT COUNT(*) FROM `{db}`.shopxo_order; SELECT COUNT(*) FROM `{db}`.veg_payment_ledger;')
        if restored != before:
            raise SystemExit('Restoration count mismatch')
        restore = 'passed-order-ledger-counts'
    finally:
        mysql(f'DROP DATABASE `{db}`;')

files = []
for path in sorted(target.iterdir()):
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    files.append({'name': path.name, 'bytes': path.stat().st_size, 'sha256': digest})
    # Connector transfers are bounded to 8 MiB; preserve complete files plus parts.
    if path.stat().st_size > 8 * 1024 * 1024:
        with path.open('rb') as source:
            index = 0
            while chunk := source.read(8 * 1024 * 1024):
                part = path.with_name(path.name + f'.part{index:02d}')
                part.write_bytes(chunk)
                part.chmod(0o600)
                index += 1
report = {'backup': str(target), 'restore_check': restore, 'order_count': int(before.splitlines()[0]), 'ledger_count': int(before.splitlines()[1]), 'files': files}
(target / 'manifest.json').write_text(json.dumps(report, indent=2) + '\n')
(target / 'manifest.json').chmod(0o600)
print(json.dumps(report))
