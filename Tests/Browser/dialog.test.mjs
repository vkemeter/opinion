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
config.labels = new Proxy({}, { get: (_, key) => ({ charactersLeft: 'Characters left: {count}', videoTimeLeft: 'Left: {time}' })[key] ?? '[' + String(key) + ']' });
document.getElementById('open').addEventListener('click', () => { window.dialog = openOpinionDialog(config); });
document.getElementById('open').addEventListener('open-unavailable', () => { window.dialog = openOpinionDialog({ ...config, unavailable: window.unavailable }); });
document.getElementById('open').addEventListener('open-with-collect', () => { window.dialog = openOpinionDialog({ ...config, collect: window.dialogConfigOverride }); });
document.getElementById('open').addEventListener('open-with-video', () => {
  window.dialog = openOpinionDialog({ ...config, captureKinds: ['screenshot', 'video'], video: window.videoLimits, collectContextAtEnd: () => ({ module: 'web_list' }) });
});
// The recordings of the scenarios, so a scenario can end the sharing like the browser bar does
const getDisplayMedia = navigator.mediaDevices.getDisplayMedia.bind(navigator.mediaDevices);
navigator.mediaDevices.getDisplayMedia = async (options) => { window.lastStream = await getDisplayMedia(options); return window.lastStream; };
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

async function openVideoDialog(page, videoLimits = { maxDuration: 60, uploadLimit: 10 * 1024 * 1024 }) {
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('dialog'));
  await page.evaluate((limits) => {
    window.videoLimits = limits;
    document.getElementById('open').dispatchEvent(new Event('open-with-video'));
  }, videoLimits);
  await page.waitForSelector('dialog.opinion-dialog[open]');
}

function mockSubmit(page, bodies) {
  return page.route('**/typo3/ajax/opinion/submit**', (route) => {
    bodies.push(route.request().postDataBuffer().toString('latin1'));
    return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true, title: 'Feedback', message: 'ok', delivered: true }) });
  });
}

await scenario('without video no capture choice is shown', [], async (page) => {
  assert(await page.locator('[role=switch]').count() === 0, 'choice shown without video');
  assert(await page.textContent('legend') === '[screenshot]', 'legend not for screenshots');
});

await scenario('video: notice, recording with page usable and click markers, input kept, upload', ['--auto-accept-this-tab-capture'], async (page) => {
  const bodies = [];
  await mockSubmit(page, bodies);
  await openVideoDialog(page);
  assert(await page.getAttribute('[role=switch]', 'aria-checked') === 'false', 'screenshot not preselected');
  assert(await page.getAttribute('[role=switch]', 'aria-label') === '[captureVideo]', 'switch has no name');
  assert(await page.isHidden('text=[videoNotice]'), 'notice shown for screenshots');
  await page.fill('textarea', 'The menu closes too early.');
  await page.focus('[role=switch]');
  await page.keyboard.press('Space');
  assert(await page.getAttribute('[role=switch]', 'aria-checked') === 'true', 'switch not on after Space');
  assert(await page.isVisible('text=[videoNotice]'), 'privacy notice missing');
  await page.click('text=[videoStart]');
  await page.waitForSelector('.opinion-recording');
  assert(await page.locator('dialog[open]').count() === 0, 'dialog still open during the recording');
  const leaving = await page.evaluate(() => {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);
    return event.defaultPrevented;
  });
  assert(leaving, 'leaving the page is not confirmed');
  await page.click('h1');
  assert(await page.locator('.opinion-click-marker').count() === 1, 'no click marker');
  assert(/^Left: (1:00|0:5\d)$/.test(await page.textContent('.opinion-recording__time')), 'remaining time not shown');
  await page.waitForTimeout(1500);
  await page.click('.opinion-recording__stop');
  await page.waitForSelector('dialog.opinion-dialog[open] video.opinion-dialog__preview:not([hidden])', { timeout: 10000 });
  assert(await page.locator('.opinion-recording').count() === 0, 'bar not removed');
  assert(await page.inputValue('textarea') === 'The menu closes too early.', 'description lost');
  assert(await page.isVisible('text=[videoRetake]') && await page.isVisible('text=[videoRemove]'), 'record again / remove missing');
  assert(await page.isHidden('text=[annotate]'), 'videos must not be annotated');
  assert(!(await page.evaluate(() => { const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event); return event.defaultPrevented; })), 'leaving still confirmed');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  const body = bodies[0];
  assert(/name="video"; filename="video\.(mp4|webm)"\r\nContent-Type: video\/(mp4|webm)/.test(body), 'video not uploaded');
  // avc1 breaks when the tab is resized during the recording
  const avc3 = await page.evaluate(() => MediaRecorder.isTypeSupported('video/mp4;codecs=avc3'));
  assert(!avc3 || body.includes('filename="video.mp4"'), 'MP4 (avc3) not preferred');
  assert(body.includes('\r\n\r\n\x00\x00\x00') || body.includes('\x1A\x45\xDF\xA3'), 'not an MP4 or WebM file');
  assert(!body.includes('name="screenshot"'), 'screenshot sent as well');
  assert(body.includes('name="contextAtEnd"\r\n\r\n{"module":"web_list"}'), 'end context missing');
});

