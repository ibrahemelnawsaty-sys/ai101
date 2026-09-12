/**
 * Prints the certificate sheet the way a holder would — Chromium's "Save as
 * PDF", which honours the sheet's named A4-landscape page — and reports the
 * page count and size (D-81). Also writes a PNG to look at.
 *
 * Run: php tools/offline-checks/render-alpine-pages.php
 *      node tools/offline-checks/print-certificate.mjs
 */
import { chromium } from 'playwright';
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve('C:/Users/b.maher/Downloads/wesal/LARAVEL/public');
const TYPES = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.woff2': 'font/woff2', '.svg': 'image/svg+xml' };
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
const tab = await browser.newPage({ viewport: { width: 1200, height: 900 } });
await tab.goto(`http://127.0.0.1:${server.address().port}/certificate-probe.html`, { waitUntil: 'load' });
await tab.waitForTimeout(400);

const out = path.resolve(process.env.OUT || '.');
await tab.screenshot({ path: path.join(out, 'certificate-screen.png'), fullPage: true });
await tab.emulateMedia({ media: 'print' });
const pdf = await tab.pdf({ preferCSSPageSize: true, printBackground: true });
fs.writeFileSync(path.join(out, 'certificate.pdf'), pdf);
// A4 landscape at 96 dpi, so the picture is the page the PDF holds.
await tab.setViewportSize({ width: 1123, height: 794 });
await tab.screenshot({ path: path.join(out, 'certificate-print.png'), fullPage: true });

const text = pdf.toString('latin1');
const pages = (text.match(/\/Type\s*\/Page[^s]/g) || []).length;
const box = text.match(/\/MediaBox\s*\[\s*0\s+0\s+([\d.]+)\s+([\d.]+)\s*\]/);
console.log('pdf pages      :', pages);
console.log('page size (pt) :', box ? `${box[1]} x ${box[2]}` : '?', box && Number(box[1]) > Number(box[2]) ? '(landscape)' : '(portrait?)');
console.log('files          :', out);

await browser.close();
server.close();
