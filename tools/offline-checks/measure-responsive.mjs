/**
 * Opens every rendered screen at eight widths and MEASURES what a phone gets.
 *
 * WHY MEASURE AND NOT READ THE CSS
 * Every responsive failure this project has had was invisible in the source: a
 * grid whose columns were fine until a 14-character serial number sat in one, a
 * table with no scroll container, a rail that ate half a 360px screen, a
 * dropdown clipped by an ancestor's overflow. None of it shows in a stylesheet.
 * Only a browser at that width answers.
 *
 * What it reports, per screen and width:
 *   · horizontal overflow of the document, and the elements that cause it;
 *   · interactive targets smaller than 44x44 (PRD §13, Article 18);
 *   · text below 12px;
 *   · any computed font-family that is not the platform's one family;
 *   · page errors thrown while the bundle runs.
 *
 * Run: php tools/offline-checks/render-all-screens.php   (writes public/sweep)
 *      npm run build                                     (the CSS it loads)
 *      node tools/offline-checks/measure-responsive.mjs
 *
 * Exit code 1 when anything in the BLOCKING set is found, so it can gate a
 * commit. Pass --json for the full machine-readable report.
 *
 * @see PRD §13 · CONSTITUTION art. 16, 18 · D-86
 */
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve('C:/Users/b.maher/Downloads/wesal/LARAVEL/public');
const SWEEP = path.join(ROOT, 'sweep');
const WIDTHS = [320, 360, 390, 414, 768, 1024, 1280, 1440];
const FONT = 'IBM Plex Sans Arabic';

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript',
  '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon',
  '.json': 'application/json', '.map': 'application/json',
};

const server = http.createServer((req, res) => {
  const file = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
  fs.readFile(file, (err, body) => {
    if (err) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' });
    res.end(body);
  });
});

await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

const pages = fs.readdirSync(SWEEP).filter((f) => f.endsWith('.html')).sort();

if (pages.length === 0) {
  console.error('no rendered screens: run php tools/offline-checks/render-all-screens.php first');
  process.exit(2);
}

const browser = await chromium.launch();
const report = [];