await scenario('video: stops at the maximum duration and when the sharing ends, replaces a screenshot', ['--auto-accept-this-tab-capture'], async (page) => {
  const bodies = [];
  await mockSubmit(page, bodies);
  await openVideoDialog(page, { maxDuration: 2, uploadLimit: 10 * 1024 * 1024 });
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('img.opinion-dialog__preview:not([hidden])', { timeout: 10000 });
  await page.click('[role=switch]');
  assert(await page.getAttribute('[role=switch]', 'aria-checked') === 'true', 'switch does not select video');
  assert(await page.isVisible('img.opinion-dialog__preview'), 'changing the option alone removed the screenshot');
  // The retake button offers the other kind now
  await page.click('text=[videoStart] >> visible=true');
  await page.waitForSelector('.opinion-recording');
  await page.waitForSelector('dialog.opinion-dialog[open] video.opinion-dialog__preview:not([hidden])', { timeout: 6000 });
  assert(await page.isHidden('img.opinion-dialog__preview'), 'screenshot kept next to the video');

  await page.click('text=[videoRetake]');
  await page.waitForSelector('.opinion-recording');
  await page.evaluate(() => window.lastStream.getVideoTracks()[0].dispatchEvent(new Event('ended')));
  await page.waitForSelector('dialog.opinion-dialog[open] video.opinion-dialog__preview:not([hidden])', { timeout: 6000 });
  await page.fill('textarea', 'The menu closes too early.');
  await page.click('button[type=submit]');
  await page.waitForSelector('.opinion-dialog__status--success');
  assert(bodies[0].includes('name="video"') && !bodies[0].includes('name="screenshot"'), 'not only the video sent');
});

await scenario('video: stops before the recorded size reaches the upload limit', ['--auto-accept-this-tab-capture'], async (page) => {
  await openVideoDialog(page, { maxDuration: 60, uploadLimit: 1 });
  await page.click('[role=switch]');
  await page.click('text=[videoStart]');
  await page.waitForSelector('.opinion-recording');
  // The first chunk arrives after about a second and already passes the limit
  await page.waitForSelector('dialog.opinion-dialog[open] video.opinion-dialog__preview:not([hidden])', { timeout: 6000 });
});

