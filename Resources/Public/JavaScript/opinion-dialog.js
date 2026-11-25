import { collectClientInfo } from './client-info.js';
import { el } from './dom.js';
import { icon } from './icons.js';
import { ScreenshotControl } from './screenshot-control.js';

// Generous, the request can carry a screenshot of up to 10 MB or a video of up to about 48 MB
const SUBMIT_TIMEOUT_MS = 60000;
const VIDEO_SUBMIT_TIMEOUT_MS = 300000;

/**
 * Opens the feedback dialog. The configuration is built on the server by
 * \Vkemeter\Opinion\Dialog\DialogConfigurationBuilder.
 *
 * @param {object} config
 * @returns {HTMLDialogElement}
 */
export function openOpinionDialog(config) {
  if (config.unavailable) {
    return openUnavailableNotice(config);
  }
  const dialog = new OpinionDialog(config);
  dialog.open();
  return dialog.element;
}

let instanceCounter = 0;

/**
 * Header with title, the intro of the form and the close button (×) that closes the dialog like Cancel.
 */
function dialogHeader(dialog, labels, id, withIntro) {
  const closeButton = el('button', { class: 'opinion-dialog__close', type: 'button', 'aria-label': labels.close, title: labels.close }, [icon('cancel')]);
  closeButton.addEventListener('click', () => dialog.close());
  return el('div', { class: 'opinion-dialog__header' }, [
    el('div', { class: 'opinion-dialog__heading' }, [
      el('h2', { class: 'opinion-dialog__title', id: `${id}-title` }, labels.title),
      ...(withIntro ? [el('p', { class: 'opinion-dialog__intro' }, labels.intro)] : []),
    ]),
    closeButton,
  ]);
}

/**
 * Tells users who cannot send feedback (no email address) why, instead of the form.
 */
function openUnavailableNotice(config) {
  const id = `opinion-dialog-${++instanceCounter}`;
  const dialog = el('dialog', { class: 'opinion-dialog', 'aria-labelledby': `${id}-title`, 'aria-describedby': `${id}-message` });
  const closeButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, config.labels.close);
  closeButton.addEventListener('click', () => dialog.close());
  const settingsLink = el(
    'a',
    { class: 'opinion-dialog__button opinion-dialog__button--primary', href: config.unavailable.settingsUrl, target: '_top' },
    config.unavailable.settingsLabel,
  );
  dialog.append(
    el('div', { class: 'opinion-dialog__form' }, [
      dialogHeader(dialog, config.labels, id, false),
      el('div', { class: 'opinion-dialog__body' }, [
        el('p', { class: 'opinion-dialog__status opinion-dialog__status--error', id: `${id}-message` }, config.unavailable.message),
      ]),
      el('div', { class: 'opinion-dialog__footer' }, [closeButton, settingsLink]),
    ]),
  );
  dialog.addEventListener('close', () => dialog.remove(), { once: true });
  document.body.append(dialog);
  dialog.showModal();
  settingsLink.focus();
  return dialog;
}

class OpinionDialog {
  constructor(config) {
    this.config = config;
    this.labels = config.labels;
    this.id = `opinion-dialog-${++instanceCounter}`;
    this.element = this.render();
  }

  open() {
    document.body.append(this.element);
    // While recording the dialog is closed with its input kept, see reopen()
    this.element.addEventListener('close', () => this.recording || this.destroy());
    this.element.showModal();
    this.message.focus();
  }

  reopen() {
    this.recording = false;
    this.element.showModal();
  }

  destroy() {
    this.screenshotControl.reset();
    this.element.remove();
  }