/** Runs inside the page: everything measured in one pass. */
const MEASURE = (family) => {
  const vw = document.documentElement.clientWidth;
  const visible = (el) => {
    const s = getComputedStyle(el);
    if (s.display === 'none' || s.visibility === 'hidden' || s.opacity === '0') return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };

  // 1 · horizontal overflow — but only what a READER actually suffers.
  //
  // A wide table inside its own `overflow-x: auto` box is not a defect: it is
  // the fix. In RTL such a table's rectangle starts at a NEGATIVE left, which
  // looks exactly like overflow to a naive rule and is not. So every element
  // is judged against its nearest scrolling ancestor, and only two things are
  // reported: the page itself scrolling sideways, and content clipped out of
  // reach with nothing to scroll it back.
  const scroller = document.scrollingElement || document.documentElement;
  const htmlOverflow = getComputedStyle(document.documentElement).overflowX;
  const bodyOverflow = getComputedStyle(document.body).overflowX;
  const pageClips = htmlOverflow === 'hidden' || htmlOverflow === 'clip'
    || bodyOverflow === 'hidden' || bodyOverflow === 'clip';
  const pageScroll = Math.round(scroller.scrollWidth - scroller.clientWidth);
  const docOverflow = pageClips ? 0 : Math.max(0, pageScroll);

  /**
   * The nearest ancestor that does not let this element spill past it: a
   * scroller (the reader can reach what is inside) or a clipper (they cannot).
   * html and body are the page itself and are judged separately.
   */
  const boxOf = (el) => {
    for (let p = el.parentElement; p && p !== document.body && p !== document.documentElement; p = p.parentElement) {
      const o = getComputedStyle(p).overflowX;
      if (o !== 'visible') return { p, o };
    }
    return null;
  };

  const culprits = [];
  const clipped = [];

  for (const el of document.querySelectorAll('body *')) {
    // Inside an SVG every node is clipped by the svg on purpose; the svg
    // itself is measured. Screen-reader-only text is off-screen by design.
    if (el.closest('svg') && el.tagName.toLowerCase() !== 'svg') continue;
    if (el.closest('.sr, .ui-sr')) continue;
    // The landing's ticker is a marquee: a track far wider than the screen,
    // clipped on purpose and moved by animation. It is decoration, and the
    // same words are in the page's own sections.
    if (el.closest('.tick__track')) continue;
    // Soft glows behind the landing hero bleed past its edges on purpose;
    // they are aria-hidden paint, not content.
    if (el.closest('.hero__glow')) continue;
    if (!visible(el)) continue;

    const s = getComputedStyle(el);
    if (s.position === 'fixed') continue; // judged with the overlays below

    const r = el.getBoundingClientRect();
    const box = boxOf(el);

    const label = (over) => ({
      selector: el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : ''),
      over: Math.round(over),
      width: Math.round(r.width),
      overflowX: s.overflowX,
      text: (el.textContent || '').trim().slice(0, 40),
    });

    if (box && (box.o === 'auto' || box.o === 'scroll')) continue; // reachable

    if (box) {
      // Clipped by a local box: a defect only if it pokes out of THAT box.
      const b = box.p.getBoundingClientRect();
      const over = Math.max(r.right - b.right, b.left - r.left);
      if (over > 1) clipped.push(label(over));
      continue;
    }

    const over = Math.max(r.right - vw, -r.left);
    if (over <= 1) continue;
    if (docOverflow > 1) culprits.push(label(over));
    else clipped.push(label(over));
  }

  // Only the widest few, and only the deepest node of a chain that all share
  // one width, so the report names a cause and not a lineage.
  const trim = (list) => list
    .sort((a, b) => b.over - a.over)
    .filter((x, i, all) => all.findIndex((y) => y.over === x.over && y.width === x.width) === i)
    .slice(0, 6);

  // 2 · touch targets
  const small = [];
  for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, summary, [role=button], [role=tab], [role=menuitem]')) {
    if (!visible(el)) continue;
    // Visually hidden controls (the skip link until focused, a file input
    // whose label is the real target) are not what a finger aims at.
    if (el.closest('.sr, .ui-sr')) continue;
    const r = el.getBoundingClientRect();
    if (r.width < 44 || r.height < 44) {
      const s = getComputedStyle(el);
      // A link inside a paragraph of running text is not a control.
      const inProse = el.tagName === 'A' && el.parentElement && ['P', 'LI', 'DD', 'SPAN', 'SMALL'].includes(el.parentElement.tagName);
      small.push({
        selector: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
        w: Math.round(r.width), h: Math.round(r.height), inProse,
        label: (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 30),
        display: s.display,
      });
    }
  }

  // 3 · tiny text
  const tiny = [];
  for (const el of document.querySelectorAll('body *')) {
    if (!visible(el)) continue;
    if (!el.firstChild || el.firstChild.nodeType !== 3 || !el.firstChild.textContent.trim()) continue;
    if (el.closest('.sr, .ui-sr')) continue;
    const size = parseFloat(getComputedStyle(el).fontSize);
    if (size < 12) {
      tiny.push({ selector: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/)[0] : ''), size, text: el.textContent.trim().slice(0, 30) });
    }
  }

  // 4 · families actually in use
  const families = {};
  for (const el of document.querySelectorAll('body, h1, h2, h3, h4, p, a, button, input, label, td, th, li, span, code, .logo, svg text')) {
    if (!visible(el)) continue;
    const f = getComputedStyle(el).fontFamily;
    families[f] = (families[f] || 0) + 1;
  }

  // 5 · fixed overlays that swallow a narrow screen
  const overlays = [];
  for (const el of document.querySelectorAll('body *')) {
    if (!visible(el)) continue;
    const s = getComputedStyle(el);
    if (s.position !== 'fixed') continue;
    const r = el.getBoundingClientRect();
    if (r.width > vw * 0.6 && r.height > window.innerHeight * 0.6) {
      overlays.push({ selector: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''), w: Math.round(r.width), h: Math.round(r.height) });
    }
  }

  return { docOverflow: Math.round(docOverflow), culprits: trim(culprits), clipped: trim(clipped), small, tiny: tiny.slice(0, 10), families, overlays };
};

// Phones and tablets are measured as phones and tablets: touch, a coarse
// pointer, a mobile viewport. `(pointer: coarse)` is what lifts the small
// buttons to 44px, and a desktop context would never see it.
const PROFILES = [
  { touch: true, widths: WIDTHS.filter((w) => w < 1024) },
  { touch: false, widths: WIDTHS.filter((w) => w >= 1024) },
];

