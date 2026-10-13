import { el } from './dom.js';
import { blobToDataUrl, captureScreenshot, isScreenshotSupported } from './screenshot.js';

/**
 * Take / preview / retake / remove controls for the screenshot of the dialog.
 */
export class ScreenshotControl {
  constructor({ labels, id, beforeCapture, afterCapture }) {
    this.labels = labels;
    this.id = id;
    this.beforeCapture = beforeCapture;
    this.afterCapture = afterCapture;
    this.blob = null;
    this.element = this.render();
  }

  getBlob() {
    return this.blob;
  }

  reset() {
    this.blob = null;
    this.preview.removeAttribute('src');
    this.update();
  }

  render() {
    this.takeButton = el('button', { class: 'opinion-dialog__button', type: 'button', 'aria-describedby': `${this.id}-hint` }, this.labels.screenshotTake);
    this.retakeButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.screenshotRetake);
    this.removeButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.screenshotRemove);
    this.preview = el('img', { class: 'opinion-dialog__preview', alt: this.labels.screenshotPreview });
    this.previewWrapper = el('div', { class: 'opinion-dialog__preview-wrapper' }, [this.preview]);
    this.hint = el('p', { class: 'opinion-dialog__hint', id: `${this.id}-hint` }, this.labels.screenshotHint);
    this.notice = el('p', { class: 'opinion-dialog__notice', role: 'status', 'aria-live': 'polite' });

    this.takeButton.addEventListener('click', () => this.capture());
    this.retakeButton.addEventListener('click', () => this.capture());
    this.removeButton.addEventListener('click', () => {
      this.reset();
      this.takeButton.focus();
    });

    const container = el('div', { class: 'opinion-dialog__screenshot' }, [
      this.previewWrapper,
      el('div', { class: 'opinion-dialog__actions' }, [this.takeButton, this.retakeButton, this.removeButton]),
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

  update() {
    const hasScreenshot = this.blob !== null;
    this.previewWrapper.hidden = !hasScreenshot;
    this.takeButton.hidden = hasScreenshot;
    this.retakeButton.hidden = !hasScreenshot;
    this.removeButton.hidden = !hasScreenshot;
  }
}
