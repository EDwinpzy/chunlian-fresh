"""Atomically replace only web files, keep rollback and old hashed chunks."""
import ctypes
import hashlib
import http.client
import json
from pathlib import Path
import shutil
import socket
import ssl
import tarfile

release = 'chunlian-web-art-20261014-v2'
archive = Path('/opt/chunlian') / (release + '.tar.gz')
live = Path('/var/www/chunlian/shopxo/public/app')
staged = live.parent / ('.' + release)
rollback = Path('/opt/chunlian/releases') / release
if staged.exists() or rollback.exists() or not live.is_dir():
    raise SystemExit('Existing staging/rollback or missing live web; inspect before retry')
if shutil.disk_usage(live).free < 100 * 1024 * 1024:
    raise SystemExit('Insufficient rollback space')
rollback.mkdir(mode=0o700)
shutil.copytree(live, staged)
with tarfile.open(archive) as tar:
    for item in tar.getmembers():
        target = (staged / item.name).resolve()
        if not target.is_relative_to(staged) or not item.isfile():
            raise SystemExit('Unsafe archive')
    tar.extractall(staged)
manifest = json.loads((staged / 'web-art-manifest.json').read_text())
for name, digest in manifest.items():
    if hashlib.sha256((staged / name).read_bytes()).hexdigest() != digest:
        raise SystemExit('Manifest mismatch')
(staged / 'web-art-manifest.json').unlink()
for path in [staged, *staged.rglob('*')]:
    path.chmod(0o755 if path.is_dir() else 0o644)
# Linux RENAME_EXCHANGE keeps the site present at every instant.
libc = ctypes.CDLL(None, use_errno=True)
def exchange():
    if libc.renameat2(-100, str(live).encode(), -100, str(staged).encode(), 2) != 0:
        raise OSError(ctypes.get_errno(), 'Atomic web exchange failed')
context = ssl.create_default_context(cafile='/etc/chunlian/tls/server-chain.pem')
class Connection(http.client.HTTPSConnection):
    def connect(self):
        self.sock = context.wrap_socket(socket.create_connection(('127.0.0.1',8096),timeout=20), server_hostname='103.236.97.137')
exchange()
try:
    for name,digest in manifest.items():
        connection = Connection('103.236.97.137',8096,timeout=20)
        connection.request('GET','/app/'+name)
        response = connection.getresponse()
        data = response.read()
        connection.close()
        if response.status != 200 or hashlib.sha256(data).hexdigest()!=digest:
            raise RuntimeError('Public TLS asset mismatch: '+name)
except Exception:
    exchange()
    raise
shutil.move(str(staged), str(rollback / 'web'))
(rollback / 'manifest.json').write_text(json.dumps(manifest,indent=2))
print(json.dumps({'release':release,'verified_files':len(manifest),'rollback':str(rollback),'atomic_exchange':True,'backend_changed':False}))
