/**
 * Loads the four pages render-alpine-pages.php wrote, in Chromium, against the
 * BUILT bundle in public/build, and reports what a person would see (D-67).
 *
 * Run: php tools/offline-checks/render-alpine-pages.php
 *      node tools/offline-checks/measure-alpine-pages.mjs
 */
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve('C:/Users/b.maher/Downloads/wesal/LARAVEL/public');
const TYPES = {
  '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript',
  '.woff2': 'font/woff2', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json',
};

const server = http.createServer((req, res) => {
  // The roster's poll endpoint, answered with what the real endpoint returned
  // once a participant had checked in (render-alpine-pages.php writes it).
  if (/^\/trainer\/attendance\/[^/]+\/poll/.test(req.url)) {
    res.writeHead(200, { 'content-type': 'application/json' });
    res.end(fs.readFileSync(path.join(ROOT, 'roster-probe.json')));
    return;
  }
  const file = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
  fs.readFile(file, (err, body) => {
    if (err) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' });
    res.end(body);
  });
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

const browser = await chromium.launch();

async function open(name) {
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const tab = await ctx.newPage();
  const errors = [];
  tab.on('pageerror', (e) => errors.push(String(e).split('\n')[0]));
  tab.on('console', (m) => {
    // A 404 for a font or an avatar is the static server, not the page.
    if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) errors.push(m.text());
  });
  await tab.goto(`${base}/${name}`, { waitUntil: 'load' });
  await tab.waitForTimeout(700);
  return { tab, errors, ctx };
}

let failures = 0;
const check = (label, ok, detail = '') => {
  if (!ok) failures += 1;
  console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${label}${detail ? ' — ' + detail : ''}`);
};

// 1 · registration
{
  const { tab, errors, ctx } = await open('register-probe.html');
  console.log('register');
  const panes = await tab.evaluate(() => [...document.querySelectorAll('.wiz__pane')]
    .map((p) => getComputedStyle(p).display !== 'none'));
  check('one wizard pane visible', panes.filter(Boolean).length === 1, JSON.stringify(panes));
  const inputs = await tab.evaluate(() => [...document.querySelectorAll('.wiz__pane input')]
    .filter((i) => i.type !== 'hidden' && getComputedStyle(i).display !== 'none' && i.getClientRects().length > 0).length);
  check('fields visible in that pane', inputs > 0, `${inputs} inputs`);
  check('no script errors', errors.length === 0, errors.join(' | '));
  await ctx.close();
}

// 2 · reset
{
  const { tab, errors, ctx } = await open('reset-probe.html');
  console.log('reset-password');
  check('form rendered', await tab.locator('form[x-data^="passwordStrength"]').count() === 1);
  const label = await tab.evaluate(() => [...document.querySelectorAll('.form__submit [x-show]')]
    .filter((s) => getComputedStyle(s).display !== 'none')
    .map((s) => s.textContent.trim()).join(' / '));
  check('submit button has a visible label', label !== '', label);
  await tab.fill('input[name="password"]', 'Abcdefg1!');
  await tab.waitForTimeout(200);
  const meter = await tab.evaluate(() => ({
    level: document.querySelector('.pw__meter')?.getAttribute('data-level'),
    label: document.querySelector('.pw__label b')?.textContent.trim(),
    ok: document.querySelectorAll('.pw__rules li.is-ok').length,
  }));
  check('typing scores the password', Number(meter.level) > 0 && meter.label !== '' && meter.ok === 5, JSON.stringify(meter));
  check('no script errors', errors.length === 0, errors.join(' | '));
  await ctx.close();
}

// 3 · schedule
{
  const { tab, errors, ctx } = await open('schedule-probe.html');
  console.log('schedule');
  const state = async () => tab.evaluate(() => {
    const shows = [...document.querySelectorAll('[x-show]')].filter((el) => /view ===/.test(el.getAttribute('x-show')));
    return shows.map((el) => ({ expr: el.getAttribute('x-show'), shown: getComputedStyle(el).display !== 'none' }));
  });
  const before = await state();
  check('exactly one of the two views is showing', before.filter((s) => s.shown).length === 1, JSON.stringify(before));
  await tab.getByRole('tab').nth(1).click();
  await tab.waitForTimeout(250);
  const after = await state();
  const cal = after.find((s) => s.expr.includes('calendar'));
  check('the toggle switches to the calendar', !!cal && cal.shown, JSON.stringify(after));
  const remembered = await tab.evaluate(() => window.localStorage.getItem('athar.schedule.view'));
  check('the choice is remembered', remembered === 'calendar', String(remembered));
  check('no script errors', errors.length === 0, errors.join(' | '));
  await ctx.close();
}

// 4 · messages
{
  const { tab, errors, ctx } = await open('messages-probe.html');
  console.log('messages');
  const thread = await tab.evaluate(() => {
    const root = document.querySelector('.chat');
    const stream = document.querySelector('[x-ref="stream"]');
    return {
      component: !!root && !!root._x_dataStack,
      latest: stream ? stream.getAttribute('data-latest') : null,
      bubbles: document.querySelectorAll('.chat__msgs .msg').length,
    };
  });
  check('atharThread is running on the conversation', thread.component, JSON.stringify(thread));
  check('no script errors', errors.length === 0, errors.join(' | '));
  await ctx.close();
}

// 5 · the trainer's live roster
{
  const { tab, errors, ctx } = await open('roster-probe.html');
  console.log('trainer roster (live)');
  const id = fs.readFileSync(path.join(ROOT, 'roster-probe.meta'), 'utf8').trim();
  const row = `tr[data-participant="${id}"]`;
  const read = () => tab.evaluate((sel) => {
    const r = document.querySelector(sel);
    return {
      inCell: r?.querySelector('[data-cell="in"]')?.textContent.trim(),
      status: r?.querySelector('[data-cell="status"]')?.textContent.trim(),
      edit: !!r?.querySelector('[data-cell="edit"] a'),
      present: document.querySelector('[data-cell="present"]')?.textContent.trim(),
    };
  }, row);

  const before = await read();
  // Tick a DIFFERENT row's checkbox, as a trainer mid-way through bulk marking.
  const other = await tab.evaluate((sel) => {
    const box = [...document.querySelectorAll('tr[data-participant] input[type="checkbox"]')]
      .find((b) => !b.closest(sel));
    if (box) { box.checked = true; return box.value; }
    return null;
  }, row);

  await tab.waitForTimeout(2600);
  const after = await read();
  const stillTicked = await tab.evaluate((v) => !!document.querySelector(`input[type="checkbox"][value="${v}"]`)?.checked, other);

  check('before the poll: no check-in shown', before.inCell === '—' && !before.edit, JSON.stringify(before));
  check('after the poll: the check-in time appears', after.inCell && after.inCell !== '—', JSON.stringify(after));
  check('after the poll: the status and the edit button update', after.status !== before.status && after.edit);
  check('after the poll: the present count moves', Number(after.present) === Number(before.present) + 1, `${before.present} -> ${after.present}`);
  check('a ticked checkbox elsewhere survives the refresh', other !== null && stillTicked);
  check('no script errors', errors.length === 0, errors.join(' | '));
  await ctx.close();
}

await browser.close();
server.close();
console.log(failures === 0 ? '\nALL CHECKS PASS' : `\n${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
