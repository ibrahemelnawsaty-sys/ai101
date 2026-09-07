import fs from 'node:fs';
import path from 'node:path';

const ROOT = 'c:/Users/b.maher/Downloads/wesal/LARAVEL';
process.chdir(ROOT);

function walk(d, out = []) {
  for (const e of fs.readdirSync(d, { withFileTypes: true })) {
    const p = path.join(d, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.blade.php')) out.push(p);
  }
  return out;
}

const SKIP = new Set(['this', 'attributes', 'slot', 'loop', 'errors', 'page', 'message', 'component']);
const views = walk('resources/views');
const map = {};

for (const f of views) {
  const s = fs.readFileSync(f, 'utf8');
  const rel = f.split(path.sep).join('/');
  const re = /\$([a-zA-Z_][a-zA-Z0-9_]*)->([a-zA-Z_][a-zA-Z0-9_]*)(?!\s*\()/g;
  let m;
  while ((m = re.exec(s))) {
    const v = m[1], p = m[2];
    if (SKIP.has(v)) continue;
    map[v] ??= { props: new Set(), files: new Set() };
    map[v].props.add(p);
    map[v].files.add(rel);
  }
}

const rows = Object.entries(map).sort((a, b) => b[1].props.size - a[1].props.size);
const out = [
  '# Inventory of view-model properties dereferenced by the Blade layer.',
  '# Generated mechanically from resources/views/**/*.blade.php.',
  '# Any property listed here MUST be readable on the value the controller passes,',
  '# or the screen fatals / renders blank.',
  '',
];
for (const [v, d] of rows) {
  out.push(`$${v}   (${d.files.size} files, ${d.props.size} properties)`);
  out.push('  properties: ' + [...d.props].sort().join(', '));
  out.push('  files: ' + [...d.files].sort().join(', '));
  out.push('');
}
fs.writeFileSync('docs/04-design/VIEW-MODEL-INVENTORY.txt', out.join('\n'));
console.log('wrote docs/04-design/VIEW-MODEL-INVENTORY.txt');
console.log('variables:', rows.length, '| distinct properties:', rows.reduce((a, [, d]) => a + d.props.size, 0));