await scenario('video: click markers inside a same-origin frame, also after it reloaded', ['--auto-accept-this-tab-capture'], async (page) => {
  await page.evaluate(() => {
    const frame = document.createElement('iframe');
    frame.id = 'module';
    frame.srcdoc = '<button id="save">Save</button>';
    document.body.append(frame);
    return new Promise((resolve) => frame.addEventListener('load', resolve, { once: true }));
  });
  await openVideoDialog(page);
  await page.click('[role=switch]');
  await page.click('text=[videoStart]');
  await page.waitForSelector('.opinion-recording');
  const frame = page.frameLocator('#module');
  await frame.locator('#save').click();
  assert(await frame.locator('.opinion-click-marker').count() === 1, 'no marker in the frame');
  await page.evaluate(() => new Promise((resolve) => {
    const module = document.getElementById('module');
    module.addEventListener('load', resolve, { once: true });
    module.srcdoc = '<button id="save">Saved</button>';
  }));
  await frame.locator('#save').click();
  assert(await frame.locator('.opinion-click-marker').count() === 1, 'no marker after the frame reloaded');
  await page.click('.opinion-recording__stop');
  await page.waitForSelector('dialog.opinion-dialog[open]');
});

async function reopenDialog(page) {
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('dialog'));
  await page.click('#open');
  await page.waitForSelector('dialog.opinion-dialog[open]');
}

await scenario('layout: close button in the header, only the body scrolls', [], async (page) => {
  assert(await page.locator('.opinion-dialog__header .opinion-dialog__title').count() === 1, 'no header with title');
  assert(await page.locator('.opinion-dialog__footer button[type=submit]').count() === 1, 'Send not in the footer');
  // Header before body before footer: Shift+Tab from the description reaches the close button
  await page.keyboard.press('Shift+Tab');
  assert(await page.evaluate(() => document.activeElement?.getAttribute('aria-label')) === '[close]', 'close button not before the body');
  await page.setViewportSize({ width: 800, height: 420 });
  const body = await page.evaluate(() => { const b = document.querySelector('.opinion-dialog__body'); return { scrolls: b.scrollHeight > b.clientHeight, overflow: getComputedStyle(b).overflowY }; });
  assert(body.scrolls && body.overflow === 'auto', 'body does not scroll on a low viewport');
  const send = await page.locator('button[type=submit]').boundingBox();
  assert(send && send.y + send.height <= 420, 'Send not visible on a low viewport');
  await page.click('[aria-label="[close]"]');
  await page.waitForFunction(() => !document.querySelector('dialog'), null, { timeout: 2000 });
});

await scenario('look: dark by the system or the TYPO3 backend, light when the backend says so', [], async (page) => {
  const background = () => page.evaluate(() => getComputedStyle(document.querySelector('dialog')).backgroundColor);
  assert(await background() === 'rgb(255, 255, 255)', 'light dialog not white');
  await page.emulateMedia({ colorScheme: 'dark' });
  assert(await background() === 'rgb(27, 31, 36)', 'not dark with a dark system');
  await page.evaluate(() => { document.documentElement.dataset.colorScheme = 'light'; });
  assert(await background() === 'rgb(255, 255, 255)', 'backend light does not win over a dark system');
  await page.emulateMedia({ colorScheme: 'light' });
  await page.evaluate(() => { document.documentElement.dataset.colorScheme = 'dark'; });
  assert(await background() === 'rgb(27, 31, 36)', 'backend dark does not win over a light system');
});

await scenario('look: controls keep their style on a page that styles them globally', [], async (page) => {
  // Many sites shrink the root font size, which would shrink every rem
  await page.addStyleTag({ content: 'html { font-size: 62.5%; } button, select, textarea, label, p { border: 5px dashed lime; border-radius: 0; padding: 30px; margin: 20px; font-size: 30px; text-transform: uppercase; background: yellow; box-shadow: 0 0 0 9px red; }' });
  await reopenDialog(page);
  const styles = await page.evaluate(() => {
    const style = (selector) => { const c = getComputedStyle(document.querySelector(selector)); return [c.borderTopWidth, c.borderTopLeftRadius, c.paddingTop, c.paddingLeft, c.marginTop, c.textTransform, c.fontSize].join(' '); };
    return { textarea: style('.opinion-dialog__textarea'), select: style('.opinion-dialog__select'), send: style('button[type=submit]'), label: style('.opinion-dialog__label') };
  });
  assert(styles.textarea === '1px 8px 6px 12px 0px none 16px', `textarea: ${styles.textarea}`);
  assert(styles.select === '1px 8px 6px 12px 0px none 16px', `select: ${styles.select}`);
  assert(styles.send === '1px 8px 6px 14px 0px none 16px', `send: ${styles.send}`);
  assert(styles.label.endsWith('0px none 16px'), `label: ${styles.label}`);
});

