"""Build a reviewable server package and a source bundle without local secrets."""
import argparse
import gzip
import hashlib
import json
from pathlib import Path
import shutil
import tarfile
import tempfile
import zipfile

parser = argparse.ArgumentParser()
parser.add_argument('--release', required=True)
parser.add_argument('--source-bundle', action='store_true')
args = parser.parse_args()
root = Path(__file__).resolve().parents[2]
out = root / 'dist/release'
out.mkdir(parents=True, exist_ok=True)
if not (root / 'apps/customer-web/dist/index.html').is_file():
    raise SystemExit('Build customer-web before packaging')
with tempfile.TemporaryDirectory(prefix='chunlian-package-') as folder:
    staged = Path(folder)
    for src, dest in [
        ('backend/extension/src', 'backend-extension/src'),
        ('backend/extension/migrations', 'backend-extension/migrations'),
        ('backend/shopxo-plugin/chunlianfresh', 'plugin'),
        ('apps/customer-web/dist', 'web'),
    ]:
        shutil.copytree(root / src, staged / dest)
    shutil.copy2(root / 'infrastructure/nginx/chunlian.conf', staged / 'nginx.conf')
    files = {str(p.relative_to(staged)).replace('\\', '/'): hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(staged.rglob('*')) if p.is_file()}
    (staged / 'manifest.json').write_text(json.dumps({'release': args.release, 'files': files}, indent=2) + '\n')
    package = out / 'chunlian-server.tar.gz'
    def release_permissions(info):
        # Windows modes must not become world-writable Linux deployment files.
        info.uid = info.gid = 0
        info.uname = info.gname = 'root'
        info.mode = 0o755 if info.isdir() else 0o644
        info.mtime = 0
        return info

    with package.open('wb') as raw, gzip.GzipFile(filename='', mode='wb', fileobj=raw, mtime=0) as compressed:
        with tarfile.open(fileobj=compressed, mode='w') as archive:
            for path in sorted(staged.iterdir()):
                archive.add(path, arcname=path.name, filter=release_permissions)
    print(json.dumps({'server_package': str(package), 'sha256': hashlib.sha256(package.read_bytes()).hexdigest(), 'verified_files': len(files)}))

if args.source_bundle:
    excluded_dirs = {'.git', '.gradle', 'node_modules', 'vendor', 'dist', 'build', '.idea', '__pycache__', 'test-results', 'playwright-report', 'design-assets', '.workbuddy-ai'}
    excluded_names = {'local.properties', 'signing.properties', '.phpunit.result.cache'}
    allowed_roots = {'apps', 'backend', 'infrastructure', 'docs'}
    source_zip = out / 'chunlian-source.zip'
    with zipfile.ZipFile(source_zip, 'w', zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(root.rglob('*')):
            rel = path.relative_to(root)
            # Operational screenshots and QA fixtures stay in the controlled
            # workspace. Share the written acceptance report with source code.
            if any(rel.parts[i:i + 2] == ('docs', 'evidence') for i in range(len(rel.parts) - 1)):
                continue
            if any(part in excluded_dirs for part in rel.parts) or not path.is_file():
                continue
            if len(rel.parts) > 1 and rel.parts[0] not in allowed_roots:
                continue
            if path.name in excluded_names or path.name.startswith('.env') and path.name != '.env.example':
                continue
            if path.suffix.lower() in {'.key', '.jks', '.keystore', '.p12', '.apk', '.aab', '.log', '.tmp', '.bak'}:
                continue
            if path.suffix.lower() == '.pem' and path.name != 'chunlian_ca.pem':
                continue
            archive.write(path, str(rel).replace('\\', '/'))
    print(json.dumps({'source_bundle': str(source_zip), 'bytes': source_zip.stat().st_size}))
