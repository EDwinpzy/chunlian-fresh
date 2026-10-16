"""Read-only hashes of persisted project orders, funds and existing shop settings.

No customer rows or credentials are printed or saved. Run on the project server.
A difference may also reflect real concurrent business activity; inspect it before
deciding what changed. This check never restores or modifies database records.
"""
import argparse
import datetime
import hashlib
import json
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser()
parser.add_argument('--out', required=True)
parser.add_argument('--compare')
args = parser.parse_args()

def sql(statement):
    return subprocess.check_output(['mysql', '--protocol=socket', '-uroot', '-N', '-B', '-e', statement])

tables = ['shopxo_order', 'shopxo_order_detail', 'veg_order_delivery', 'veg_order_line',
          'veg_order_revision', 'veg_payment_ledger']
fingerprints = {}
for table in tables:
    raw = subprocess.check_output(['mysqldump', '--protocol=socket', '-uroot',
        '--single-transaction', '--no-create-info', '--skip-triggers', '--compact',
        '--skip-add-locks', '--skip-extended-insert', '--order-by-primary', '--hex-blob',
        'chunlian_shop', table])
    fingerprints[table] = {'rows': int(sql(f'SELECT COUNT(*) FROM chunlian_shop.`{table}`;')),
                           'sha256': hashlib.sha256(raw).hexdigest()}

# Compare the existing setting columns across the additive QR migration.
raw = sql("SELECT JSON_ARRAY(id,shop_name,notice,phone,delivery_fee_fen,is_open,min_lead_minutes,updated_at) "
          "FROM chunlian_shop.veg_shop_setting ORDER BY id;")
fingerprints['veg_shop_setting_existing_columns'] = {
    'rows': int(sql('SELECT COUNT(*) FROM chunlian_shop.veg_shop_setting;')),
    'sha256': hashlib.sha256(raw).hexdigest(),
}
report = {'timestamp': datetime.datetime.now(datetime.timezone.utc).isoformat(),
          'scope': 'persisted order, line, revision, ledger and existing shop setting data; no writes',
          'tables': fingerprints}
if args.compare:
    before = json.loads(Path(args.compare).read_text())
    report['unchanged'] = before['tables'] == fingerprints
    report['different_tables'] = [name for name in fingerprints if before['tables'].get(name) != fingerprints[name]]
Path(args.out).write_text(json.dumps(report, indent=2) + '\n')
Path(args.out).chmod(0o600)
print(json.dumps(report))
raise SystemExit(1 if report.get('unchanged') is False else 0)
