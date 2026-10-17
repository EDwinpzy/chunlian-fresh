"""Review/check and switch a verified package on the existing Chunlian server.

No installation, seeded data, account changes or upload replacement. Apply only
after client and isolated business acceptance. The release applies only the
forward 0006-0009 migrations, which are replay-safe and expand the schema. File
rollback preserves the additive schema and all production data.
"""
import argparse
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import shutil
import socket
import ssl
import subprocess
import tarfile
import tempfile
import time

parser = argparse.ArgumentParser()
parser.add_argument('--package', required=True)
parser.add_argument('--apply', action='store_true')
args = parser.parse_args()
REQUIRED_FORWARD_MIGRATIONS = (
    '2026_10_17_000006_add_notification_popup_fields.sql',
    '2026_10_17_000007_add_home_featured_product_ids.sql',
    '2026_10_17_000008_unify_order_status.sql',
    '2026_10_17_000009_create_veg_push_device.sql',
)
root = Path('/var/www/chunlian')
nginx = Path('/etc/nginx/sites-available/chunlian.conf')
flag = root / 'maintenance.flag'
if not root.is_dir() or not nginx.is_file() or flag.exists():
    raise SystemExit('Project/config missing or maintenance already active')

def run(*command):
    return subprocess.check_output(command, stderr=subprocess.STDOUT).decode().strip()

def sql(statement):
    return run('mysql', '--protocol=socket', '-uroot', '-N', '-e', statement)

