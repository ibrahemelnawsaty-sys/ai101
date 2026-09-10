/**
 * Measures the select dropdown on the REAL /admin/users/create page, rendered
 * through the whole dashboard shell.
 *
 * The earlier probe put one card on a bare document and answered "is a card's
 * overflow clipping the panel". That is a different question from the one the
 * owner is asking, which is "on this page, does the list show". A sidebar, a
 * sticky header, a stacking context or a transformed ancestor can each change
 * the answer, and none of them existed in the isolated probe.
 */
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve('C:/Users/b.maher/Downloads/wesal/LARAVEL/public');
const TYPES = {
  '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript',
  '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png',
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
const url = `http://127.0.0.1:${server.address().port}/create-probe.html`;

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 } });
const tab = await ctx.newPage();

const errors = [];
tab.on('pageerror', (e) => errors.push(String(e)));
tab.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });

await tab.goto(url, { waitUntil: 'load' });
await tab.waitForTimeout(600);

console.log('Alpine running     :', await tab.evaluate(() => Boolean(window.Alpine)) ? 'yes' : 'NO');
console.log('selects on page    :', await tab.locator('.ui-select__button').count());
console.log('open before click  :', await tab.evaluate(() =>
  [...document.querySelectorAll('.ui-select__panel')].filter((p) => getComputedStyle(p).display !== 'none').length));

// Every ancestor property that would make something a containing block for a
// fixed element — and therefore able to clip it again.
const traps = await tab.evaluate(() => {
  const field = document.querySelector('[name="gender"], #s-gender');
  const start = field ? field.closest('.ui-select') || field : document.body;
  const found = [];
  for (let el = start; el && el !== document.documentElement; el = el.parentElement) {
    const s = getComputedStyle(el);
    const why = [];
    if (s.transform !== 'none') why.push('transform');
    if (s.filter !== 'none') why.push('filter');
    if (s.backdropFilter && s.backdropFilter !== 'none') why.push('backdrop-filter');
    if (s.perspective !== 'none') why.push('perspective');
    if (s.willChange && !['auto', ''].includes(s.willChange)) why.push('will-change:' + s.willChange);
    if (s.contain && !['none', ''].includes(s.contain)) why.push('contain:' + s.contain);
    if (why.length) found.push({ el: el.className || el.tagName, why: why.join(' ') });
  }
  return found;
});
console.log('fixed-position traps:', traps.length ? JSON.stringify(traps) : 'none');

// Open the gender list — the one in the owner's screenshot.
const gender = tab.locator('.ui-select').filter({ has: tab.locator('[name="gender"]') }).locator('.ui-select__button');
await gender.scrollIntoViewIfNeeded();
await gender.click();
await tab.waitForTimeout(400);

const result = await tab.evaluate(() => {
  const panels = [...document.querySelectorAll('.ui-select__panel')]
    .filter((p) => getComputedStyle(p).display !== 'none');
  if (panels.length !== 1) return { error: `${panels.length} panels open` };

  const panel = panels[0];
  const s = getComputedStyle(panel);
  const box = panel.getBoundingClientRect();

  let clipper = null;
  for (let el = panel.parentElement; el; el = el.parentElement) {
    const cs = getComputedStyle(el);
    if (['hidden', 'clip', 'auto', 'scroll'].includes(cs.overflow) ||
        ['hidden', 'clip', 'auto', 'scroll'].includes(cs.overflowY)) { clipper = el; break; }
  }
  const clip = clipper ? clipper.getBoundingClientRect() : null;

  const options = [...panel.querySelectorAll('.ui-select__option')].map((o) => {
    const r = o.getBoundingClientRect();
    const hit = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);
    return { label: (o.textContent || '').trim().slice(0, 16), reachable: hit !== null && (hit === o || o.contains(hit)) };
  });

  return {
    position: s.position,
    zIndex: s.zIndex,
    panel: { top: Math.round(box.top), bottom: Math.round(box.bottom), left: Math.round(box.left), width: Math.round(box.width), height: Math.round(box.height) },
    clipper: clipper ? { cls: String(clipper.className).split(' ')[0], bottom: Math.round(clip.bottom) } : null,
    clippedByAncestor: clip ? box.bottom > clip.bottom + 1 : false,
    clippedByViewport: box.bottom > window.innerHeight + 1,
    options,
  };
});

console.log(JSON.stringify(result, null, 2));
console.log(errors.length ? '\nBROWSER ERRORS:\n  ' + errors.join('\n  ') : '\nbrowser errors     : none');

const bad = (result.options || []).filter((o) => !o.reachable);

// `clippedByAncestor` compares RECTANGLES, and a rectangle that extends past the
// card is precisely what the fix produces — a fixed element is not clipped by an
// ancestor's overflow unless something makes that ancestor a containing block for
// it. Reading the overlap as a failure reported a working fix as broken while
// every option was demonstrably clickable, and a false alarm costs more than no
// alarm: it sends the next reader looking for a defect that is not there.
const trapped = result.position !== 'fixed' && result.clippedByAncestor;

console.log('\nVERDICT            :',
  result.error ? 'FAILED — ' + result.error
    : bad.length === 0 && !trapped && !result.clippedByViewport
      ? 'the list shows in full and every option is clickable'
      : 'STILL BROKEN — ' + (bad.length ? 'unreachable: ' + bad.map((o) => o.label).join(', ') : 'clipped by ' + (result.clipper || {}).cls));

await tab.screenshot({ path: 'C:/Users/b.maher/Downloads/wesal/LARAVEL/public/create-probe.png', fullPage: false });
await browser.close();
server.close();
