import { COLORS, TOOLS, cropFromPoints, isShapeLargeEnough, renderAnnotations } from './annotation.js';
import { el } from './dom.js';
import { icon } from './icons.js';

const TOOL_LABELS = {
  rectangle: 'annotationRectangle',
  arrow: 'annotationArrow',
  text: 'annotationText',
  blackout: 'annotationBlackout',
  crop: 'annotationCrop',
};
const COLOR_LABELS = { red: 'annotationColorRed', yellow: 'annotationColorYellow', blue: 'annotationColorBlue' };
// Tools that are always drawn the same way, so the colour buttons do not apply
const COLORLESS_TOOLS = ['blackout', 'crop'];
const CROP_DIM_COLOR = 'rgb(0 0 0 / 55%)';
const CROP_FRAME_COLOR = '#fff';
const TEXT_MAX_LENGTH = 200;

/**
 * Opens the full-screen editor above the feedback dialog.
 * Resolves with the new shapes and crop on apply, or null on cancel.
 *
 * @param {{ image: ImageBitmap, shapes: Array<object>, crop: object|null, labels: object, id: string }} options
 * @returns {Promise<{ shapes: Array<object>, crop: object|null }|null>}
 */
export function openAnnotationEditor(options) {
  return new AnnotationEditor(options).open();
}

/**
 * A small button showing only an icon; the label is its accessible name and tooltip.
 */
function iconButton(iconName, label, attributes = {}) {
  const { class: className = '', ...rest } = attributes;
  return el('button', { class: `opinion-dialog__button opinion-annotation__button opinion-annotation__button--icon ${className}`.trim(), type: 'button', 'aria-label': label, title: label, ...rest }, [icon(iconName)]);
}

class AnnotationEditor {
  constructor({ image, shapes, crop = null, labels, id }) {
    this.image = image;
    this.shapes = [...shapes];
    this.crop = crop;
    this.labels = labels;
    this.id = id;
    this.tool = TOOLS[0];
    this.color = Object.keys(COLORS)[0];
    this.draft = null;
    this.textInput = null;
    this.result = null;
    this.renderScheduled = false;
    this.element = this.render();
  }

  open() {
    return new Promise((resolve) => {
      this.element.addEventListener(
        'close',
        () => {
          this.element.remove();
          resolve(this.result);
        },
        { once: true },
      );
      document.body.append(this.element);
      this.element.showModal();
      this.draw();
      this.toolButtons[this.tool].focus();
    });
  }

  render() {
    const dialog = el('dialog', { class: 'opinion-annotation', 'aria-labelledby': `${this.id}-title`, 'aria-describedby': `${this.id}-hint` });

    this.toolButtons = {};
    const tools = el('div', { class: 'opinion-annotation__group', role: 'group', 'aria-label': this.labels.annotationTools });
    for (const tool of TOOLS) {
      const button = iconButton(tool, this.labels[TOOL_LABELS[tool]], { class: `opinion-annotation__tool opinion-annotation__tool--${tool}`, 'data-tool': tool });
      button.addEventListener('click', () => this.selectTool(tool));
      this.toolButtons[tool] = button;
      tools.append(button);
    }

    this.colorButtons = {};
    this.colorGroup = el('div', { class: 'opinion-annotation__group', role: 'group', 'aria-label': this.labels.annotationColor });
    for (const [name, value] of Object.entries(COLORS)) {
      const label = this.labels[COLOR_LABELS[name]];
      const button = el('button', { class: 'opinion-annotation__color', type: 'button', 'data-color': name, 'aria-label': label, title: label });
      // CSSOM instead of a style attribute, which a Content Security Policy may block
      button.style.setProperty('--opinion-swatch', value);
      button.addEventListener('click', () => this.selectColor(name));
      this.colorButtons[name] = button;
      this.colorGroup.append(button);
    }

    this.undoButton = iconButton('undo', this.labels.annotationUndo, { 'aria-keyshortcuts': 'Control+Z Meta+Z' });
    this.undoButton.addEventListener('click', () => this.undo());
    this.clearButton = iconButton('clear', this.labels.annotationClear);
    this.clearButton.addEventListener('click', () => this.clear());
    this.resetCropButton = iconButton('resetCrop', this.labels.annotationResetCrop);
    this.resetCropButton.addEventListener('click', () => this.resetCrop());

    const cancelButton = el('button', { class: 'opinion-dialog__button opinion-annotation__button', type: 'button' }, [icon('cancel'), this.labels.cancel]);
    cancelButton.addEventListener('click', () => this.element.close());
    const applyButton = el('button', { class: 'opinion-dialog__button opinion-dialog__button--primary opinion-annotation__button', type: 'button' }, [icon('apply'), this.labels.annotationApply]);
    applyButton.addEventListener('click', () => {
      this.commitText();
      this.result = { shapes: this.shapes, crop: this.crop };
      this.element.close();
    });

    this.canvas = el('canvas', { class: 'opinion-annotation__canvas', role: 'img', 'aria-label': this.labels.annotationCanvas });
    this.canvas.width = this.image.width;
    this.canvas.height = this.image.height;
    this.context = this.canvas.getContext('2d');
    this.canvas.addEventListener('pointerdown', (event) => this.startShape(event));
    this.canvas.addEventListener('pointermove', (event) => this.moveShape(event));
    this.canvas.addEventListener('pointerup', (event) => this.endShape(event));
    this.canvas.addEventListener('pointercancel', () => {
      this.draft = null;
      this.scheduleDraw();
    });

    dialog.addEventListener('keydown', (event) => {
      // In the text field Ctrl/Cmd + Z is the native text undo
      if (event.target === this.textInput) {
        return;
      }
      if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        this.undo();
      }
    });

