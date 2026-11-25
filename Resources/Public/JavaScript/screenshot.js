const MAX_WIDTH = 2560;
const JPEG_QUALITY = 0.85;
// Time for the browser to remove its picker and the hidden dialog from the captured frame
const SETTLE_DELAY_MS = 300;

export function isScreenshotSupported() {
  return window.isSecureContext && typeof navigator.mediaDevices?.getDisplayMedia === 'function';
}

/**
 * Captures one frame of the current tab and returns it as JPEG blob.
 * Throws a DOMException "NotAllowedError" when the user cancels or denies.
 *
 * @returns {Promise<Blob>}
 */
export async function captureScreenshot() {
  const stream = await navigator.mediaDevices.getDisplayMedia({
    video: { displaySurface: 'browser' },
    audio: false,
    preferCurrentTab: true,
    selfBrowserSurface: 'include',
    surfaceSwitching: 'exclude',
  });

  try {
    const video = document.createElement('video');
    video.muted = true;
    video.playsInline = true;
    video.srcObject = stream;
    await playWithRepaints(video);
    await new Promise((resolve) => window.setTimeout(resolve, SETTLE_DELAY_MS));

    const scale = Math.min(1, MAX_WIDTH / video.videoWidth);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    video.srcObject = null;

    return await canvasToJpeg(canvas);
  } finally {
    for (const track of stream.getTracks()) {
      track.stop();
    }
  }
}

/**
 * Chrome delivers a frame of the tab only when the page repaints; with the dialog hidden a static
 * page may never repaint. One pixel changes invisibly (1–2 % black) until the first frame arrives.
 *
 * @param {HTMLVideoElement} video
 */
async function playWithRepaints(video) {
  const pixel = document.createElement('div');
  pixel.style.cssText = 'position:fixed;top:0;left:0;width:1px;height:1px;pointer-events:none;z-index:2147483647';
  document.body.append(pixel);
  let tick = 0;
  const interval = window.setInterval(() => {
    pixel.style.background = `rgb(0 0 0 / ${++tick % 2 + 1}%)`;
  }, 100);
  try {
    await video.play();
  } finally {
    window.clearInterval(interval);
    pixel.remove();
  }
}

/**
 * @param {HTMLCanvasElement} canvas
 * @returns {Promise<Blob>}
 */
export function canvasToJpeg(canvas) {
  return new Promise((resolve, reject) => {
    canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Screenshot could not be encoded'))), 'image/jpeg', JPEG_QUALITY);
  });
}

export function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener('load', () => resolve(reader.result));
    reader.addEventListener('error', () => reject(reader.error));
    reader.readAsDataURL(blob);
  });
}