await scenario('look: invalid field in red, focus ring, no animation with reduced motion', [], async (page) => {
  await page.route('**/typo3/ajax/opinion/submit**', (route) => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ success: false, message: 'Please check', errors: { message: 'Too short!' } }) }));
  await page.fill('textarea', 'The teaser image is cropped.');
  await page.click('button[type=submit]');
  await page.waitForSelector('[data-error-for="message"]:not([hidden])');
  // The border colour changes with a transition
  await page.waitForTimeout(300);
  const invalid = await page.evaluate(() => [getComputedStyle(document.querySelector('textarea')).borderTopColor, getComputedStyle(document.querySelector('[data-error-for="message"]')).color]);
  assert(invalid[0] === 'rgb(198, 40, 40)' && invalid[1] === 'rgb(198, 40, 40)', `invalid not red: ${invalid}`);
  await page.focus('select[name=category]');
  await page.waitForTimeout(300);
  assert((await page.evaluate(() => getComputedStyle(document.activeElement).boxShadow)).includes('3px'), 'no focus ring');
  assert((await page.evaluate(() => getComputedStyle(document.querySelector('dialog')).transitionDuration)).startsWith('0.3s'), 'no opening animation');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await reopenDialog(page);
  assert(await page.evaluate(() => getComputedStyle(document.querySelector('dialog')).transitionDuration) === '0s', 'animation despite reduced motion');
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
  assert(await page.isDisabled('[aria-label="[annotationUndo]"]') && await page.isDisabled('[aria-label="[annotationClear]"]'), 'undo/clear enabled without shapes');

  await page.click('[data-color=yellow]');
  assert(await pressed(page, '[data-color=yellow]') === 'true' && await pressed(page, '[data-color=red]') === 'false', 'colour state wrong');
  await dragOnCanvas(page, [0.1, 0.1], [0.4, 0.4]);
  await page.click('[aria-label="[annotationArrow]"]');
  await dragOnCanvas(page, [0.1, 0.7], [0.4, 0.7]);
  await page.click('[aria-label="[annotationBlackout]"]');
  assert(await page.isDisabled('[data-color=red]'), 'colours enabled for black out');
  await dragOnCanvas(page, [0.6, 0.1], [0.9, 0.4]);
  await page.click('[aria-label="[annotationUndo]"]');
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
  assert(await page.isEnabled('[aria-label="[annotationUndo]"]'), 'shapes not restored');
  [blackout] = await pixelsAt(page, null, [[0.75, 0.25]]);
  assert(isBlack(blackout), 'black out not shown again');
  await page.click('[aria-label="[annotationClear]"]');
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
  await page.click('[aria-label="[annotationBlackout]"]');
  await dragOnCanvas(page, [0.2, 0.2], [0.8, 0.8]);
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  assert(await page.getAttribute('.opinion-dialog__preview', 'src') !== original, 'black out not applied');

  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.click('[aria-label="[annotationClear]"]');
  assert(await page.evaluate(() => document.activeElement?.dataset.tool) === 'rectangle', 'focus lost after clear');
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  assert(await page.getAttribute('.opinion-dialog__preview', 'src') === original, 'clear all did not restore the capture');

  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.click('[aria-label="[annotationBlackout]"]');
  await dragOnCanvas(page, [0.2, 0.2], [0.8, 0.8]);
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  await page.click('text=[screenshotRetake]');
  await page.waitForFunction((previous) => document.querySelector('.opinion-dialog__preview')?.src !== previous, await page.getAttribute('.opinion-dialog__preview', 'src'), { timeout: 10000 });
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  assert(await page.isDisabled('[aria-label="[annotationUndo]"]'), 'shapes kept after retake');
});

