import { COLORS, TOOLS, isShapeLargeEnough, renderAnnotations } from './annotation.js';
import { el } from './dom.js';

const TOOL_LABELS = { rectangle: 'annotationRectangle', arrow: 'annotationArrow', blackout: 'annotationBlackout' };
const COLOR_LABELS = { red: 'annotationColorRed', yellow: 'annotationColorYellow', blue: 'annotationColorBlue' };

/**
 * Opens the full-screen editor above the feedback dialog.
 * Resolves with the new list of shapes on apply, or null on cancel.
 *
 * @param {{ image: ImageBitmap, shapes: Array<object>, labels: object, id: string }} options
 * @returns {Promise<Array<object>|null>}
 */
export function openAnnotationEditor(options) {
  return new AnnotationEditor(options).open();
}

class AnnotationEditor {
  constructor({ image, shapes, labels, id }) {
    this.image = image;
    this.shapes = [...shapes];
    this.labels = labels;
    this.id = id;
    this.tool = TOOLS[0];
    this.color = Object.keys(COLORS)[0];
    this.draft = null;
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
      const button = el('button', { class: `opinion-dialog__button opinion-annotation__tool opinion-annotation__tool--${tool}`, type: 'button', 'data-tool': tool }, this.labels[TOOL_LABELS[tool]]);
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

    this.undoButton = el('button', { class: 'opinion-dialog__button', type: 'button', 'aria-keyshortcuts': 'Control+Z Meta+Z' }, this.labels.annotationUndo);
    this.undoButton.addEventListener('click', () => this.undo());
    this.clearButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.annotationClear);
    this.clearButton.addEventListener('click', () => this.clear());

    const cancelButton = el('button', { class: 'opinion-dialog__button', type: 'button' }, this.labels.cancel);
    cancelButton.addEventListener('click', () => this.element.close());
    const applyButton = el('button', { class: 'opinion-dialog__button opinion-dialog__button--primary', type: 'button' }, this.labels.annotationApply);
    applyButton.addEventListener('click', () => {
      this.result = this.shapes;
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
      if ((event.ctrlKey || event.metaKey) && !event.shiftKey && event.key.toLowerCase() === 'z') {
        event.preventDefault();
        this.undo();
      }
    });

    dialog.append(
      el('div', { class: 'opinion-annotation__toolbar' }, [
        el('h2', { class: 'opinion-dialog__title opinion-annotation__title', id: `${this.id}-title` }, this.labels.annotationTitle),
        tools,
        this.colorGroup,
        el('div', { class: 'opinion-annotation__group' }, [this.undoButton, this.clearButton]),
        el('div', { class: 'opinion-annotation__group opinion-annotation__group--end' }, [cancelButton, applyButton]),
      ]),
      el('p', { class: 'opinion-dialog__hint opinion-annotation__hint', id: `${this.id}-hint` }, this.labels.annotationHint),
      el('div', { class: 'opinion-annotation__stage' }, [this.canvas]),
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

  startShape(event) {
    if (event.button !== 0) {
      return;
    }
    event.preventDefault();
    this.canvas.setPointerCapture(event.pointerId);
    const { x, y } = this.toImagePoint(event);
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
    if (isShapeLargeEnough(this.draft)) {
      this.shapes.push(this.draft);
    }
    this.draft = null;
    this.updateControls();
    this.draw();
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
    renderAnnotations(this.context, this.image, this.draft ? [...this.shapes, this.draft] : this.shapes);
  }

  updateControls() {
    const focused = document.activeElement;
    for (const [tool, button] of Object.entries(this.toolButtons)) {
      button.setAttribute('aria-pressed', String(tool === this.tool));
    }
    for (const [color, button] of Object.entries(this.colorButtons)) {
      button.setAttribute('aria-pressed', String(color === this.color));
      // Black out is always black
      button.disabled = this.tool === 'blackout';
    }
    this.undoButton.disabled = this.shapes.length === 0;
    this.clearButton.disabled = this.shapes.length === 0;
    // A disabled button loses the focus, keep it inside the editor
    if (focused?.disabled) {
      this.toolButtons[this.tool].focus();
    }
  }
}
