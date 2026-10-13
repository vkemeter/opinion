/**
 * Browser test of the shared feedback dialog against a mocked endpoint.
 *
 * Runs Chromium in the "new headless" mode, which supports tab capture:
 *   npm --prefix Build ci && npx --prefix Build playwright install chromium
 *   node Tests/Browser/dialog.test.mjs
 *
 * Playwright is taken from Build/node_modules.
 */
import { createReadStream } from 'node:fs';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { dirname, extname, join, normalize } from 'node:path';
import { fileURLToPath } from 'node:url';

const packagePath = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const publicPath = join(packagePath, 'Resources', 'Public');
const require = createRequire(join(packagePath, 'Build', 'package.json'));
const { chromium } = require('playwright');

const contentTypes = { '.js': 'text/javascript', '.css': 'text/css' };
const server = createServer((request, response) => {
  const path = normalize(join(publicPath, new URL(request.url, 'http://localhost').pathname));
  if (!path.startsWith(publicPath) || !contentTypes[extname(path)]) {
    response.writeHead(404).end();
    return;
  }
  response.writeHead(200, { 'Content-Type': contentTypes[extname(path)] });
  createReadStream(path).on('error', () => response.end()).pipe(response);
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
let failures = 0;

const BASE = `http://localhost:${server.address().port}`;
const config = {
  endpoint: '/typo3/ajax/opinion/submit?token=abc',
  source: 'frontend',
  context: { pageUid: 12 },
  messageMinLength: 10,
  messageMaxLength: 5000,
  categories: [{ value: 'bug', label: 'Bug' }, { value: 'content', label: 'Content' }],
  severities: [{ value: 'low', label: 'Low' }, { value: 'normal', label: 'Normal' }, { value: 'high', label: 'High' }],
  defaultSeverity: 'normal',
};
const html = `<!doctype html><html><head><link rel="stylesheet" href="/Css/opinion-dialog.css"></head>
<body style="background:linear-gradient(90deg,#f00,#00f)"><h1>Harness page</h1><button id="open">open</button>
<script type="module">
import { openOpinionDialog } from '/JavaScript/opinion-dialog.js';
const config = ${JSON.stringify(config)};
// Labels are rendered as [key], so the tests can find elements by them
config.labels = new Proxy({}, { get: (_, key) => key === 'charactersLeft' ? 'Characters left: {count}' : '[' + String(key) + ']' });
document.getElementById('open').addEventListener('click', () => { window.dialog = openOpinionDialog(config); });
document.getElementById('open').addEventListener('open-unavailable', () => { window.dialog = openOpinionDialog({ ...config, unavailable: window.unavailable }); });
document.getElementById('open').addEventListener('open-with-collect', () => { window.dialog = openOpinionDialog({ ...config, collect: window.dialogConfigOverride }); });
</script></body></html>`;

async function scenario(name, args, fn) {
  const browser = await chromium.launch({ args, channel: 'chromium' });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
  await page.route(`${BASE}/harness.html`, (route) => route.fulfill({ contentType: 'text/html', body: html }));
  try {
    await page.goto(`${BASE}/harness.html`);
    await page.click('#open');
    await fn(page);
    console.log(`PASS ${name}${errors.length ? ' (page errors: ' + errors.join(' | ') + ')' : ''}`);
  } catch (e) {
    failures++;
    console.log(`FAIL ${name}: ${e.message.split('\n')[0]}${errors.length ? ' (page errors: ' + errors.join(' | ') + ')' : ''}`);
  } finally {
    await browser.close();
  }
}

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

await scenario('text-only submit with field errors, then success', [], async (page) => {
  const requests = [];
  let call = 0;
  await page.route('**/typo3/ajax/opinion/submit**', async (route) => {
    requests.push(route.request().postDataBuffer().toString('latin1'));
    call++;
    if (call === 1) {
      return route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ success: false, title: 'Feedback', message: 'Please check', errors: { message: 'Too short!' } }) });
    }
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, title: 'Feedback', message: 'Thanks <b>x</b>', delivered: true }) });
  });
  assert(await page.locator('dialog.opinion-dialog[open]').count() === 1, 'dialog not open');
  assert(await page.evaluate(() => document.activeElement?.name) === 'message', 'textarea not focused');
  assert(await page.getByLabel('[message]').count() === 1, 'textarea has no label');
  assert(await page.getByLabel('[category]').count() === 1, 'category has no label');
  await page.fill('textarea', 'short');
  assert((await page.textContent('.opinion-dialog__counter')).includes('4995'), 'counter wrong');
  await page.click('button[type=submit]');
  await page.waitForSelector('[data-error-for="message"]:not([hidden])');
  assert(await page.textContent('[data-error-for="message"]') === 'Too short!', 'field error not shown');
  assert(await page.getAttribute('textarea', 'aria-invalid') === 'true', 'aria-invalid missing');
  await page.fill('textarea', '  The teaser image is cropped.  ');
  await page.selectOption('select[name=category]', 'content');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  assert(await page.textContent('.opinion-dialog__status') === 'Thanks <b>x</b>', 'success message not as text');
  const body = requests[1];
  assert(body.includes('name="message"\r\n\r\nThe teaser image is cropped.\r\n'), 'message not trimmed/sent');
  assert(body.includes('name="category"\r\n\r\ncontent'), 'category missing');
  assert(body.includes('name="severity"\r\n\r\nnormal'), 'severity missing');
  assert(body.includes('name="source"\r\n\r\nfrontend'), 'source missing');
  assert(body.includes('{"pageUid":12}'), 'context missing');
  assert(body.includes('"viewport":{"width"'), 'client info missing');
  assert(!/cookie/i.test(body), 'cookie data sent');
  assert(!body.includes('name="screenshot"'), 'unexpected screenshot');
  assert(await page.evaluate(() => document.activeElement?.textContent) === '[close]', 'close button not focused');
  await page.click('text=[close]');
  await page.waitForFunction(() => !document.querySelector('dialog'), null, { timeout: 2000 });
});