async function imageSize(page, source) {
  return page.evaluate(async (source) => {
    const image = new Image(); image.src = source; await image.decode();
    return { width: image.width, height: image.height };
  }, source);
}

// Number of pixels of the editor canvas in the relative box that match the colour test
async function countPixels(page, box, test) {
  const data = await page.evaluate((box) => {
    const canvas = document.querySelector('.opinion-annotation__canvas');
    const x = Math.round(canvas.width * box[0]); const y = Math.round(canvas.height * box[1]);
    return Array.from(canvas.getContext('2d').getImageData(x, y, Math.round(canvas.width * box[2]) - x, Math.round(canvas.height * box[3]) - y).data);
  }, box);
  let count = 0;
  for (let i = 0; i < data.length; i += 4) {
    if (test({ r: data[i], g: data[i + 1], b: data[i + 2] })) count++;
  }
  return count;
}

await scenario('screenshot annotation: crop, reset crop, reopen with the frame', ['--auto-accept-this-tab-capture'], async (page) => {
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  const full = await imageSize(page, await page.getAttribute('.opinion-dialog__preview', 'src'));

  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  assert(await page.isDisabled('[aria-label="[annotationResetCrop]"]'), 'reset crop enabled without crop');
  // Black out the left half of the later frame, so its content can be checked
  await page.click('[aria-label="[annotationBlackout]"]');
  await dragOnCanvas(page, [0.25, 0.25], [0.5, 0.5]);
  await page.click('[aria-label="[annotationCrop]"]');
  assert(await page.isDisabled('[data-color=red]'), 'colours enabled for crop');
  await dragOnCanvas(page, [0.1, 0.1], [0.12, 0.12]);
  assert(await page.isDisabled('[aria-label="[annotationResetCrop]"]'), 'too small frame accepted');
  await dragOnCanvas(page, [0.25, 0.25], [0.75, 0.5]);
  assert(await page.isEnabled('[aria-label="[annotationResetCrop]"]'), 'crop not set');
  await closeEditor(page, () => page.click('text=[annotationApply]'));

  const cropped = await page.getAttribute('.opinion-dialog__preview', 'src');
  const size = await imageSize(page, cropped);
  console.log('   cropped', JSON.stringify(size), 'of', JSON.stringify(full));
  assert(Math.abs(size.width - full.width * 0.5) <= 2 && Math.abs(size.height - full.height * 0.25) <= 2, 'cropped JPEG has not the frame size');
  const [left, right] = await pixelsAt(page, cropped, [[0.25, 0.5], [0.75, 0.5]]);
  assert(isBlack(left) && !isBlack(right), 'cropped JPEG does not show the framed area');

  // Reopening shows the full capture with the frame; reset crop sends the full size
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  assert(await page.evaluate(() => document.querySelector('.opinion-annotation__canvas').width) === full.width, 'editor not on the full capture');
  assert(await page.isEnabled('[aria-label="[annotationResetCrop]"]'), 'crop not restored');
  await page.click('[aria-label="[annotationResetCrop]"]');
  await closeEditor(page, () => page.click('text=[annotationApply]'));
  const reset = await imageSize(page, await page.getAttribute('.opinion-dialog__preview', 'src'));
  assert(reset.width === full.width && reset.height === full.height, 'reset crop did not restore the full size');
});

