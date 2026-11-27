import { flattenAnnotations, sentPins } from './annotation.js';
import { openAnnotationEditor } from './annotation-editor.js';
import { el } from './dom.js';
import { icon } from './icons.js';
import { isRecordingSupported, recordTab } from './recording.js';
import { blobToDataUrl, captureScreenshot, isScreenshotSupported } from './screenshot.js';

/**
 * Take / preview / mark up / retake / remove controls for the capture of the dialog: a screenshot or,
 * when offered, a video of the tab. One capture per feedback; a new one of another kind replaces it.
 */
export class ScreenshotControl {
  /**
   * @param {object} options
   * @param {string[]} [options.captureKinds] 'screenshot' and optionally 'video'
   * @param {{maxDuration: number, uploadLimit: number}|null} [options.video] limits of the recording
   * @param {() => void} options.beforeRecording closes the dialog, so the page can be used
   * @param {() => void} options.afterRecording opens the dialog again
   * @param {() => object} [options.collectContextAtEnd] context when the recording stops (backend)
   */
  constructor({ labels, id, captureKinds = ['screenshot'], video = null, beforeCapture, afterCapture, beforeRecording, afterRecording, collectContextAtEnd }) {
    this.labels = labels;
    this.id = id;
    this.kinds = captureKinds.filter((kind) => kind === 'screenshot' || (kind === 'video' && video && isRecordingSupported()));
    this.video = video;
    this.beforeCapture = beforeCapture;
    this.afterCapture = afterCapture;
    this.beforeRecording = beforeRecording;
    this.afterRecording = afterRecording;
    this.collectContextAtEnd = collectContextAtEnd;
    this.kind = 'screenshot';
    // The capture as taken, the shapes drawn on it and the image that is sent (capture with shapes burnt in)
    this.originalBlob = null;
    this.shapes = [];
    this.crop = null;
    this.blob = null;
    this.blobKind = null;
    this.contextAtEnd = null;
    this.element = this.render();
  }

  /**
   * @returns {{field: string, blob: Blob, filename: string, contextAtEnd: object|null, pins: Array<{number: number, comment: string}>}|null}
   */
  getCapture() {
    if (!this.blob) {
      return null;
    }
    const filename = this.blobKind === 'video' ? `video.${this.blob.type === 'video/mp4' ? 'mp4' : 'webm'}` : 'screenshot.jpg';
    return { field: this.blobKind, blob: this.blob, filename, contextAtEnd: this.contextAtEnd, pins: this.blobKind === 'screenshot' ? sentPins(this.shapes, this.crop) : [] };
  }

  reset() {
    this.originalBlob = null;
    this.shapes = [];
    this.crop = null;
    this.blob = null;
    this.blobKind = null;
    this.contextAtEnd = null;
    this.preview.removeAttribute('src');
    this.clearVideoPreview();
    this.renderPins();
    this.update();
  }

  // The comments that are sent, numbered like the badges on the image
  renderPins() {
    const pins = sentPins(this.shapes, this.crop);
    this.pinList.replaceChildren(...pins.map(({ number, comment }) => el('li', { value: String(number) }, comment)));
    this.pinSection.hidden = pins.length === 0;
  }

  clearVideoPreview() {
    if (this.videoPreview.src) {
      URL.revokeObjectURL(this.videoPreview.src);
      this.videoPreview.removeAttribute('src');
    }
  }

  render() {
    this.takeButton = this.captureButton();
    this.annotateButton = el('button', { class: 'opinion-dialog__button opinion-dialog__button--capture', type: 'button' }, [icon('markUp'), this.labels.annotate]);
    this.retakeButton = this.captureButton();
    this.removeButton = el('button', { class: 'opinion-dialog__button opinion-dialog__button--capture', type: 'button' }, [icon('clear'), el('span', {}, this.labels.screenshotRemove)]);
    this.preview = el('img', { class: 'opinion-dialog__preview', alt: this.labels.screenshotPreview });
    this.videoPreview = el('video', { class: 'opinion-dialog__preview', controls: '', muted: '', preload: 'metadata', 'aria-label': this.labels.videoPreview });
    this.previewWrapper = el('div', { class: 'opinion-dialog__preview-wrapper' }, [this.preview, this.videoPreview]);
    this.hint = el('p', { class: 'opinion-dialog__hint', id: `${this.id}-hint` }, this.labels.screenshotHint);
    this.videoNotice = el('p', { class: 'opinion-dialog__hint opinion-dialog__hint--warning', id: `${this.id}-video-hint` }, this.labels.videoNotice);
    this.notice = el('p', { class: 'opinion-dialog__notice', role: 'status', 'aria-live': 'polite' });
    this.pinList = el('ol', { class: 'opinion-dialog__pins', 'aria-labelledby': `${this.id}-pins-title` });
    this.pinSection = el('div', { hidden: '' }, [
      el('p', { class: 'opinion-dialog__pins-title', id: `${this.id}-pins-title` }, this.labels.pinComments),
      this.pinList,
      el('p', { class: 'opinion-dialog__error', id: `${this.id}-pins-error`, 'data-error-for': 'pins', hidden: '' }),
    ]);

    this.annotateButton.addEventListener('click', () => this.annotate());
    this.removeButton.addEventListener('click', () => {
      this.reset();
      this.takeButton.focus();
    });

    // Buttons first: below a large preview they are easily missed on small screens
    const container = el('div', { class: 'opinion-dialog__screenshot' }, [
      el('div', { class: 'opinion-dialog__actions' }, [
        ...(this.kinds.length > 1 ? [this.renderChoice()] : []),
        this.takeButton,
        this.annotateButton,
        this.retakeButton,
        this.removeButton,
      ]),
      this.hint,
      this.videoNotice,
      this.notice,
      this.previewWrapper,
      this.pinSection,
    ]);

    if (!isScreenshotSupported()) {
      this.takeButton.disabled = true;
      this.hint.hidden = true;
      this.notice.textContent = this.labels.screenshotUnsupported;
    }
    this.update();

    return container;
  }