for (const file of pages) {
  const name = file.replace(/\.html$/, '');
  const errors = [];
  const widths = {};
  let failed = null;

  for (const profile of PROFILES) {
    const ctx = await browser.newContext({
      viewport: { width: profile.widths[0], height: 900 },
      deviceScaleFactor: 1,
      isMobile: profile.touch,
      hasTouch: profile.touch,
    });
    const tab = await ctx.newPage();
    tab.on('pageerror', (e) => errors.push(String(e).slice(0, 160)));

    try {
      await tab.goto(`${base}/sweep/${file}`, { waitUntil: 'load', timeout: 20000 });
      await tab.waitForTimeout(220);
    } catch (e) {
      failed = String(e).slice(0, 160);
      await ctx.close();
      break;
    }

    for (const width of profile.widths) {
      await tab.setViewportSize({ width, height: 900 });
      await tab.waitForTimeout(90);
      widths[width] = await tab.evaluate(MEASURE, FONT);
    }

    await ctx.close();
  }

  report.push(failed ? { name, error: failed } : { name, errors: [...new Set(errors)], widths });
}

await browser.close();
server.close();

// ----------------------------------------------------------------- verdict

const blocking = [];
const warnings = [];
const familySet = new Map();

for (const page of report) {
  if (page.error) { blocking.push(`${page.name}: failed to load — ${page.error}`); continue; }
  for (const e of page.errors) blocking.push(`${page.name}: page error — ${e}`);

  for (const [width, m] of Object.entries(page.widths)) {
    if (m.docOverflow > 1) {
      const who = m.culprits.map((c) => `${c.selector}(+${c.over}px)`).join(' · ') || 'unattributed';
      blocking.push(`${page.name} @${width}: page scrolls ${m.docOverflow}px sideways — ${who}`);
    }
    if (m.clipped.length > 0) {
      const who = m.clipped.map((c) => `${c.selector}(+${c.over}px "${c.text.slice(0, 18)}")`).join(' · ');
      blocking.push(`${page.name} @${width}: content cut off, nothing scrolls it — ${who}`);
    }
    for (const t of m.small.filter((s) => !s.inProse)) {
      warnings.push(`${page.name} @${width}: target ${t.w}x${t.h} "${t.label}" (${t.selector})`);
    }
    for (const t of m.tiny) warnings.push(`${page.name} @${width}: ${t.size}px text "${t.text}" (${t.selector})`);
    for (const o of m.overlays) warnings.push(`${page.name} @${width}: fixed overlay ${o.w}x${o.h} (${o.selector})`);
    for (const [f, n] of Object.entries(m.families)) familySet.set(f, (familySet.get(f) || 0) + n);
  }
}

const foreignFamilies = [...familySet.entries()].filter(([f]) => !f.includes(FONT));

console.log(`screens: ${report.length} · widths: ${WIDTHS.join(', ')}`);
console.log(`\nFONT FAMILIES IN USE`);
for (const [f, n] of [...familySet.entries()].sort((a, b) => b[1] - a[1])) {
  console.log(`  ${String(n).padStart(6)}  ${f}`);
}

const uniq = (list) => [...new Set(list)];
const blockingU = uniq(blocking);
const warningsU = uniq(warnings);

console.log(`\nBLOCKING (${blockingU.length})`);
for (const b of blockingU.slice(0, 60)) console.log('  ' + b);
if (blockingU.length > 60) console.log(`  … and ${blockingU.length - 60} more`);

console.log(`\nWARNINGS (${warningsU.length})`);
for (const w of warningsU.slice(0, 60)) console.log('  ' + w);
if (warningsU.length > 60) console.log(`  … and ${warningsU.length - 60} more`);

if (process.argv.includes('--json')) {
  fs.writeFileSync(path.join(SWEEP, 'responsive-report.json'), JSON.stringify(report, null, 2));
  console.log(`\nfull report: ${path.join(SWEEP, 'responsive-report.json')}`);
}

if (foreignFamilies.length > 0) {
  console.log(`\nNOTE: ${foreignFamilies.length} family stack(s) in use do not name "${FONT}".`);
}

process.exit(blockingU.length > 0 ? 1 : 0);
