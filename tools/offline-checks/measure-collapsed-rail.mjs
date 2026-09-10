/**
 * Collapses the sidebar and asks whether the rail is still usable.
 *
 * The collapse button is the ONLY way back out of the collapsed state, so a rail
 * that pushes it outside its own 72px is a one-way door: the reader collapses
 * the navigation once and cannot restore it. That is what happened — the rule
 * meant to hide the wordmark targeted `.side__brand .logo`, and `x-ui.logo`
 * renders `ui-logo`, so it matched nothing and the wordmark stayed at full width.
 *
 * Every answer here is measured, not read: the rail's own rectangle, the button's
 * rectangle against it, and `elementFromPoint` at the button's centre — a button
 * that is inside the rail but painted under something else is still unusable.
 *
 * Run: node tools/offline-checks/measure-collapsed-rail.mjs
 * It needs the page written by tools/offline-checks/render-create-page.php.
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

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const tab = await ctx.newPage();

const errors = [];
tab.on('pageerror', (e) => errors.push(String(e)));

await tab.goto(`http://127.0.0.1:${server.address().port}/create-probe.html`, { waitUntil: 'load' });
await tab.waitForTimeout(500);

const read = () => tab.evaluate(() => {
  const rail = document.querySelector('.side');
  const button = document.querySelector('.side:not(.drawer__panel) .side__collapse');
  const wordmark = document.querySelector('.side__wordmark');
  const mark = document.querySelector('.side__mark');
  if (!rail || !button) return { error: 'no rail or no collapse button' };

  const r = rail.getBoundingClientRect();
  const b = button.getBoundingClientRect();
  const hit = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2);

  const shown = (el) => Boolean(el) && getComputedStyle(el).display !== 'none';

  return {
    collapsed: document.querySelector('.shell')?.dataset.collapsed ?? '(unset)',
    rail: { left: Math.round(r.left), right: Math.round(r.right), width: Math.round(r.width) },
    button: { left: Math.round(b.left), right: Math.round(b.right), width: Math.round(b.width), height: Math.round(b.height) },
    buttonInsideRail: b.left >= r.left - 1 && b.right <= r.right + 1,
    buttonClickable: hit !== null && (hit === button || button.contains(hit)),
    wordmarkShown: shown(wordmark),
    markShown: shown(mark),
  };
});

// The burger opens the mobile drawer, and below 1024px that drawer is the only
// navigation on the platform. Above it, the rail carries the same links and the
// burger is redundant — it was showing anyway, because `.icb` declares
// `display: grid` later in the bundle at equal specificity.
const burger = await tab.evaluate(() => {
  const el = document.querySelector('.appbar__burger');
  return el ? { present: true, display: getComputedStyle(el).display } : { present: false };
});
console.log('burger at 1440px:', JSON.stringify(burger),
  burger.display === 'none' ? '(correctly hidden)' : '*** VISIBLE ON DESKTOP ***');

console.log('--- open ---');
console.log(JSON.stringify(await read(), null, 2));

await tab.locator('.side:not(.drawer__panel) .side__collapse').click();
await tab.waitForTimeout(450);

const collapsed = await read();
console.log('\n--- collapsed ---');
console.log(JSON.stringify(collapsed, null, 2));

console.log(errors.length ? '\nBROWSER ERRORS:\n  ' + errors.join('\n  ') : '\nbrowser errors: none');

const ok = collapsed.buttonInsideRail
  && collapsed.buttonClickable
  && collapsed.markShown
  && !collapsed.wordmarkShown
  // A 44px target is the platform's own floor (Article 18).
  && collapsed.button.width >= 44 && collapsed.button.height >= 44;

console.log('\nVERDICT       :', collapsed.error ? 'FAILED — ' + collapsed.error
  : ok ? 'the rail collapses, the mark replaces the wordmark, and the way back is clickable'
    : 'STILL BROKEN — ' + JSON.stringify({
        insideRail: collapsed.buttonInsideRail,
        clickable: collapsed.buttonClickable,
        markShown: collapsed.markShown,
        wordmarkShown: collapsed.wordmarkShown,
      }));

await tab.screenshot({ path: 'C:/Users/b.maher/Downloads/wesal/LARAVEL/public/create-probe.png' });
await browser.close();
server.close();