    this.stage = el('div', { class: 'opinion-annotation__stage' }, [this.canvas]);
    dialog.append(
      el('div', { class: 'opinion-annotation__toolbar' }, [
        el('h2', { class: 'opinion-dialog__title opinion-annotation__title', id: `${this.id}-title` }, this.labels.annotationTitle),
        tools,
        this.colorGroup,
        el('div', { class: 'opinion-annotation__group' }, [this.undoButton, this.clearButton, this.resetCropButton]),
        el('div', { class: 'opinion-annotation__group opinion-annotation__group--end' }, [cancelButton, applyButton]),
      ]),
      el('p', { class: 'opinion-dialog__hint opinion-annotation__hint', id: `${this.id}-hint` }, this.labels.annotationHint),
      this.stage,
    );
    this.updateControls();
    return dialog;
  }

  selectTool(tool) {
    this.tool = tool;
    this.updateControls();
  }

  selectColor(color) {
    this.color = color;
    this.updateControls();
  }

  undo() {
    this.shapes.pop();
    this.updateControls();
    this.draw();
  }

  clear() {
    this.shapes = [];
    this.updateControls();
    this.draw();
  }

  resetCrop() {
    this.crop = null;
    this.updateControls();
    this.draw();
  }

  startShape(event) {
    if (event.button !== 0) {
      return;
    }
    // preventDefault() keeps the focus where it is, so an open text field does not blur by itself
    event.preventDefault();
    if (this.textInput) {
      this.commitText();
      return;
    }
    const { x, y } = this.toImagePoint(event);
    if (this.tool === 'text') {
      this.openTextInput(x, y);
      return;
    }
    this.canvas.setPointerCapture(event.pointerId);
    this.draft = { tool: this.tool, color: COLORS[this.color], x1: x, y1: y, x2: x, y2: y };
  }

  moveShape(event) {
    if (!this.draft) {
      return;
    }
    const { x, y } = this.toImagePoint(event);
    this.draft.x2 = x;
    this.draft.y2 = y;
    this.scheduleDraw();
  }

  endShape(event) {
    if (!this.draft) {
      return;
    }
    this.moveShape(event);
    if (this.draft.tool === 'crop') {
      // A new frame replaces the previous one; a too small one is an accidental click
      const { x1, y1, x2, y2 } = this.draft;
      this.crop = cropFromPoints(x1, y1, x2, y2) ?? this.crop;
    } else if (isShapeLargeEnough(this.draft)) {
      this.shapes.push(this.draft);
    }
    this.draft = null;
    this.updateControls();
    this.draw();
  }

  openTextInput(x, y) {
    const input = el('input', { class: 'opinion-annotation__text', type: 'text', maxlength: String(TEXT_MAX_LENGTH), 'aria-label': this.labels.annotationTextField });
    const color = COLORS[this.color];
    input.style.setProperty('--opinion-text-color', color);
    const canvasRect = this.canvas.getBoundingClientRect();
    const stageRect = this.stage.getBoundingClientRect();
    input.style.left = `${canvasRect.left - stageRect.left + (x * canvasRect.width) / this.canvas.width}px`;
    input.style.top = `${canvasRect.top - stageRect.top + (y * canvasRect.height) / this.canvas.height}px`;
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Enter') {
        event.preventDefault();
        this.commitText();
      } else if (event.key === 'Escape') {
        // Discard the text only, the editor stays open
        event.preventDefault();
        event.stopPropagation();
        this.closeTextInput();
      }
    });
    input.addEventListener('blur', () => this.commitText());
    this.textInput = input;
    this.textPosition = { x, y, color };
    this.stage.append(input);
    input.focus();
  }

  commitText() {
    if (!this.textInput) {
      return;
    }
    const text = this.textInput.value.trim();
    if (text !== '') {
      this.shapes.push({ tool: 'text', ...this.textPosition, text });
    }
    this.closeTextInput();
    this.updateControls();
    this.draw();
  }

  closeTextInput() {
    const input = this.textInput;
    if (!input) {
      return;
    }
    // Cleared first, so the blur caused by removing the field does not commit again
    this.textInput = null;
    input.remove();
    this.toolButtons[this.tool].focus();
  }

  toImagePoint(event) {
    const rect = this.canvas.getBoundingClientRect();
    const clamp = (value, max) => Math.min(Math.max(value, 0), max);
    return {
      x: clamp(((event.clientX - rect.left) * this.canvas.width) / rect.width, this.canvas.width),
      y: clamp(((event.clientY - rect.top) * this.canvas.height) / rect.height, this.canvas.height),
    };
  }

  scheduleDraw() {
    if (this.renderScheduled) {
      return;
    }
    this.renderScheduled = true;
    window.requestAnimationFrame(() => {
      this.renderScheduled = false;
      this.draw();
    });
  }

  draw() {
    const draftShape = this.draft && this.draft.tool !== 'crop' ? this.draft : null;
    renderAnnotations(this.context, this.image, draftShape ? [...this.shapes, draftShape] : this.shapes);
    const crop = this.draft?.tool === 'crop' ? cropFromPoints(this.draft.x1, this.draft.y1, this.draft.x2, this.draft.y2) : this.crop;
    if (crop) {
      this.drawCropFrame(crop);
    }
  }

  // Dims the area outside the frame; only shown in the editor, never burnt in
  drawCropFrame({ x, y, width, height }) {
    const context = this.context;
    const { width: canvasWidth, height: canvasHeight } = this.canvas;
    context.save();
    context.fillStyle = CROP_DIM_COLOR;
    context.beginPath();
    context.rect(0, 0, canvasWidth, canvasHeight);
    context.rect(x, y, width, height);
    context.fill('evenodd');
    context.strokeStyle = CROP_FRAME_COLOR;
    context.lineWidth = Math.max(1, Math.round(canvasWidth / 640));
    context.setLineDash([context.lineWidth * 4, context.lineWidth * 3]);
    context.strokeRect(x, y, width, height);
    context.restore();
  }

  updateControls() {
    const focused = document.activeElement;
    for (const [tool, button] of Object.entries(this.toolButtons)) {
      button.setAttribute('aria-pressed', String(tool === this.tool));
    }
    for (const [color, button] of Object.entries(this.colorButtons)) {
      button.setAttribute('aria-pressed', String(color === this.color));
      button.disabled = COLORLESS_TOOLS.includes(this.tool);
    }
    this.undoButton.disabled = this.shapes.length === 0;
    this.clearButton.disabled = this.shapes.length === 0;
    this.resetCropButton.disabled = this.crop === null;
    // A disabled button loses the focus, keep it inside the editor
    if (focused?.disabled) {
      this.toolButtons[this.tool].focus();
    }
  }
}
