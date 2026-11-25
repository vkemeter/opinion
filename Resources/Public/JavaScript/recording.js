import { el } from './dom.js';

// avc3 repeats the frame size in every key frame; with avc1 the MP4 breaks when the tab is resized
// during the recording. MP4 before WebM: WebM files have no duration, so the player has no seek bar.
const MIME_TYPES = ['video/mp4;codecs=avc3', 'video/webm;codecs=vp9', 'video/webm'];
const BITS_PER_SECOND = 1_000_000;
// Without regular key frames an MP4 of a page that does not change cannot be decoded
const KEY_FRAME_INTERVAL_MS = 1000;
const MAX_WIDTH = 1280;
const MARKER_DURATION_MS = 500;
// Chrome emits MP4 data in a few large chunks; stop before the next one could pass the limit
const SIZE_GUARD = 0.85;

export function isRecordingSupported() {
  return window.isSecureContext && typeof navigator.mediaDevices?.getDisplayMedia === 'function' && typeof window.MediaRecorder === 'function'
    && MIME_TYPES.some((type) => MediaRecorder.isTypeSupported(type));
}

/**
 * Records the current tab until the user stops, the maximum duration or the size guard is reached,
 * or the sharing is ended in the browser bar. Throws a DOMException "NotAllowedError" when the user
 * cancels the permission prompt.
 *
 * @param {{maxDuration: number, uploadLimit: number, labels: object}} options
 * @returns {Promise<Blob>}
 */
export async function recordTab({ maxDuration, uploadLimit, labels }) {
  const stream = await navigator.mediaDevices.getDisplayMedia({
    video: { displaySurface: 'browser', width: { max: MAX_WIDTH } },
    audio: false,
    preferCurrentTab: true,
    selfBrowserSurface: 'include',
    surfaceSwitching: 'exclude',
  });
  const cleanups = [() => stream.getTracks().forEach((track) => track.stop())];
  // Whatever fails, the tab must not stay shared
  try {
    const mimeType = MIME_TYPES.find((type) => MediaRecorder.isTypeSupported(type));
    const recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: BITS_PER_SECOND, videoKeyFrameIntervalDuration: KEY_FRAME_INTERVAL_MS });
    const chunks = [];
    let size = 0;
    const stop = () => recorder.state === 'recording' && recorder.stop();

    recorder.addEventListener('dataavailable', (event) => {
      chunks.push(event.data);
      size += event.data.size;
      if (size >= uploadLimit * SIZE_GUARD) {
        stop();
      }
    });
    stream.getVideoTracks()[0]?.addEventListener('ended', stop);

    const confirmLeave = (event) => event.preventDefault();
    window.addEventListener('beforeunload', confirmLeave);
    cleanups.push(showBar(labels, maxDuration, stop), showClickMarkers(), () => window.removeEventListener('beforeunload', confirmLeave));

    const stopped = new Promise((resolve) => recorder.addEventListener('stop', resolve, { once: true }));
    recorder.start(1000);
    await stopped;

    return new Blob(chunks, { type: recorder.mimeType.split(';')[0] });
  } finally {
    for (const cleanup of cleanups) {
      cleanup();
    }
  }
}

/**
 * The floating bar with the remaining time and "Stop"; it stops the recording at the maximum duration.
 *
 * @returns {() => void} removes the bar
 */
function showBar(labels, maxDuration, stop) {
  const time = el('span', { class: 'opinion-recording__time', role: 'timer' });
  const stopButton = el('button', { class: 'opinion-recording__stop', type: 'button' }, labels.videoStop);
  stopButton.addEventListener('click', stop);
  const bar = el('div', { class: 'opinion-recording', role: 'region', 'aria-label': labels.videoRecording }, [
    el('span', { class: 'opinion-recording__dot', 'aria-hidden': 'true' }),
    el('span', {}, labels.videoRecording),
    time,
    stopButton,
  ]);

  const end = Date.now() + maxDuration * 1000;
  const tick = () => {
    const left = Math.max(0, Math.ceil((end - Date.now()) / 1000));
    time.textContent = labels.videoTimeLeft.replace('{time}', `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`);
    if (left === 0) {
      stop();
    }
  };
  tick();
  const interval = window.setInterval(tick, 250);

  document.body.append(bar);
  stopButton.focus();
  return () => {
    window.clearInterval(interval);
    bar.remove();
  };
}

/**
 * Shows a short circle at every click, in the document and in same-origin frames (the backend module
 * frame), so the clicks are visible in the video. Frames are watched again after every load.
 *
 * @returns {() => void} removes the listeners
 */
function showClickMarkers() {
  const documents = new Set();
  const listen = (doc) => {
    if (doc && !documents.has(doc)) {
      doc.addEventListener('pointerdown', showMarker, true);
      documents.add(doc);
    }
  };
  const listenToFrame = (frame) => {
    try {
      listen(frame.contentDocument);
    } catch {
      // Frames of another origin cannot be watched
    }
  };
  const onFrameLoad = (event) => listenToFrame(event.target);
  const frames = Array.from(document.querySelectorAll('iframe'));

  listen(document);
  for (const frame of frames) {
    listenToFrame(frame);
    frame.addEventListener('load', onFrameLoad);
  }

  return () => {
    for (const doc of documents) {
      doc.removeEventListener('pointerdown', showMarker, true);
    }
    for (const frame of frames) {
      frame.removeEventListener('load', onFrameLoad);
    }
  };
}

function showMarker(event) {
  const doc = event.target?.ownerDocument;
  if (!doc?.body || event.target.closest?.('.opinion-recording')) {
    return;
  }
  // Inline styles: the stylesheet of the dialog is not loaded in the module frame
  const marker = doc.createElement('div');
  marker.className = 'opinion-click-marker';
  marker.setAttribute('aria-hidden', 'true');
  marker.style.cssText = `position:fixed;left:${event.clientX - 18}px;top:${event.clientY - 18}px;width:36px;height:36px;`
    + 'border:4px solid #e00;border-radius:50%;background:rgb(238 0 0 / 25%);pointer-events:none;z-index:2147483647';
  doc.body.append(marker);
  window.setTimeout(() => marker.remove(), MARKER_DURATION_MS);
}
