import { canvasToJpeg } from './screenshot.js';

export const TOOLS = ['rectangle', 'arrow', 'blackout'];
export const COLORS = { red: '#e00000', yellow: '#ffd400', blue: '#0078e6' };
const BLACKOUT_COLOR = '#000';
// Shapes smaller than this (in image pixels) are treated as accidental clicks
const MIN_SIZE = 4;

/**
 * A shape is { tool, color, x1, y1, x2, y2 } in image pixel coordinates.
 */
export function isShapeLargeEnough(shape) {
  return Math.hypot(shape.x2 - shape.x1, shape.y2 - shape.y1) >= MIN_SIZE;
}

export function lineWidthFor(imageWidth) {
  return Math.max(2, Math.round(imageWidth / 320));
}

/**
 * Draws the image and all shapes onto the canvas, which must have the image size.
 *
 * @param {CanvasRenderingContext2D} context
 * @param {CanvasImageSource} image
 * @param {Array<object>} shapes
 */
export function renderAnnotations(context, image, shapes) {
  const { width, height } = context.canvas;
  context.clearRect(0, 0, width, height);
  context.drawImage(image, 0, 0, width, height);
  const lineWidth = lineWidthFor(width);
  for (const shape of shapes) {
    drawShape(context, shape, lineWidth);
  }
}

function drawShape(context, shape, lineWidth) {
  const x = Math.min(shape.x1, shape.x2);
  const y = Math.min(shape.y1, shape.y2);
  const width = Math.abs(shape.x2 - shape.x1);
  const height = Math.abs(shape.y2 - shape.y1);
  context.save();
  context.lineWidth = lineWidth;
  context.lineCap = 'round';
  context.lineJoin = 'round';
  context.strokeStyle = shape.color;
  context.fillStyle = shape.color;
  switch (shape.tool) {
    case 'rectangle':
      context.strokeRect(x, y, width, height);
      break;
    case 'blackout':
      context.fillStyle = BLACKOUT_COLOR;
      context.fillRect(x, y, width, height);
      break;
    case 'arrow':
      drawArrow(context, shape, lineWidth);
      break;
  }
  context.restore();
}

function drawArrow(context, { x1, y1, x2, y2 }, lineWidth) {
  const angle = Math.atan2(y2 - y1, x2 - x1);
  const headLength = Math.min(lineWidth * 5, Math.hypot(x2 - x1, y2 - y1));
  const headAngle = Math.PI / 7;
  // The shaft ends inside the head, so the round cap does not blunt the tip
  const shaftEnd = headLength * Math.cos(headAngle) * 0.8;
  context.beginPath();
  context.moveTo(x1, y1);
  context.lineTo(x2 - shaftEnd * Math.cos(angle), y2 - shaftEnd * Math.sin(angle));
  context.stroke();
  context.beginPath();
  context.moveTo(x2, y2);
  context.lineTo(x2 - headLength * Math.cos(angle - headAngle), y2 - headLength * Math.sin(angle - headAngle));
  context.lineTo(x2 - headLength * Math.cos(angle + headAngle), y2 - headLength * Math.sin(angle + headAngle));
  context.closePath();
  context.fill();
}

/**
 * Returns the image with the shapes burnt in, as JPEG.
 *
 * @param {ImageBitmap} image
 * @param {Array<object>} shapes
 * @returns {Promise<Blob>}
 */
export function flattenAnnotations(image, shapes) {
  const canvas = document.createElement('canvas');
  canvas.width = image.width;
  canvas.height = image.height;
  renderAnnotations(canvas.getContext('2d'), image, shapes);
  return canvasToJpeg(canvas);
}