await scenario('screenshot annotation: text in the chosen colour, Escape keeps the editor', ['--auto-accept-this-tab-capture'], async (page) => {
  await page.click('text=[screenshotTake]');
  await page.waitForSelector('.opinion-dialog__preview-wrapper:not([hidden])', { timeout: 10000 });
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.click('[aria-label="[annotationText]"]');
  await page.click('[data-color=yellow]');
  const box = await page.locator('.opinion-annotation__canvas').boundingBox();

  // Escape discards the text, the editor stays open
  await page.mouse.click(box.x + box.width * 0.2, box.y + box.height * 0.2);
  await page.waitForSelector('.opinion-annotation__text');
  assert(await page.getAttribute('.opinion-annotation__text', 'maxlength') === '200', 'no length limit');
  await page.keyboard.type('discard me');
  await page.keyboard.press('Escape');
  assert(await page.locator('dialog.opinion-annotation[open]').count() === 1, 'Escape closed the editor');
  assert(await page.locator('.opinion-annotation__text').count() === 0, 'text field still open');
  assert(await page.isDisabled('[aria-label="[annotationUndo]"]'), 'discarded text was placed');

  // An empty text places nothing
  await page.mouse.click(box.x + box.width * 0.2, box.y + box.height * 0.2);
  await page.keyboard.press('Enter');
  assert(await page.isDisabled('[aria-label="[annotationUndo]"]'), 'empty text was placed');

  const textBox = [0.3, 0.6, 0.6, 0.75];
  assert(await countPixels(page, textBox, isYellow) === 0, 'yellow before the text');
  await page.mouse.click(box.x + box.width * 0.3, box.y + box.height * 0.6);
  await page.keyboard.type('should be blue');
  await page.keyboard.press('Enter');
  assert(await page.isEnabled('[aria-label="[annotationUndo]"]'), 'text not placed');
  assert(await countPixels(page, textBox, isYellow) > 50, 'text not drawn in yellow');
  await closeEditor(page, () => page.click('text=[annotationApply]'));

  const annotated = await page.getAttribute('.opinion-dialog__preview', 'src');
  const yellowInJpeg = await page.evaluate(async ({ source, box }) => {
    const image = new Image(); image.src = source; await image.decode();
    const c = document.createElement('canvas'); c.width = image.width; c.height = image.height;
    const ctx = c.getContext('2d'); ctx.drawImage(image, 0, 0);
    const x = Math.round(image.width * box[0]); const y = Math.round(image.height * box[1]);
    const data = ctx.getImageData(x, y, Math.round(image.width * box[2]) - x, Math.round(image.height * box[3]) - y).data;
    let count = 0;
    for (let i = 0; i < data.length; i += 4) if (data[i] > 200 && data[i + 1] > 170 && data[i + 2] < 110) count++;
    return count;
  }, { source: annotated, box: textBox });
  assert(yellowInJpeg > 50, 'text not burnt into the JPEG');

  // Placed text is undone like any other shape
  await page.click('text=[annotate]');
  await page.waitForSelector('dialog.opinion-annotation[open]');
  await page.keyboard.press('Control+z');
  assert(await countPixels(page, textBox, isYellow) === 0, 'undo did not remove the text');
});