await scenario('escape closes, session expired message', [], async (page) => {
  await page.route('**/typo3/ajax/opinion/submit**', (route) => route.fulfill({ status: 401, body: '' }));
  await page.fill('textarea', 'The teaser image is cropped.');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--error');
  assert(await page.textContent('.opinion-dialog__status') === '[sessionExpired]', 'no session message');
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('dialog'), null, { timeout: 2000 });
});

await scenario('screenshot capture, preview, retake, remove, upload', ['--auto-accept-this-tab-capture'], async (page) => {
  let body = '';
  await page.route('**/typo3/ajax/opinion/submit**', async (route) => {
    body = route.request().postDataBuffer().toString('latin1');
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, title: 'Feedback', message: 'ok', delivered: true }) });
  });
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  const src = await page.getAttribute('.opinion-dialog__preview', 'src');
  assert(src?.startsWith('data:image/jpeg;base64,'), 'preview not a JPEG data URL');
  const pixel = await page.evaluate(async (src) => {
    const img = new Image(); img.src = src; await img.decode();
    const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    const ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0);
    const [r, g, b] = ctx.getImageData(Math.floor(img.width / 2), Math.floor(img.height / 2), 1, 1).data;
    return { r, g, b, w: img.width };
  }, src);
  console.log('   captured image', JSON.stringify(pixel));
  assert(!(pixel.r > 230 && pixel.g > 230 && pixel.b > 230), 'dialog (white) visible in the middle of the screenshot');
  assert(await page.isVisible('text=[screenshotRetake]') && await page.isVisible('text=[screenshotRemove]'), 'retake/remove not visible');
  assert(!(await page.isVisible('text=[screenshotTake]')), 'take still visible');
  await page.click('text=[screenshotRemove]');
  assert(!(await page.isVisible('.opinion-dialog__preview')), 'preview still visible after remove');
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  await page.click('text=[screenshotRetake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  await page.fill('textarea', 'The teaser image is cropped.');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  assert(body.includes('name="screenshot"; filename="screenshot.jpg"\r\nContent-Type: image/jpeg\r\n\r\n\xFF\xD8\xFF'), 'screenshot not uploaded as JPEG');
});