  render() {
    const dialog = el('dialog', { class: 'opinion-dialog', 'aria-labelledby': `${this.id}-title` });
    this.form = el('form', { class: 'opinion-dialog__form', novalidate: '' });
    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.submit();
    });

    const header = dialogHeader(dialog, this.labels, this.id, true);

    this.message = el('textarea', {
      class: 'opinion-dialog__textarea',
      id: `${this.id}-message`,
      name: 'message',
      rows: '6',
      required: '',
      minlength: String(this.config.messageMinLength),
      maxlength: String(this.config.messageMaxLength),
      'aria-describedby': `${this.id}-message-hint ${this.id}-message-counter ${this.id}-message-error`,
    });
    // No live region: it would announce every keystroke, the textarea references the counter instead
    this.counter = el('span', { class: 'opinion-dialog__counter', id: `${this.id}-message-counter` });
    this.message.addEventListener('input', () => this.updateCounter());
    this.updateCounter();

    const messageField = this.field('message', this.labels.message, this.message, [
      el('span', { class: 'opinion-dialog__hint', id: `${this.id}-message-hint` }, this.labels.messageHint),
      this.counter,
    ]);

    this.category = this.select('category', this.config.categories, this.config.categories[0]?.value);
    this.severity = this.select('severity', this.config.severities, this.config.defaultSeverity);
    const selects = el('div', { class: 'opinion-dialog__row' }, [
      this.field('category', this.labels.category, this.category),
      this.field('severity', this.labels.severity, this.severity),
    ]);

    this.screenshotControl = new ScreenshotControl({
      labels: this.labels,
      id: `${this.id}-screenshot`,
      captureKinds: this.config.captureKinds,
      video: this.config.video,
      beforeCapture: () => this.setCapturing(true),
      afterCapture: () => this.setCapturing(false),
      beforeRecording: () => {
        // A modal dialog makes the page inert
        this.recording = true;
        this.element.close();
      },
      afterRecording: () => this.reopen(),
      collectContextAtEnd: this.config.collectContextAtEnd,
    });
    const screenshotField = el('fieldset', { class: 'opinion-dialog__fieldset' }, [
      el('legend', { class: 'opinion-dialog__label' }, this.screenshotControl.kinds.length > 1 ? this.labels.capture : this.labels.screenshot),
      this.screenshotControl.element,
      this.errorElement('screenshot'),
      this.errorElement('video'),
    ]);

    this.status = el('p', { class: 'opinion-dialog__status', role: 'status', 'aria-live': 'polite' });

    this.sendButton = el('button', { class: 'opinion-dialog__button opinion-dialog__button--primary', type: 'submit' }, this.labels.send);
    this.cancelButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.cancel);
    this.cancelButton.addEventListener('click', () => this.element.close());
    this.footer = el('div', { class: 'opinion-dialog__footer' }, [this.cancelButton, this.sendButton]);
    // Only the body scrolls, title and buttons stay visible
    this.body = el('div', { class: 'opinion-dialog__body' }, [messageField, selects, screenshotField, this.status]);

    this.form.append(header, this.body, this.footer);
    dialog.append(this.form);
    return dialog;
  }

  setCapturing(capturing) {
    this.element.classList.toggle('opinion-dialog--capturing', capturing);
    document.documentElement.classList.toggle('opinion-capturing', capturing);
  }

  field(name, label, control, extra = []) {
    return el('div', { class: 'opinion-dialog__field' }, [
      el('label', { class: 'opinion-dialog__label', for: control.id }, label),
      control,
      ...extra,
      this.errorElement(name),
    ]);
  }

  select(name, options, selected) {
    const select = el('select', { class: 'opinion-dialog__select', id: `${this.id}-${name}`, name, 'aria-describedby': `${this.id}-${name}-error` });
    for (const option of options) {
      const optionElement = el('option', { value: option.value }, option.label);
      optionElement.selected = option.value === selected;
      select.append(optionElement);
    }
    return select;
  }

  errorElement(name) {
    return el('p', { class: 'opinion-dialog__error', id: `${this.id}-${name}-error`, 'data-error-for': name, hidden: '' });
  }

  updateCounter() {
    const left = this.config.messageMaxLength - this.message.value.length;
    this.counter.textContent = this.labels.charactersLeft.replace('{count}', String(left));
  }

  async submit() {
    this.clearErrors();
    const body = new FormData();
    body.append('message', this.message.value.trim());
    body.append('category', this.category.value);
    body.append('severity', this.severity.value);
    body.append('source', this.config.source);
    body.append('clientInfo', JSON.stringify(collectClientInfo(this.config.collect?.client)));
    body.append('context', JSON.stringify(this.config.context || {}));
    const capture = this.screenshotControl.getCapture();
    if (capture) {
      body.append(capture.field, capture.blob, capture.filename);
      if (capture.contextAtEnd) {
        body.append('contextAtEnd', JSON.stringify(capture.contextAtEnd));
      }
    }

    this.setBusy(true);
    try {
      const timeout = capture?.field === 'video' ? VIDEO_SUBMIT_TIMEOUT_MS : SUBMIT_TIMEOUT_MS;
      const response = await fetch(this.config.endpoint, { method: 'POST', body, credentials: 'same-origin', signal: AbortSignal.timeout(timeout) });
      if (response.status === 401) {
        this.showStatus(this.labels.sessionExpired, true);
        return;
      }
      const result = await response.json().catch(() => ({}));
      if (response.ok && result.success) {
        this.showSuccess(result.message);
        return;
      }
      if (response.status === 422 && result.errors) {
        this.showErrors(result.errors);
      }
      this.showStatus(result.message || this.labels.error, true);
    } catch (error) {
      this.showStatus(error?.name === 'TimeoutError' ? this.labels.timeout : this.labels.error, true);
    } finally {
      this.setBusy(false);
    }
  }

  setBusy(busy) {
    this.form.setAttribute('aria-busy', String(busy));
    this.sendButton.disabled = busy;
    this.sendButton.textContent = busy ? this.labels.sending : this.labels.send;
  }

  showSuccess(message) {
    for (const child of Array.from(this.body.children)) {
      child.hidden = child !== this.status;
    }
    this.footer.hidden = true;
    this.form.querySelector('.opinion-dialog__intro').hidden = true;
    this.showStatus(message, false);
    const closeButton = el('button', { class: 'opinion-dialog__button opinion-dialog__button--primary', type: 'button' }, this.labels.close);
    closeButton.addEventListener('click', () => this.element.close());
    this.form.append(el('div', { class: 'opinion-dialog__footer' }, [closeButton]));
    closeButton.focus();
  }

  showStatus(message, isError) {
    this.status.textContent = message;
    this.status.classList.toggle('opinion-dialog__status--error', isError);
    this.status.classList.toggle('opinion-dialog__status--success', !isError);
  }

  showErrors(errors) {
    let focused = false;
    for (const [field, message] of Object.entries(errors)) {
      const errorElement = this.form.querySelector(`[data-error-for="${CSS.escape(field)}"]`);
      if (!errorElement) {
        continue;
      }
      errorElement.textContent = message;
      errorElement.hidden = false;
      const control = this.form.querySelector(`[name="${CSS.escape(field)}"]`);
      control?.setAttribute('aria-invalid', 'true');
      if (control && !focused) {
        control.focus();
        focused = true;
      }
    }
  }

  clearErrors() {
    this.showStatus('', false);
    for (const errorElement of this.form.querySelectorAll('[data-error-for]')) {
      errorElement.textContent = '';
      errorElement.hidden = true;
    }
    for (const control of this.form.querySelectorAll('[aria-invalid]')) {
      control.removeAttribute('aria-invalid');
    }
  }
}
