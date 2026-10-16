"""Loopback-only QA tunnel using the configured cloud plugin connection.

Credentials are resolved by the plugin and remain in memory. No public listener.
"""
import argparse
import importlib.util
import pathlib
import socket
import socketserver
import sys
import threading

parser = argparse.ArgumentParser()
parser.add_argument('--plugin-root', required=True)
parser.add_argument('--port', type=int, default=18096)
parser.add_argument('--listen-port', type=int, help='local loopback port; defaults to the remote QA port')
args = parser.parse_args()
sys.path.insert(0, args.plugin_root)
spec = importlib.util.spec_from_file_location('cloud_qa_connection', pathlib.Path(args.plugin_root) / 'server.py')
plugin = importlib.util.module_from_spec(spec)
spec.loader.exec_module(plugin)
_, item = plugin.connection('panshi-cloud')
plugin.invoke('panshi-cloud', 'true', b'', timeout=20)
key = (item['host'], item['user'], int(item.get('port', 22)), item['credential_ref'])
transport = plugin.SSH_CLIENTS[key].get_transport()

class Handler(socketserver.BaseRequestHandler):
    @staticmethod
    def relay(source, destination):
        try:
            while True:
                data = source.recv(65536)
                if not data:
                    return
                destination.sendall(data)
        except (OSError, EOFError):
            return
        finally:
            try:
                if hasattr(destination, 'shutdown_write'):
                    destination.shutdown_write()
                else:
                    destination.shutdown(socket.SHUT_WR)
            except (OSError, EOFError, AttributeError):
                pass

    def handle(self):
        channel = transport.open_channel('direct-tcpip', ('127.0.0.1', args.port), self.request.getpeername())
        if channel is None:
            return
        try:
            upstream = threading.Thread(target=self.relay, args=(self.request, channel), daemon=True)
            downstream = threading.Thread(target=self.relay, args=(channel, self.request), daemon=True)
            upstream.start()
            downstream.start()
            upstream.join()
            downstream.join()
        finally:
            channel.close()

class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True

listen_port = args.listen_port or args.port
with Server(('127.0.0.1', listen_port), Handler) as server:
    print(f'QA tunnel listening on loopback port {listen_port} to remote port {args.port}', flush=True)
    server.serve_forever()
