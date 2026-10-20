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
  // Chromium logs the 4xx answers the scenarios mock for the endpoint as errors, they are expected
  page.on('console', (m) => m.type() === 'error' && !m.location().url.includes('/typo3/ajax/opinion/submit') && errors.push(m.text()));
  await page.route(`${BASE}/harness.html`, (route) => route.fulfill({ contentType: 'text/html', body: html }));
  try {
    await page.goto(`${BASE}/harness.html`);
    await page.click('#open');
    await fn(page);
    assert(errors.length === 0, 'unexpected page errors');
    console.log(`PASS ${name}`);
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
  assert(await page.locator('.opinion-dialog__counter[aria-live]').count() === 0, 'counter announces every keystroke');
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

async function dragOnCanvas(page, from, to) {
  const box = await page.locator('.opinion-annotation__canvas').boundingBox();
  await page.mouse.move(box.x + box.width * from[0], box.y + box.height * from[1]);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width * ((from[0] + to[0]) / 2), box.y + box.height * ((from[1] + to[1]) / 2), { steps: 3 });
  await page.mouse.move(box.x + box.width * to[0], box.y + box.height * to[1], { steps: 3 });
  await page.mouse.up();
}

// Colour of the image (data URL or the editor canvas) at relative coordinates
async function pixelsAt(page, source, points) {
  return page.evaluate(async ({ source, points }) => {
    let image = document.querySelector('.opinion-annotation__canvas');
    if (source) {
      image = new Image();
      image.src = source;
      await image.decode();
    }
    const c = document.createElement('canvas'); c.width = image.width; c.height = image.height;
    const ctx = c.getContext('2d'); ctx.drawImage(image, 0, 0);
    return points.map(([x, y]) => {
      const [r, g, b] = ctx.getImageData(Math.round(image.width * x), Math.round(image.height * y), 1, 1).data;
      return { r, g, b };
    });
  }, { source, points });
}

const isYellow = ({ r, g, b }) => r > 200 && g > 170 && b < 110;
const isBlack = ({ r, g, b }) => r < 40 && g < 40 && b < 40;
const pressed = (page, selector) => page.getAttribute(selector, 'aria-pressed');

// The preview is updated after the editor closed; the focus returns to "Mark up" once it is done
async function closeEditor(page, action) {
  await action();
  await page.waitForSelector('dialog.opinion-annotation', { state: 'detached' });
  await page.waitForFunction(() => document.activeElement?.textContent === '[annotate]', null, { timeout: 5000 });
}