def apply_migration(path):
    migration_sql = path.read_text(encoding='utf-8')
    if path.name == '2026_10_17_000008_unify_order_status.sql':
        # Reconcile legacy writes once during a controlled release, including
        # writes made while the old application code was restored.
        migration_sql = 'SET @cl_legacy_sync = 1;\n' + migration_sql
    completed = subprocess.run(
        [
            'mysql', '--protocol=socket', '-uroot', '--default-character-set=utf8mb4',
            '--batch', '--skip-column-names', '--silent', '--show-warnings', 'chunlian_shop',
        ],
        input=migration_sql,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    if completed.returncode != 0:
        raise RuntimeError('Migration failed: ' + path.name + '\n' + completed.stdout[-4000:])

def schema_health():
    expected_tables = {
        'veg_notification_seen',
        'veg_shop_profile',
        'veg_fresh_category_root',
        'veg_fresh_category_state',
        'veg_push_device',
    }
    existing_tables = set(filter(None, sql(
        "SELECT TABLE_NAME FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME IN "
        "('veg_notification_seen','veg_shop_profile','veg_fresh_category_root',"
        "'veg_fresh_category_state','veg_push_device');"
    ).splitlines()))
    if existing_tables != expected_tables:
        raise RuntimeError('Required release tables are missing: ' + ', '.join(sorted(expected_tables - existing_tables)))

    expected_columns = {'order_status', 'fulfillment', 'receipt_state', 'received_at'}
    existing_columns = set(filter(None, sql(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_order_delivery' "
        "AND COLUMN_NAME IN ('order_status','fulfillment','receipt_state','received_at');"
    ).splitlines()))
    if existing_columns != expected_columns:
        raise RuntimeError('Order compatibility columns are missing: ' + ', '.join(sorted(expected_columns - existing_columns)))

    profile_columns = {'notification_popup_title', 'notification_popup_image_url', 'home_featured_product_ids'}
    existing_profile_columns = set(filter(None, sql(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_shop_profile' "
        "AND COLUMN_NAME IN ('notification_popup_title','notification_popup_image_url','home_featured_product_ids');"
    ).splitlines()))
    if existing_profile_columns != profile_columns:
        raise RuntimeError('Shop profile migration columns are missing: ' + ', '.join(sorted(profile_columns - existing_profile_columns)))

    push_columns = {'actor_type', 'actor_id', 'app_id', 'installation_id', 'cid', 'revoked_at', 'created_at', 'updated_at'}
    existing_push_columns = set(filter(None, sql(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_push_device' "
        "AND COLUMN_NAME IN ('actor_type','actor_id','app_id','installation_id','cid','revoked_at','created_at','updated_at');"
    ).splitlines()))
    if existing_push_columns != push_columns:
        raise RuntimeError('Push device migration columns are missing: ' + ', '.join(sorted(push_columns - existing_push_columns)))

    inconsistent = sql(
        "SELECT COUNT(*) FROM chunlian_shop.veg_order_delivery "
        "WHERE order_status <> IF(receipt_state='CONFIRMED','FINISHED',fulfillment) "
        "OR order_status NOT IN ('PENDING','DELIVERING','DELIVERED','FINISHED','CANCELLED') "
        "OR fulfillment NOT IN ('PENDING','DELIVERING','DELIVERED','FINISHED','CANCELLED') "
        "OR receipt_state NOT IN ('UNCONFIRMED','CONFIRMED') "
        "OR (receipt_state='CONFIRMED' AND received_at IS NULL);"
    )
    if inconsistent != '0':
        raise RuntimeError('Order state aliases are inconsistent or contain unsupported values')

    for table, index in (
        ('veg_order_delivery', 'idx_order_status_expected'),
        ('veg_order_delivery', 'idx_order_status_receivable'),
        ('veg_push_device', 'uk_app_installation'),
    ):
        count = sql(
            "SELECT COUNT(*) FROM information_schema.STATISTICS "
            "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='" + table + "' "
            "AND INDEX_NAME='" + index + "';"
        )
        if count != '1':
            raise RuntimeError('Required schema index is missing: ' + table + '.' + index)

def health(staged):
    """Verify exact public build and APIs locally with production hostname TLS."""
    schema_health()
    if sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_notification_seen';") != '1':
        raise RuntimeError('Notification read marker migration health check failed')
    if sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME IN ('veg_shop_profile','veg_fresh_category_root','veg_fresh_category_state');") != '3':
        raise RuntimeError('Shop configuration migration health check failed')
    context = ssl.create_default_context(cafile='/etc/chunlian/tls/server-chain.pem')
    class Connection(http.client.HTTPSConnection):
        def connect(self):
            self.sock = context.wrap_socket(socket.create_connection(('127.0.0.1', 8096), timeout=20), server_hostname='103.236.97.137')
    def get(path):
        connection = Connection('103.236.97.137', 8096, timeout=20)
        try:
            connection.request('GET', path)
            response = connection.getresponse()
            return response.status, response.read()
        finally:
            connection.close()
    for path in (staged / 'web').rglob('*'):
        if path.is_file():
            status, data = get('/app/' + path.relative_to(staged / 'web').as_posix())
            if status != 200 or hashlib.sha256(data).digest() != hashlib.sha256(path.read_bytes()).digest():
                raise RuntimeError('Deployed asset health check failed')
    status, data = get('/app/orders/1')
    if status != 200 or data != (staged / 'web/index.html').read_bytes():
        raise RuntimeError('Deployed SPA health check failed')
    for path in ('shop', 'catalog/categories', 'catalog/goods?page=1&page_size=30'):
        status, data = get('/api/v1/customer/' + path)
        envelope = json.loads(data)
        if status != 200 or envelope.get('code') != 'OK':
            raise RuntimeError('Public API health check failed')
        if path == 'shop' and not isinstance(envelope.get('data', {}).get('payment_qr_url'), str):
            raise RuntimeError('QR migration API health check failed')
        if path == 'shop':
            profile = envelope.get('data', {})
            for field in ('shop_logo_url', 'shop_address', 'business_hours', 'shop_description', 'delivery_description', 'home_banner_title', 'home_banner_subtitle', 'shop_slogan', 'home_section_title'):
                if not isinstance(profile.get(field), str):
                    raise RuntimeError('Shop profile API field missing: ' + field)
            if not isinstance(profile.get('search_hot_words'), list):
                raise RuntimeError('Shop search configuration missing')
    for role in ('customer', 'merchant'):
        _, data = get('/api/v1/' + role + '/orders')
        if json.loads(data).get('code') != 'FORBIDDEN':
            raise RuntimeError('Anonymous API protection health check failed')
    return 'passed-strict-tls-assets-public-and-protected-api'

with tempfile.TemporaryDirectory(prefix='chunlian-release-', dir='/opt/chunlian') as folder:
    staged = Path(folder)
    with tarfile.open(args.package, 'r:gz') as archive:
        for entry in archive.getmembers():
            dest = (staged / entry.name).resolve()
            if not dest.is_relative_to(staged) or entry.issym() or entry.islnk() or not (entry.isfile() or entry.isdir()):
                raise SystemExit('Unsafe package entry')
        archive.extractall(staged)
    manifest = json.loads((staged / 'manifest.json').read_text())
    if not re.fullmatch(r'[a-zA-Z0-9_-]{1,80}', manifest['release']):
        raise SystemExit('Invalid release identifier')
    actual_files = {str(path.relative_to(staged)) for path in staged.rglob('*') if path.is_file() and path.name != 'manifest.json'}
    if actual_files != set(manifest['files']):
        raise SystemExit('Package contains unverified files')
    for name, digest in manifest['files'].items():
        path = (staged / name).resolve()
        if not path.is_relative_to(staged) or hashlib.sha256(path.read_bytes()).hexdigest() != digest:
            raise SystemExit('Package manifest mismatch')
    for base in ['backend-extension/src', 'backend-extension/config', 'backend-extension/scripts', 'plugin']:
        for path in (staged / base).rglob('*.php'):
            run('php', '-l', str(path))
    notification_migration = staged / 'backend-extension/migrations/2026_10_14_000004_create_veg_notification_seen.sql'
    profile_migration = staged / 'backend-extension/migrations/2026_10_15_000005_create_veg_shop_profile_and_fresh_category_config.sql'
    if not profile_migration.is_file():
        raise SystemExit('Shop configuration migration missing from verified package')
    if not notification_migration.is_file():
        raise SystemExit('Notification read marker migration missing from verified package')
    missing_migrations = [
        name for name in REQUIRED_FORWARD_MIGRATIONS
        if not (staged / 'backend-extension/migrations' / name).is_file()
    ]
    if missing_migrations:
        raise SystemExit('Current release migrations missing: ' + ', '.join(missing_migrations))
    for name in ('config/extension.php', 'scripts/outbox-worker.php', 'scripts/migrate.php', 'scripts/health-check.php'):
        if not (staged / 'backend-extension' / name).is_file():
            raise SystemExit('Required backend runtime file missing: ' + name)
    tables = sql("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME IN ('veg_shop_setting','veg_customer_session','veg_order_delivery','veg_payment_ledger','veg_order_revision');")
    if tables != '5':
        raise SystemExit('Existing project schema is not ready')
    status_sources = sql(
        "SELECT COUNT(*) FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_order_delivery' "
        "AND COLUMN_NAME IN ('fulfillment','order_status');"
    )
    if status_sources not in {'1', '2'}:
        raise SystemExit('Order status source is unavailable; refusing to initialize compatibility fields')
    print(json.dumps({'release': manifest['release'], 'verified_files': len(manifest['files']), 'apply': args.apply}), flush=True)
    if not args.apply:
        raise SystemExit(0)

    # Backups, retained rollback code and staging copies need temporary space.
    required = max(512 * 1024 * 1024, sum(p.stat().st_size for p in root.rglob('*') if p.is_file()) * 3)
    if shutil.disk_usage(root).free < required:
        raise SystemExit('Insufficient free disk space for backup and rollback')

    rollback = Path('/opt/chunlian/releases') / manifest['release'] / 'rollback'
    rollback.mkdir(parents=True, mode=0o700, exist_ok=False)
    shutil.copy2(nginx, rollback / 'nginx.conf')
    shutil.copy2(root / 'shopxo/config/app.php', rollback / 'app.php')
    swapped = []
    try:
        shutil.copy2(staged / 'nginx.conf', nginx)
        run('nginx', '-t')
        flag.touch(mode=0o644)
        run('systemctl', 'reload', 'nginx')
        # Block new business requests before creating the release recovery point.
        # Existing PHP requests finish before the backup starts.
        workers = [int(line.split(None, 1)[0]) for line in run('ps', '-eo', 'pid,args').splitlines() if 'php-fpm: pool ' in line]
        run('systemctl', 'reload', 'php8.3-fpm')
        deadline = time.monotonic() + 120
        while any(Path('/proc', str(pid)).exists() for pid in workers):
            if time.monotonic() >= deadline:
                raise RuntimeError('Existing PHP requests did not finish within the maintenance window')
            time.sleep(0.25)
        backup = json.loads(run('python3', '/opt/chunlian/production-backup.py', '--restore-check'))
        print(json.dumps({'backup': backup['backup'], 'restore_check': backup['restore_check']}), flush=True)
        qr_exists = sql("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='chunlian_shop' AND TABLE_NAME='veg_shop_setting' AND COLUMN_NAME='payment_qr_url';")
        if qr_exists == '0':
            sql("ALTER TABLE chunlian_shop.veg_shop_setting ADD COLUMN payment_qr_url varchar(500) NOT NULL DEFAULT '' COMMENT 'Merchant payment QR image';")
        apply_migration(notification_migration)
        apply_migration(profile_migration)
        for name in REQUIRED_FORWARD_MIGRATIONS:
            apply_migration(staged / 'backend-extension/migrations' / name)
        schema_health()

        replacements = [
            (root / 'backend-extension', staged / 'backend-extension', 'backend-extension'),
            (root / 'shopxo/app/plugins/chunlianfresh', staged / 'plugin', 'plugin'),
            (root / 'shopxo/public/app', staged / 'web', 'web'),
        ]
        for dest, source, label in replacements:
            next_dir = dest.with_name(dest.name + '.release-next')
            if next_dir.exists():
                raise RuntimeError('Unexpected existing staging directory')
            if label == 'backend-extension':
                shutil.copytree(dest, next_dir)
                for directory in ('src', 'config', 'migrations', 'scripts'):
                    existing = next_dir / directory
                    if existing.exists():
                        shutil.rmtree(existing)
                    shutil.copytree(source / directory, existing)
            else:
                shutil.copytree(source, next_dir)
            # PHP/nginx may read code, but runtime accounts must never own or
            # write it. Uploads and ShopXO runtime stay outside these trees.
            run('chown', '-R', 'root:www-data', str(next_dir))
            run('chmod', '-R', 'u=rwX,g=rX,o=', str(next_dir))
            os.replace(dest, rollback / label)
            try:
                os.replace(next_dir, dest)
            except BaseException:
                os.replace(rollback / label, dest)
                raise
            swapped.append((dest, label))
        app = root / 'shopxo/config/app.php'
        text = app.read_text()
        text, count = re.subn(r"('show_error_msg'\s*=>\s*)(?:true|false)", r'\1false', text)
        if count != 1:
            raise RuntimeError('Unexpected framework error setting')
        app.write_text(text)
        run('systemctl', 'reload', 'php8.3-fpm')
        run('nginx', '-t')
        verified_health = health(staged)
        flag.unlink()
        print(json.dumps({'status': 'deployed', 'release': manifest['release'], 'backup': backup['backup'], 'rollback': str(rollback), 'health': verified_health}), flush=True)
    except BaseException:
        flag.touch(mode=0o644)
        for dest, label in reversed(swapped):
            failed = rollback / (label + '-failed')
            os.replace(dest, failed)
            os.replace(rollback / label, dest)
            run('chown', '-R', 'root:www-data', str(dest))
            run('chmod', '-R', 'u=rwX,g=rX,o=', str(dest))
        shutil.copy2(rollback / 'app.php', root / 'shopxo/config/app.php')
        shutil.copy2(rollback / 'nginx.conf', nginx)
        run('nginx', '-t')
        run('systemctl', 'reload', 'php8.3-fpm')
        run('systemctl', 'reload', 'nginx')
        flag.unlink(missing_ok=True)
        raise