await scenario('backend: unread count on the module menu entry, also after it is re-rendered', [], async (page) => {
  await page.route(`${BASE}/backend.html`, (route) => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body>
<nav id="modulemenu"><a data-modulemenu-identifier="web_layout">Page</a><a data-modulemenu-identifier="opinion_feedback"><span>Feedback</span></a></nav>
<button data-opinion-toolbar-config="{}" data-opinion-unread="2" data-opinion-unread-label="2 feedback entries with new messages">Send feedback</button>
<script type="module" src="/JavaScript/toolbar-item.js"></script></body></html>` }));
  await page.goto(`${BASE}/backend.html`);
  const badge = '[data-modulemenu-identifier=opinion_feedback] .opinion-modulemenu-badge';
  await page.waitForSelector(badge);
  assert(await page.textContent(badge) === '2', 'badge count');
  assert(await page.getAttribute(badge, 'aria-hidden') === 'true', 'badge not hidden from screen readers');
  assert(await page.textContent('[data-modulemenu-identifier=opinion_feedback] .visually-hidden') === '2 feedback entries with new messages', 'label for screen readers');
  assert(await page.locator('.opinion-modulemenu-badge').count() === 1, 'badge on another entry');
  // The backend replaces the menu, the badge comes back once
  await page.evaluate(() => { document.getElementById('modulemenu').innerHTML = '<a data-modulemenu-identifier="opinion_feedback"><span>Feedback</span></a>'; });
  await page.waitForSelector(badge);
  assert(await page.locator(badge).count() === 1, 'badge added twice');
});

await scenario('backend: the toolbar sends the module at the end of a recording', ['--auto-accept-this-tab-capture'], async (page) => {
  const toolbarConfig = {
    ...config,
    source: 'backend',
    context: {},
    collect: { client: [], context: ['module', 'page', 'url'] },
    captureKinds: ['screenshot', 'video'],
    video: { maxDuration: 60, uploadLimit: 10 * 1024 * 1024 },
    labels: { title: 'Send feedback', captureVideo: 'Video', captureScreenshot: 'Screenshot', videoStart: 'Start recording', videoStop: 'Stop', videoTimeLeft: '{time}', send: 'Send', charactersLeft: '{count}' },
  };
  const moduleHtml = (module) => `<!doctype html><div class="module" data-module-name="${module}">${module}</div>`;
  await page.route(`${BASE}/typo3/module/**`, (route) => route.fulfill({ contentType: 'text/html', body: moduleHtml(new URL(route.request().url()).pathname.split('/').pop()) }));
  await page.route(`${BASE}/backend.html`, (route) => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><link rel="stylesheet" href="/Css/opinion-dialog.css"></head><body>
<button data-opinion-toolbar-config='${JSON.stringify(toolbarConfig)}'>Send feedback</button>
<iframe id="typo3-contentIframe" src="/typo3/module/web_layout?id=1"></iframe>
<script type="module" src="/JavaScript/toolbar-item.js"></script></body></html>` }));
  const bodies = [];
  await mockSubmit(page, bodies);
  await page.goto(`${BASE}/backend.html`);
  await page.frameLocator('#typo3-contentIframe').locator('[data-module-name=web_layout]').waitFor();
  await page.click('text=Send feedback');
  await page.fill('textarea', 'The list loses the sorting.');
  await page.click('[role=switch]');
  await page.click('text=Start recording');
  await page.waitForSelector('.opinion-recording');
  await page.evaluate(() => { document.getElementById('typo3-contentIframe').src = '/typo3/module/web_list?id=2'; });
  await page.frameLocator('#typo3-contentIframe').locator('[data-module-name=web_list]').waitFor();
  await page.click('.opinion-recording__stop');
  await page.waitForSelector('dialog.opinion-dialog[open] video.opinion-dialog__preview:not([hidden])', { timeout: 10000 });
  await page.click('button[type=submit]');
  await page.waitForFunction(() => document.querySelector('.opinion-dialog__status')?.textContent === 'ok');
  const field = (name) => JSON.parse(bodies[0].match(new RegExp(`name="${name}"\\r\\n\\r\\n(.*)\\r\\n`))?.[1] ?? 'null');
  assert(JSON.stringify(field('context')) === JSON.stringify({ url: '/typo3/module/web_layout?id=1', pageUid: '1', module: 'web_layout' }), 'start context wrong: ' + JSON.stringify(field('context')));
  assert(JSON.stringify(field('contextAtEnd')) === JSON.stringify({ url: '/typo3/module/web_list?id=2', pageUid: '2', module: 'web_list' }), 'end context wrong: ' + JSON.stringify(field('contextAtEnd')));
});

await scenario('backend: no badge without unread messages', [], async (page) => {
  await page.route(`${BASE}/backend.html`, (route) => route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body>
<nav id="modulemenu"><a data-modulemenu-identifier="opinion_feedback"><span>Feedback</span></a></nav>
<button data-opinion-toolbar-config="{}" data-opinion-unread="0" data-opinion-unread-label="0 feedback entries with new messages">Send feedback</button>
<script type="module" src="/JavaScript/toolbar-item.js"></script><script type="module">window.loaded = true;</script></body></html>` }));
  await page.goto(`${BASE}/backend.html`);
  await page.waitForFunction(() => window.loaded);
  await page.waitForTimeout(200);
  assert(await page.locator('.opinion-modulemenu-badge').count() === 0, 'badge shown for 0');
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