await scenario('screenshot annotation: rectangle, arrow, black out, undo, cancel, upload', ['--auto-accept-this-tab-capture'], async (page) => {
  let body = '';
  await page.route('**/typo3/ajax/opinion/submit**', async (route) => {
    body = route.request().postDataBuffer().toString('latin1');
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, title: 'Feedback', message: 'ok', delivered: true }) });
  });
  assert(!(await page.isVisible('text=[annotate]')), 'mark up visible without screenshot');
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  const original = await page.getAttribute('.opinion-dialog__preview', 'src');

  // Editor opens above the dialog with the first tool selected
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  assert(await page.locator('dialog.opinion-dialog[open]').count() === 1, 'feedback dialog closed');
  assert(await page.evaluate(() => document.activeElement?.dataset.tool) === 'rectangle', 'rectangle tool not focused');
  assert(await pressed(page, '[data-tool=rectangle]') === 'true', 'rectangle not pressed');
  assert(await page.isDisabled('text=[annotationUndo]') && await page.isDisabled('text=[annotationClear]'), 'undo/clear enabled without shapes');

  await page.click('[data-color=yellow]');
  assert(await pressed(page, '[data-color=yellow]') === 'true' && await pressed(page, '[data-color=red]') === 'false', 'colour state wrong');
  await dragOnCanvas(page, [0.1, 0.1], [0.4, 0.4]);
  await page.click('text=[annotationArrow]');
  await dragOnCanvas(page, [0.1, 0.7], [0.4, 0.7]);
  await page.click('text=[annotationBlackout]');
  assert(await page.isDisabled('[data-color=red]'), 'colours enabled for black out');
  await dragOnCanvas(page, [0.6, 0.1], [0.9, 0.4]);
  await page.click('text=[annotationUndo]');
  let [blackout] = await pixelsAt(page, null, [[0.75, 0.25]]);
  assert(!isBlack(blackout), 'undo did not remove the black out');
  await dragOnCanvas(page, [0.6, 0.1], [0.9, 0.4]);
  await page.keyboard.press('Control+z');
  [blackout] = await pixelsAt(page, null, [[0.75, 0.25]]);
  assert(!isBlack(blackout), 'Ctrl+Z did not remove the black out');
  await dragOnCanvas(page, [0.6, 0.1], [0.9, 0.4]);
  // A click without dragging adds no shape
  await dragOnCanvas(page, [0.5, 0.9], [0.5, 0.9]);
  await closeEditor(page, () => page.click('text=[annotationApply]'));

  const annotated = await page.getAttribute('.opinion-dialog__preview', 'src');
  assert(annotated !== original && annotated.startsWith('data:image/jpeg;base64,'), 'preview not updated');
  const [rectangleEdge, arrowShaft, blackoutCentre, untouched] = await pixelsAt(page, annotated, [[0.1, 0.25], [0.25, 0.7], [0.75, 0.25], [0.5, 0.9]]);
  assert(isYellow(rectangleEdge), 'rectangle missing: ' + JSON.stringify(rectangleEdge));
  assert(isYellow(arrowShaft), 'arrow missing: ' + JSON.stringify(arrowShaft));
  assert(isBlack(blackoutCentre), 'black out missing: ' + JSON.stringify(blackoutCentre));
  assert(!isBlack(untouched) && !isYellow(untouched), 'click added a shape');

  // Reopening shows the shapes; Escape closes only the editor and keeps the image
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  assert(await page.isEnabled('text=[annotationUndo]'), 'shapes not restored');
  [blackout] = await pixelsAt(page, null, [[0.75, 0.25]]);
  assert(isBlack(blackout), 'black out not shown again');
  await page.click('text=[annotationClear]');
  await closeEditor(page, () => page.keyboard.press('Escape'));
  assert(await page.locator('dialog.opinion-dialog[open]').count() === 1, 'Escape closed the feedback dialog');
  assert(await page.getAttribute('.opinion-dialog__preview', 'src') === annotated, 'cancel changed the screenshot');

  await page.fill('textarea', 'The teaser image is cropped.');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  const uploaded = Buffer.from(annotated.split(',')[1], 'base64').toString('latin1');
  assert(body.includes('filename="screenshot.jpg"\r\nContent-Type: image/jpeg\r\n\r\n' + uploaded), 'annotated image not uploaded');
});