  /**
   * Switch between screenshot (off) and video (on), with the icon of the selected kind on the knob.
   * Changing the kind alone keeps an existing capture.
   */
  renderChoice() {
    const toggle = el('button', { class: 'opinion-switch', type: 'button', role: 'switch', 'aria-checked': 'false', 'aria-label': this.labels.captureVideo, title: this.labels.capture }, [
      el('span', { class: 'opinion-switch__track' }, [icon('camera'), icon('video')]),
      el('span', { class: 'opinion-switch__thumb' }, [icon('camera'), icon('video')]),
    ]);
    toggle.addEventListener('click', () => {
      this.kind = this.kind === 'video' ? 'screenshot' : 'video';
      toggle.setAttribute('aria-checked', String(this.kind === 'video'));
      this.update();
    });
    return toggle;
  }

  /**
   * Take and retake button: icon of the kind it captures and its text.
   */
  captureButton() {
    const button = el('button', { class: 'opinion-dialog__button opinion-dialog__button--capture', type: 'button' }, [
      el('span', { class: 'opinion-dialog__button-icon' }, [icon('camera'), el('span', { class: 'opinion-dialog__record-dot' })]),
      el('span', {}, ''),
    ]);
    button.addEventListener('click', () => this.captureSelected());
    return button;
  }

  setCaptureButton(button, kind, text) {
    button.dataset.kind = kind;
    button.lastChild.textContent = text;
  }

  captureSelected() {
    return this.kind === 'video' ? this.record() : this.capture();
  }

  async record() {
    this.notice.textContent = '';
    this.beforeRecording();
    try {
      const blob = await recordTab({ ...this.video, labels: this.labels });
      if (blob.size === 0) {
        throw new Error('Empty recording');
      }
      this.reset();
      this.blob = blob;
      this.blobKind = 'video';
      this.contextAtEnd = this.collectContextAtEnd?.() ?? null;
      this.videoPreview.src = URL.createObjectURL(blob);
    } catch (error) {
      this.notice.textContent = error?.name === 'NotAllowedError' || error?.name === 'AbortError' ? this.labels.videoDenied : this.labels.videoFailed;
    } finally {
      this.afterRecording();
      this.update();
      (this.blob ? this.retakeButton : this.takeButton).focus();
    }
  }

  async capture() {
    this.notice.textContent = '';
    this.beforeCapture();
    try {
      const blob = await captureScreenshot();
      this.reset();
      this.originalBlob = blob;
      this.blob = blob;
      this.blobKind = 'screenshot';
      this.preview.src = await blobToDataUrl(blob);
    } catch (error) {
      this.notice.textContent = error?.name === 'NotAllowedError' || error?.name === 'AbortError' ? this.labels.screenshotDenied : this.labels.screenshotFailed;
    } finally {
      this.afterCapture();
      this.update();
      (this.blob ? this.retakeButton : this.takeButton).focus();
    }
  }

  async annotate() {
    this.notice.textContent = '';
    let image = null;
    try {
      image = await createImageBitmap(this.originalBlob);
      const result = await openAnnotationEditor({ image, shapes: this.shapes, crop: this.crop, labels: this.labels, id: `${this.id}-annotation` });
      if (result !== null) {
        const { shapes, crop } = result;
        this.blob = shapes.length > 0 || crop ? await flattenAnnotations(image, shapes, crop) : this.originalBlob;
        this.shapes = shapes;
        this.crop = crop;
        this.preview.src = await blobToDataUrl(this.blob);
        this.renderPins();
      }
    } catch {
      this.notice.textContent = this.labels.annotationFailed;
    } finally {
      image?.close();
      this.annotateButton.focus();
    }
  }

  update() {
    const hasCapture = this.blob !== null;
    const video = this.kind === 'video';
    const takeLabel = video ? this.labels.videoStart : this.labels.screenshotTake;
    this.previewWrapper.hidden = !hasCapture;
    this.preview.hidden = this.blobKind !== 'screenshot';
    this.videoPreview.hidden = this.blobKind !== 'video';
    this.takeButton.hidden = hasCapture;
    this.setCaptureButton(this.takeButton, this.kind, takeLabel);
    this.takeButton.disabled = video ? false : !isScreenshotSupported();
    this.takeButton.setAttribute('aria-describedby', `${this.id}-${video ? 'video-hint' : 'hint'}`);
    this.annotateButton.hidden = this.blobKind !== 'screenshot' || typeof window.createImageBitmap !== 'function';
    this.retakeButton.hidden = !hasCapture;
    // Retakes the capture, or replaces it by one of the other selected kind
    this.setCaptureButton(this.retakeButton, this.kind, this.kind !== this.blobKind ? takeLabel : video ? this.labels.videoRetake : this.labels.screenshotRetake);
    this.removeButton.hidden = !hasCapture;
    this.removeButton.lastChild.textContent = this.blobKind === 'video' ? this.labels.videoRemove : this.labels.screenshotRemove;
    this.hint.hidden = video || !isScreenshotSupported();
    this.videoNotice.hidden = !video;
  }
}
