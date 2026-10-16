import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const vitePath = fileURLToPath(new URL('../node_modules/vite/bin/vite.js', import.meta.url))
const server = spawn(
  process.execPath,
  [vitePath, '--host', '127.0.0.1', '--port', '5174', '--strictPort'],
  {
    stdio: 'inherit',
    env: { ...process.env, VITE_API_TARGET: 'http://127.0.0.1:18099' },
  },
)

const stop = () => server.kill()
process.on('SIGINT', stop)
process.on('SIGTERM', stop)
server.on('exit', (code) => process.exit(code ?? 0))
