import { flattenAnnotations } from './annotation.js';
import { openAnnotationEditor } from './annotation-editor.js';
import { el } from './dom.js';
import { blobToDataUrl, captureScreenshot, isScreenshotSupported } from './screenshot.js';

/**
 * Take / preview / mark up / retake / remove controls for the screenshot of the dialog.
 */
export class ScreenshotControl {
  constructor({ labels, id, beforeCapture, afterCapture }) {
    this.labels = labels;
    this.id = id;
    this.beforeCapture = beforeCapture;
    this.afterCapture = afterCapture;
    // The capture as taken, the shapes drawn on it and the image that is sent (capture with shapes burnt in)
    this.originalBlob = null;
    this.shapes = [];
    this.blob = null;
    this.element = this.render();
  }

  getBlob() {
    return this.blob;
  }

  reset() {
    this.originalBlob = null;
    this.shapes = [];
    this.blob = null;
    this.preview.removeAttribute('src');
    this.update();
  }

  render() {
    this.takeButton = el('button', { class: 'opinion-dialog__button', type: 'button', 'aria-describedby': `${this.id}-hint` }, this.labels.screenshotTake);
    this.annotateButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.annotate);
    this.retakeButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.screenshotRetake);
    this.removeButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.screenshotRemove);
    this.preview = el('img', { class: 'opinion-dialog__preview', alt: this.labels.screenshotPreview });
    this.previewWrapper = el('div', { class: 'opinion-dialog__preview-wrapper' }, [this.preview]);
    this.hint = el('p', { class: 'opinion-dialog__hint', id: `${this.id}-hint` }, this.labels.screenshotHint);
    this.notice = el('p', { class: 'opinion-dialog__notice', role: 'status', 'aria-live': 'polite' });

    this.takeButton.addEventListener('click', () => this.capture());
    this.annotateButton.addEventListener('click', () => this.annotate());
    this.retakeButton.addEventListener('click', () => this.capture());
    this.removeButton.addEventListener('click', () => {
      this.reset();
      this.takeButton.focus();
    });

    const container = el('div', { class: 'opinion-dialog__screenshot' }, [
      this.previewWrapper,
      el('div', { class: 'opinion-dialog__actions' }, [this.takeButton, this.annotateButton, this.retakeButton, this.removeButton]),
      this.hint,
      this.notice,
    ]);

    if (!isScreenshotSupported()) {
      this.takeButton.disabled = true;
      this.hint.hidden = true;
      this.notice.textContent = this.labels.screenshotUnsupported;
    }
    this.update();

    return container;
  }

  async capture() {
    this.notice.textContent = '';
    this.beforeCapture();
    try {
      const blob = await captureScreenshot();
      this.originalBlob = blob;
      this.shapes = [];
      this.blob = blob;
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
      const shapes = await openAnnotationEditor({ image, shapes: this.shapes, labels: this.labels, id: `${this.id}-annotation` });
      if (shapes !== null) {
        this.blob = shapes.length > 0 ? await flattenAnnotations(image, shapes) : this.originalBlob;
        this.shapes = shapes;
        this.preview.src = await blobToDataUrl(this.blob);
      }
    } catch {
      this.notice.textContent = this.labels.annotationFailed;
    } finally {
      image?.close();
      this.annotateButton.focus();
    }
  }

  update() {
    const hasScreenshot = this.blob !== null;
    this.previewWrapper.hidden = !hasScreenshot;
    this.takeButton.hidden = hasScreenshot;
    this.annotateButton.hidden = !hasScreenshot || typeof window.createImageBitmap !== 'function';
    this.retakeButton.hidden = !hasScreenshot;
    this.removeButton.hidden = !hasScreenshot;
  }
}
