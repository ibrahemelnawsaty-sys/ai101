import fs from 'node:fs';
import path from 'node:path';

const ROOT = 'c:/Users/b.maher/Downloads/wesal/LARAVEL';
process.chdir(ROOT);

const walk = (d, ext, out = []) => {
  if (!fs.existsSync(d)) return out;
  for (const e of fs.readdirSync(d, { withFileTypes: true })) {
    const p = path.join(d, e.name);
    if (e.isDirectory()) walk(p, ext, out);
    else if (e.name.endsWith(ext)) out.push(p.split(path.sep).join('/'));
  }
  return out;
};

const blades = walk('resources/views', '.blade.php');
const phps = [...walk('app', '.php'), ...walk('routes', '.php'), ...walk('config', '.php'), ...walk('database', '.php')];
const read = (f) => fs.readFileSync(f, 'utf8');

const problems = { components: [], routes: [], langKeys: [], classes: [] };

/* ---------- 1. Blade components used vs defined ---------- */
const defined = new Set(
  walk('resources/views/components', '.blade.php').map((f) =>
    f.replace('resources/views/components/', '').replace('.blade.php', '').split('/').join('.')
  )
);
const usedComp = new Map();
for (const f of blades) {
  for (const m of read(f).matchAll(/<x-([a-z0-9][a-z0-9._-]*)/g)) {
    const n = m[1];
    if (n.startsWith('slot')) continue;
    if (!usedComp.has(n)) usedComp.set(n, new Set());
    usedComp.get(n).add(f);
  }
}
for (const [n, files] of usedComp) {
  if (defined.has(n)) continue;
  if (defined.has(n + '.index')) continue;
  problems.components.push(`${n}  <- ${[...files].slice(0, 3).join(', ')}`);
}

/* ---------- 2. route() names used vs declared ---------- */
const routeSrc = fs.existsSync('routes/web.php') ? read('routes/web.php') : '';
const declared = new Set([...routeSrc.matchAll(/->name\(\s*'([^']+)'/g)].map((m) => m[1]));
// group prefixes: ->name('admin.') on a group prefixes children
const groupPrefixes = [...routeSrc.matchAll(/name\(\s*'([a-z0-9_.]+\.)'\s*\)/g)].map((m) => m[1]);
const expanded = new Set(declared);
for (const p of groupPrefixes) for (const d of declared) expanded.add(p + d);

const usedRoutes = new Map();
for (const f of [...blades, ...phps]) {
  for (const m of read(f).matchAll(/(^|[^>$\w])route\(\s*'([a-zA-Z0-9_.-]+)'/g)) {
    if (!usedRoutes.has(m[2])) usedRoutes.set(m[2], new Set());
    usedRoutes.get(m[2]).add(f);
  }
}
for (const [n, files] of usedRoutes) {
  if (expanded.has(n) || declared.has(n)) continue;
  // a name may be declared with its prefix already inline
  if ([...declared].some((d) => d === n || d.endsWith('.' + n))) continue;
  problems.routes.push(`${n}  <- ${[...files].slice(0, 2).join(', ')}`);
}

/* ---------- 3. lang keys used vs present in lang/ar ---------- */
const langFiles = walk('lang/ar', '.php');
const langKeys = new Set();
for (const f of langFiles) {
  const file = path.basename(f, '.php');
  const src = read(f);
  // collect quoted array keys at any depth, building dotted paths by brace tracking
  const stack = [];
  const re = /(['"])([A-Za-z0-9_.:-]+)\1\s*=>\s*(\[)?/g;
  let m;
  let lastIndex = 0;
  const depthAt = (idx) => {
    let d = 0;
    for (let i = 0; i < idx; i++) {
      const c = src[i];
      if (c === '[') d++;
      else if (c === ']') d--;
    }
    return d;
  };
  // simpler: record every key with its depth, then rebuild paths
  const entries = [];
  while ((m = re.exec(src))) entries.push({ key: m[2], opens: !!m[3], idx: m.index });
  let curDepth = 0;
  const pathStack = [];
  for (const e of entries) {
    const d = depthAt(e.idx);
    while (pathStack.length > d - 1) pathStack.pop();
    const full = [file, ...pathStack, e.key].join('.');
    langKeys.add(full);
    if (e.opens) pathStack.push(e.key);
  }
}
const usedKeys = new Map();
for (const f of [...blades, ...phps]) {
  for (const m of read(f).matchAll(/\b(?:__|trans_choice|@lang)\(\s*'([a-z][a-zA-Z0-9_.-]*\.[a-zA-Z0-9_.-]+)'\s*([.,)])/g)) {
    if (m[2] === '.') continue;                       // key is concatenated at runtime
    if (m[1].endsWith('.')) continue;
    if (!usedKeys.has(m[1])) usedKeys.set(m[1], new Set());
    usedKeys.get(m[1]).add(f);
  }
}
for (const [k, files] of usedKeys) {
  if (langKeys.has(k)) continue;
  // validation.* and pagination.* have framework fallbacks
  if (/^(validation|pagination|passwords|auth)\./.test(k) && langKeys.has(k.split('.').slice(0, 2).join('.'))) continue;
  problems.langKeys.push(`${k}  <- ${[...files].slice(0, 2).join(', ')}`);
}

/* ---------- 4. App\ classes referenced vs files ---------- */
const classFileExists = (fqcn) => {
  const rel = fqcn.replace(/^App\\/, 'app/').split('\\').join('/') + '.php';
  return fs.existsSync(rel);
};
const usedClasses = new Map();
for (const f of phps) {
  for (const m of read(f).matchAll(/^use\s+(App\\[A-Za-z0-9_\\]+)\s*;/gm)) {
    if (!usedClasses.has(m[1])) usedClasses.set(m[1], new Set());
    usedClasses.get(m[1]).add(f);
  }
}
for (const [c, files] of usedClasses) {
  if (classFileExists(c)) continue;
  problems.classes.push(`${c}  <- ${[...files].slice(0, 2).join(', ')}`);
}

/* ---------- report ---------- */
const section = (title, list) => {
  console.log(`\n=== ${title}: ${list.length} ===`);
  for (const l of list.slice(0, 25)) console.log('  ' + l);
  if (list.length > 25) console.log(`  … and ${list.length - 25} more`);
};
console.log(`scanned ${blades.length} blade files, ${phps.length} php files, ${langKeys.size} lang keys`);
section('Missing Blade components', problems.components);
section('Undefined route names', problems.routes);
section('Missing lang keys', problems.langKeys);
section('Missing App\\ classes', problems.classes);

const total = Object.values(problems).reduce((a, b) => a + b.length, 0);
console.log(`\nTOTAL PROBLEMS: ${total}`);