await scenario('screenshot annotation: clear all restores the capture, retake drops the shapes', ['--auto-accept-this-tab-capture'], async (page) => {
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  const original = await page.getAttribute('.opinion-dialog__preview', 'src');
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.click('text=[annotationBlackout]');
  await dragOnCanvas(page, [0.2, 0.2], [0.8, 0.8]);
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  assert(await page.getAttribute('.opinion-dialog__preview', 'src') !== original, 'black out not applied');

  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.click('text=[annotationClear]');
  assert(await page.evaluate(() => document.activeElement?.dataset.tool) === 'rectangle', 'focus lost after clear');
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  assert(await page.getAttribute('.opinion-dialog__preview', 'src') === original, 'clear all did not restore the capture');

  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.click('text=[annotationBlackout]');
  await dragOnCanvas(page, [0.2, 0.2], [0.8, 0.8]);
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  await page.click('text=[screenshotRetake]');
  await page.waitForFunction((previous) => document.querySelector('.opinion-dialog__preview')?.src !== previous, await page.getAttribute('.opinion-dialog__preview', 'src'), { timeout: 10000 });
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  assert(await page.isDisabled('text=[annotationUndo]'), 'shapes kept after retake');
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

// Markup of the admin panel bar as rendered by EXT:adminpanel (Partials/Modules/Item.fluid.html), reduced
function adminPanelHtml(withConfig) {
  const dialogConfig = { ...config, labels: { title: 'Send feedback', message: 'Message', charactersLeft: '{count}', send: 'Send', cancel: 'Cancel' } };
  const content = withConfig
    ? `<button type="button" id="panel-button" data-opinion-config="${JSON.stringify(dialogConfig).replaceAll('&', '&amp;').replaceAll('"', '&quot;')}">Send feedback</button>`
    : '<p>No email address</p><a href="/typo3/module/user/setup">Open user settings</a>';
  const module = (uid, label, tab, inner) => `<div class="typo3-adminPanel-module typo3-adminPanel-module-${uid}">
    <div class="typo3-adminPanel-module-trigger" data-typo3-role="typo3-adminPanel-module-trigger" id="trigger-${tab}"><span class="typo3-adminPanel-module-trigger-icon">*</span><span class="typo3-adminPanel-module-trigger-label">${label}</span></div>
    <div class="typo3-adminPanel-content" data-typo3-role="typo3-adminPanel-content"><div class="typo3-adminPanel-content-nav"><a href="#" data-typo3-role="typo3-adminPanel-content-tab" data-typo3-tab-target="${tab}">${label}</a></div>
    <div class="typo3-adminPanel-content-panes-item" data-typo3-tab-id="${tab}">${inner}</div></div></div>`;
  return `<!doctype html><html><head><link rel="stylesheet" href="/Css/opinion-dialog.css"></head><body>
<form id="TSFE_ADMIN_PANEL_FORM" data-typo3-role="typo3-adminPanel">${module(0, 'Info', 'info_general', '<p>General</p>')}${module(1, 'Feedback', 'opinion_feedback', content)}</form>
<script>
// Stand-in for EXT:adminpanel: a click on a trigger opens the module panel
window.openedPanels = [];
document.querySelectorAll('[data-typo3-role=typo3-adminPanel-module-trigger]').forEach((trigger) => trigger.addEventListener('click', () => {
  trigger.closest('.typo3-adminPanel-module').classList.add('typo3-adminPanel-module-active');
  window.openedPanels.push(trigger.id);
}));
</script>
<script src="/JavaScript/adminpanel-module.js"></script></body></html>`;
}

await scenario('admin panel: "Feedback" in the bar opens the dialog directly', [], async (page) => {
  await page.route(`${BASE}/adminpanel.html`, (route) => route.fulfill({ contentType: 'text/html', body: adminPanelHtml(true) }));
  await page.goto(`${BASE}/adminpanel.html`);
  await page.click('#trigger-opinion_feedback .typo3-adminPanel-module-trigger-label');
  await page.waitForSelector('dialog.opinion-dialog[open]');
  assert(await page.textContent('.opinion-dialog__title') === 'Send feedback', 'dialog not opened with the panel configuration');
  assert(JSON.stringify(await page.evaluate(() => window.openedPanels)) === '[]', 'module panel opened as well');
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('dialog'));
  // Other modules are left alone, the button in the panel still works
  await page.click('#trigger-info_general');
  assert(JSON.stringify(await page.evaluate(() => window.openedPanels)) === '["trigger-info_general"]', 'other module not opened');
  await page.click('#panel-button');
  await page.waitForSelector('dialog.opinion-dialog[open]');
});

await scenario('admin panel: without dialog configuration the panel opens as usual', [], async (page) => {
  await page.route(`${BASE}/adminpanel.html`, (route) => route.fulfill({ contentType: 'text/html', body: adminPanelHtml(false) }));
  await page.goto(`${BASE}/adminpanel.html`);
  await page.click('#trigger-opinion_feedback');
  assert(JSON.stringify(await page.evaluate(() => window.openedPanels)) === '["trigger-opinion_feedback"]', 'panel not opened');
  assert(await page.locator('dialog').count() === 0, 'dialog opened without configuration');
});

server.close();
process.exit(failures > 0 ? 1 : 0);
