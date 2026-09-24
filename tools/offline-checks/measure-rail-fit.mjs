/**
 * Opens every rendered screen that has the rail, at the screen heights laptops
 * actually have, open and collapsed, and MEASURES whether the rail still holds
 * together (D-115).
 *
 * WHAT BROKE
 * The rail's link list was allowed to shrink (`flex: 1; min-block-size: 0`)
 * and was never told to scroll. Wherever the links outgrew the space the
 * brand, the identity card, the cohort box and the sign-out row left them,
 * they painted straight over those blocks — a trainer's rail below 768px of
 * screen height, a trainee's or an administrator's below 900px: most laptops.
 * No stylesheet line was wrong on its own and the rail itself never
 * overflowed, so neither a PHP test nor measure-responsive.mjs (which looks
 * for sideways overflow) could see it. Only a browser at that HEIGHT answers.
 *
 * What it asserts, per screen × height × open/collapsed:
 *   · the rail's direct children are exactly brand, list, account, in order;
 *   · the three regions do not overlap one another;
 *   · no link paints outside the list's own box — a link scrolled out of
 *     sight is clipped, which is what scrolling is, not a defect;
 *   · every link, once scrolled to, is the element under its own centre, and
 *     is at least 44×44 (Article 18);
 *   · every control in the account region is the element under its centre;
 *   · the account region is entirely on screen unless the rail scrolls, and
 *     the rail scrolls only once the list is down to its --side-nav-min floor;
 *   · the link marked as the current page is in view when the page opens;
 *   · every focus ring in the rail is whole — none cut by the list's clip;
 *   · the app footer keeps the page's side gutter.
 *
 * Run: php tools/offline-checks/render-all-screens.php   (writes public/sweep)
 *      npm run build                                     (the CSS it loads)
 *      node tools/offline-checks/measure-rail-fit.mjs
 *
 * Exit code 1 on any failure, 2 when there is nothing to measure. Pass --json
 * for the full machine-readable report. Screen names narrow the sweep to the
 * screens whose file starts with them: `… measure-rail-fit.mjs t-dashboard a-`.
 *
 * @see PRD §9.5.1 · CONSTITUTION art. 16, 18 · D-86, D-108, D-115
 */
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../public');
const SWEEP = path.join(ROOT, 'sweep');

// The rail exists from 1024px up; its layout does not depend on the width
// beyond that, so the sweep walks the HEIGHT. 480 is below the list's floor
// for the tallest rail (the one with the cohort switcher), so the one-column
// fallback is exercised too.
const WIDTH = 1366;
const HEIGHTS = [480, 560, 640, 720, 768, 900, 1080];
const TOUCH = 44;

const TYPES = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css', '.js': 'text/javascript',
  '.woff2': 'font/woff2', '.woff': 'font/woff', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon',
  '.json': 'application/json', '.map': 'application/json',
};

const server = http.createServer((req, res) => {
  const file = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
  if (!file.startsWith(ROOT)) { res.writeHead(403); res.end(); return; }
  fs.readFile(file, (err, body) => {
    if (err) { res.writeHead(404); res.end(); return; }
    res.writeHead(200, { 'content-type': TYPES[path.extname(file)] || 'application/octet-stream' });
    res.end(body);
  });
});

const only = process.argv.slice(2).filter((arg) => !arg.startsWith('--'));

const pages = fs.existsSync(SWEEP)
  ? fs.readdirSync(SWEEP)
    .filter((f) => f.endsWith('.html'))
    .filter((f) => only.length === 0 || only.some((prefix) => f.startsWith(prefix)))
    .filter((f) => fs.readFileSync(path.join(SWEEP, f), 'utf8').includes('class="side"'))
    .sort()
  : [];

if (pages.length === 0) {
  console.error('no rendered screens with a rail: run php tools/offline-checks/render-all-screens.php first');
  process.exit(2);
}

