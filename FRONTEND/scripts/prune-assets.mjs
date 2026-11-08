// Vite writes its build to the project root (see vite.config.js) because the
// SPA is served same-origin with /api. `emptyOutDir` must stay false so Vite
// never deletes source files, which means old hashed bundles are left behind.
// This postbuild step keeps only the assets referenced by index.html.
import { readFile, readdir, unlink } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import path from 'node:path'

const root = fileURLToPath(new URL('../../', import.meta.url))
const assetsDir = path.join(root, 'assets')

const html = await readFile(path.join(root, 'index.html'), 'utf8')
const referenced = new Set(
  [...html.matchAll(/\/assets\/([^"'>\s]+)/g)].map(match => match[1])
)

if (referenced.size === 0) {
  console.warn('prune-assets: index.html references no /assets files; skipping.')
  process.exit(0)
}

let removed = 0
for (const name of await readdir(assetsDir)) {
  if (!referenced.has(name)) {
    await unlink(path.join(assetsDir, name))
    removed += 1
  }
}
console.log(`prune-assets: kept ${referenced.size} referenced file(s), removed ${removed} stale file(s).`)
