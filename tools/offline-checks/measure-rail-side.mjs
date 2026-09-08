// Where does the dashboard rail actually land?
//
// Run from the project root:  node tools/offline-checks/measure-rail-side.mjs
//
// This exists because the rail shipped on the LEFT of an Arabic interface while
// three comments in two stylesheets asserted it was on the right. Reading the
// CSS is how it got there; only a measurement settles it. RtlShellTest guards
// the declarations statically — this measures the result.
//
import { chromium } from 'playwright'
import { readFileSync, readdirSync } from 'node:fs'

const BUILD = 'c:/Users/b.maher/Downloads/wesal/LARAVEL/public/build/assets'
const css = readdirSync(BUILD).filter((f) => f.startsWith('app-') && f.endsWith('.css'))
if (css.length === 0) {
  console.log('no compiled app css — run: npm run build')
  process.exit(1)
}
const sheet = readFileSync(`${BUILD}/${css[0]}`, 'utf8')
console.log('stylesheet:', css[0])

const html = `<!doctype html>
<html lang="ar" dir="rtl" data-surface="light">
<head><meta charset="utf-8"><style>${sheet}</style></head>
<body class="page--app">
  <div class="shell" data-collapsed="false">
    <aside class="side" id="rail">
      <a class="side__b" aria-current="page" id="active" href="#"><span class="side__label">لوحة التحكم</span></a>
    </aside>
    <div class="shell__main" id="main">محتوى</div>
  </div>
  <div class="drawer__panel" id="drawer">درج</div>
</body></html>`

const browser = await chromium.launch()
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } })
const p = await ctx.newPage()
await p.setContent(html)

const box = async (sel) => {
  const b = await p.locator(sel).boundingBox()
  return b ? { x: Math.round(b.x), right: Math.round(b.x + b.width), w: Math.round(b.width) } : null
}

const rail = await box('#rail')
const main = await box('#main')
const drawer = await box('#drawer')

console.log('viewport 1440')
console.log('  rail  ', JSON.stringify(rail))
console.log('  main  ', JSON.stringify(main))
console.log('  drawer', JSON.stringify(drawer))
console.log()

const verdict = (name, b) => {
  if (!b) return `${name}: not rendered`
  return `${name}: ${b.right >= 1435 ? 'RIGHT ok' : 'LEFT  FAIL'} (x=${b.x} right=${b.right})`
}
console.log(verdict('rail  ', rail))
console.log(verdict('drawer', drawer))

await browser.close()