await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}`;

/**
 * Runs inside the page. `phase` is 'load' for the first look at a freshly
 * opened screen (the only moment the current-page link is judged), and
 * 'settled' after the collapse button has been pressed.
 */
const MEASURE = ({ touch, phase }) => {
  const failures = [];
  const fail = (m) => failures.push(m);
  const rail = document.querySelector('aside.side');
  if (!rail || getComputedStyle(rail).display === 'none') return { skipped: 'no rail at this width', failures };

  const R = (el) => el.getBoundingClientRect();
  const name = (el) => (el.getAttribute('aria-label') || el.textContent || el.tagName).trim().replace(/\s+/g, ' ').slice(0, 32);
  const shown = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
  const owns = (el, x, y) => { const hit = document.elementFromPoint(x, y); return Boolean(hit) && (hit === el || el.contains(hit)); };
  const centre = (el) => { const b = R(el); return [b.left + b.width / 2, b.top + b.height / 2]; };

  // 1 · three regions, in order, and nothing else.
  const kids = [...rail.children].map((el) => [...el.classList].find((c) => c.startsWith('side__')) || el.tagName.toLowerCase());
  if (kids.join(' ') !== 'side__brand side__nav side__account') fail(`rail children are [${kids.join(', ')}]`);
  const brand = rail.querySelector(':scope > .side__brand');
  const list = rail.querySelector(':scope > .side__nav');
  if (!brand || !list) return { failures };
  // Without the account wrapper (the markup before D-115), everything after
  // the list is measured as one region, so the geometry below still judges
  // the rail a reader actually sees rather than stopping at its markup.
  const tail = [...rail.children].slice([...rail.children].indexOf(list) + 1);
  const accountParts = rail.querySelector(':scope > .side__account') ? [rail.querySelector(':scope > .side__account')] : tail;
  if (accountParts.length === 0) return { failures };
  const account = {
    rect: () => {
      const boxes = accountParts.filter(shown).map((el) => el.getBoundingClientRect());
      return { top: Math.min(...boxes.map((x) => x.top)), bottom: Math.max(...boxes.map((x) => x.bottom)) };
    },
    controls: () => accountParts.flatMap((el) => [...el.querySelectorAll('button, select, a[href]')]),
    logout: () => accountParts.map((el) => (el.matches('.side__logout') ? el : el.querySelector('.side__logout'))).find(Boolean)?.querySelector('.side__b') ?? null,
  };

  // 2 · the current-page link is in view as the page opens.
  const current = list.querySelector('[aria-current="page"]');
  if (phase === 'load' && current) {
    const c = R(current), l = R(list);
    if (c.top < l.top - 0.5 || c.bottom > l.bottom + 0.5) fail(`current link "${name(current)}" opens scrolled out of view`);
  }

  rail.scrollTop = 0;
  list.scrollTop = 0;
  const b = R(brand), l = R(list), a = account.rect();
  const railScrolls = rail.scrollHeight > rail.clientHeight + 1;
  const floor = parseFloat(getComputedStyle(list).minBlockSize) || 0;

  // 3 · the regions do not overlap.
  if (b.bottom > l.top + 0.5) fail(`brand (${Math.round(b.bottom)}) runs into the list (${Math.round(l.top)})`);
  if (l.bottom > a.top + 0.5) fail(`list (${Math.round(l.bottom)}) runs into the account region (${Math.round(a.top)})`);

  // 4 · no link paints outside the list. A list that clips shows each link
  //     only inside its own box; one that does not shows the whole link.
  const clips = ['auto', 'scroll', 'hidden', 'clip'].includes(getComputedStyle(list).overflowY);
  const links = [...list.querySelectorAll('.side__b')].filter(shown);
  for (const link of links) {
    const k = R(link);
    const top = clips ? Math.max(k.top, l.top) : k.top;
    const bottom = clips ? Math.min(k.bottom, l.bottom) : k.bottom;
    if (bottom - top <= 0.5) continue;
    if (top < l.top - 0.5 || bottom > l.bottom + 0.5) fail(`link "${name(link)}" paints outside the list`);
    for (const [label, box] of [['brand', b], ['account region', a]]) {
      if (bottom > box.top + 0.5 && top < box.bottom - 0.5) fail(`link "${name(link)}" paints over the ${label}`);
    }
  }

  // 5 · every link can be scrolled to, is then the thing under its centre,
  //     and is finger-sized.
  for (const link of links) {
    const k0 = R(link), l0 = R(list);
    list.scrollTop += k0.top - l0.top - (l0.height - k0.height) / 2;
    const k1 = R(link);
    if (k1.top < 0 || k1.bottom > innerHeight) rail.scrollTop += k1.top - (innerHeight - k1.height) / 2;
    const k = R(link);
    if (!owns(link, ...centre(link))) fail(`link "${name(link)}" cannot be reached`);
    if (k.width < touch - 0.5 || k.height < touch - 0.5) fail(`link "${name(link)}" is ${Math.round(k.width)}x${Math.round(k.height)}`);
    rail.scrollTop = 0;
  }
  list.scrollTop = 0;

  // 6 · the account region: on screen, or the rail scrolls — and the rail
  //     may scroll only once the list has nothing left to give.
  if (railScrolls) {
    if (l.height > floor + 1) fail(`rail scrolls while the list is ${Math.round(l.height)}px, above its ${floor}px floor`);
  } else if (a.top < -0.5 || a.bottom > innerHeight + 0.5) {
    fail(`account region ${Math.round(a.top)}-${Math.round(a.bottom)} is not on a ${innerHeight}px screen`);
  }
  for (const control of account.controls().filter(shown)) {
    const c0 = R(control);
    if (c0.bottom > innerHeight) rail.scrollTop += c0.bottom - innerHeight;
    if (!owns(control, ...centre(control))) fail(`account control "${name(control)}" cannot be reached`);
    rail.scrollTop = 0;
  }

  // 7 · the rail's own targets.
  for (const el of [brand.querySelector('.side__collapse'), account.logout()].filter(Boolean)) {
    const k = R(el);
    if (k.width < touch - 0.5 || k.height < touch - 0.5) fail(`"${name(el)}" is ${Math.round(k.width)}x${Math.round(k.height)}`);
  }

  // 8 · nothing in the list runs sideways.
  if (list.scrollWidth > list.clientWidth + 1) fail(`list scrolls sideways by ${list.scrollWidth - list.clientWidth}px`);

  // 8b · every focus ring is whole. Each target is focused as the keyboard
  //      would focus it (the caller pressed a key first, so script focus
  //      matches :focus-visible), the browser scrolls it where it scrolls
  //      it, and the ring (outline width + offset) must lie inside the
  //      nearest box that clips it: the list for a link, the rail for the
  //      rest. The list's first link used to lose its ring's top edge.
  let rings = 0;
  const clipOf = (el) => (list.contains(el) ? list : rail);
  const targets = [...links, brand.querySelector('.side__collapse'), ...account.controls()].filter((el) => el && shown(el));
  for (const el of targets) {
    el.focus();
    const s = getComputedStyle(el);
    if (s.outlineStyle === 'none') continue;
    rings++;
    const reach = parseFloat(s.outlineWidth) + Math.max(0, parseFloat(s.outlineOffset));
    const k = R(el), c = R(clipOf(el));
    if (k.top - reach < c.top - 0.5 || k.bottom + reach > c.bottom + 0.5
      || k.left - reach < c.left - 0.5 || k.right + reach > c.right + 0.5) {
      fail(`focus ring of "${name(el)}" is clipped (${Math.round(k.top - reach)}-${Math.round(k.bottom + reach)} in ${Math.round(c.top)}-${Math.round(c.bottom)})`);
    }
  }
  if (targets.length && rings === 0) fail('no focus ring could be measured — :focus-visible never applied');
  if (document.activeElement && document.activeElement.blur) document.activeElement.blur();
  list.scrollTop = 0;
  rail.scrollTop = 0;
  window.scrollTo(0, 0);

  // 9 · the footer keeps the page's side gutter.
  const foot = document.querySelector('.foot--app .foot__bot');
  const main = document.querySelector('.shell__main');
  if (foot && main) {
    const f = R(foot), m = R(main);
    if (f.right > m.right - 16 || f.left < m.left + 16) fail(`footer content ${Math.round(f.left)}-${Math.round(f.right)} touches its column ${Math.round(m.left)}-${Math.round(m.right)}`);
  }

  return {
    failures,
    list: { height: Math.round(l.height), needs: list.scrollHeight, scrolls: list.scrollHeight > list.clientHeight + 1 },
    railScrolls,
  };
};

const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: WIDTH, height: HEIGHTS[0] } });
// Every screen opens with the rail open; the collapse is pressed by hand below.
await context.addInitScript(() => { try { localStorage.removeItem('athar.sidebar.collapsed'); } catch (e) { /* none */ } });

const report = [];
let failed = 0;

for (const file of pages) {
  const tab = await context.newPage();
  const errors = [];
  tab.on('pageerror', (e) => errors.push(String(e)));

  for (const height of HEIGHTS) {
    await tab.setViewportSize({ width: WIDTH, height });
    await tab.goto(`${base}/sweep/${file}`, { waitUntil: 'load' });
    await tab.waitForTimeout(120);

    // A key press first, so the focus the rings are judged under is the
    // keyboard's (:focus-visible) and not a pointer's.
    await tab.keyboard.press('Shift');
    const open = await tab.evaluate(MEASURE, { touch: TOUCH, phase: 'load' });

    await tab.click('aside.side .side__collapse');
    await tab.waitForTimeout(350);
    await tab.keyboard.press('Shift');
    const collapsed = await tab.evaluate(MEASURE, { touch: TOUCH, phase: 'settled' });
    // A script error that stops the rail's own component leaves the button
    // dead and would have this "collapsed" pass measure the open rail again.
    if (!(await tab.evaluate(() => document.querySelector('.shell')?.dataset.collapsed === 'true'))) {
      collapsed.failures.push('the collapse button did not collapse the rail');
    }

    for (const [state, result] of [['open', open], ['collapsed', collapsed]]) {
      report.push({ file, height, state, ...result });
      failed += result.failures.length;
    }
  }

  // Reported, not counted: an error elsewhere on the page is measure-
  // responsive.mjs's business, and one that breaks the rail already fails the
  // collapse check above.
  if (errors.length) {
    report.push({ file, height: null, state: 'script', failures: [], warnings: [...new Set(errors)] });
  }

  await tab.close();
}

await browser.close();
server.close();

if (process.argv.includes('--json')) {
  console.log(JSON.stringify(report, null, 2));
} else {
  for (const row of report) {
    if (!row.failures.length && !row.warnings) continue;
    console.log(`\n${row.file} · ${row.height ?? '-'}px · ${row.state}`);
    for (const f of row.failures) console.log(`   ✗ ${f}`);
    for (const w of row.warnings ?? []) console.log(`   ! page error (not a rail failure): ${w}`);
  }
  const scrolled = report.filter((r) => r.list && r.list.scrolls).length;
  const railScrolled = report.filter((r) => r.railScrolls).length;
  console.log(`\n${pages.length} screens × ${HEIGHTS.length} heights × open/collapsed = ${report.filter((r) => r.state !== 'script').length} layouts`);
  console.log(`the list scrolled inside its region in ${scrolled} of them; the whole rail scrolled in ${railScrolled}`);
  console.log(failed ? `FAILED — ${failed} problem(s)` : 'PASSED — no overlap, every link and control reachable, the account region on screen');
}

process.exit(failed ? 1 : 0);