await scenario('denied screenshot shows notice and text submit still possible', ['--auto-reject-this-tab-capture'], async (page) => {
  await page.click('text=[screenshotTake]');
  await page.waitForFunction(() => document.querySelector('.opinion-dialog__notice')?.textContent !== '', null, { timeout: 10000 });
  assert(await page.textContent('.opinion-dialog__notice') === '[screenshotDenied]', 'no denied notice: ' + await page.textContent('.opinion-dialog__notice'));
  assert(!(await page.locator('dialog').evaluate((d) => d.classList.contains('opinion-dialog--capturing'))), 'dialog stays hidden');
  assert(await page.isEnabled('button[type=submit]'), 'send disabled');
});

await scenario('unsupported browser disables screenshot, text submit works', [], async (page) => {
  await page.evaluate(() => document.querySelector('dialog')?.close());
  await page.waitForFunction(() => !document.querySelector('dialog'));
  await page.evaluate(() => { Object.defineProperty(navigator.mediaDevices, 'getDisplayMedia', { value: undefined }); });
  await page.click('#open');
  assert(await page.isDisabled('text=[screenshotTake]'), 'take button enabled');
  assert(await page.textContent('.opinion-dialog__notice') === '[screenshotUnsupported]', 'no unsupported notice');
  let sent = false;
  await page.route('**/typo3/ajax/opinion/submit**', (route) => { sent = true; return route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true,"message":"ok"}' }); });
  await page.fill('textarea', 'The teaser image is cropped.');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  assert(sent, 'not sent');
});

await scenario('only allowed client fields are collected', [], async (page) => {
  await page.evaluate(() => document.querySelector('dialog')?.close());
  await page.waitForFunction(() => !document.querySelector('dialog'));
  await page.evaluate(() => { window.dialogConfigOverride = { client: ['language', 'viewport'] }; });
  await page.evaluate(() => document.getElementById('open').dispatchEvent(new CustomEvent('open-with-collect')));
  let body = '';
  await page.route('**/typo3/ajax/opinion/submit**', (route) => { body = route.request().postDataBuffer().toString('latin1'); return route.fulfill({ status: 200, contentType: 'application/json', body: '{"success":true,"message":"ok"}' }); });
  await page.fill('textarea', 'The teaser image is cropped.');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  const clientInfo = JSON.parse(body.split('name="clientInfo"\r\n\r\n')[1].split('\r\n')[0]);
  assert(JSON.stringify(Object.keys(clientInfo).sort()) === '["language","viewport"]', 'unexpected keys: ' + Object.keys(clientInfo));
});

await scenario('users without email address see a notice instead of the form', [], async (page) => {
  await page.evaluate(() => document.querySelector('dialog')?.close());
  await page.waitForFunction(() => !document.querySelector('dialog'));
  await page.evaluate(() => {
    window.unavailable = { message: 'No email address', settingsUrl: '/typo3/module/user/setup', settingsLabel: 'Open user settings' };
    document.getElementById('open').dispatchEvent(new CustomEvent('open-unavailable'));
  });
  await page.waitForSelector('dialog.opinion-dialog[open]');
  assert(await page.locator('textarea').count() === 0, 'form rendered');
  assert(await page.textContent('dialog .opinion-dialog__status') === 'No email address', 'notice missing');
  assert(await page.getAttribute('dialog a', 'href') === '/typo3/module/user/setup', 'settings link missing');
  assert(await page.evaluate(() => document.activeElement?.textContent) === 'Open user settings', 'link not focused');
  await page.click('text=[close]');
  await page.waitForFunction(() => !document.querySelector('dialog'));
});

server.close();
process.exit(failures > 0 ? 1 : 0);
