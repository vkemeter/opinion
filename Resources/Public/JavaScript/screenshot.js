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
    await video.play();
    await new Promise((resolve) => window.setTimeout(resolve, SETTLE_DELAY_MS));

    const scale = Math.min(1, MAX_WIDTH / video.videoWidth);
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(video.videoWidth * scale);
    canvas.height = Math.round(video.videoHeight * scale);
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    video.srcObject = null;

    return await new Promise((resolve, reject) => {
      canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new Error('Screenshot could not be encoded'))), 'image/jpeg', JPEG_QUALITY);
    });
  } finally {
    for (const track of stream.getTracks()) {
      track.stop();
    }
  }
}

export function blobToDataUrl(blob) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.addEventListener('load', () => resolve(reader.result));
    reader.addEventListener('error', () => reject(reader.error));
    reader.readAsDataURL(blob);
  });
}
