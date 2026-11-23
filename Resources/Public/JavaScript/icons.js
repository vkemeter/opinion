/**
 * Icons of the annotation editor as SVG paths (16 × 16, currentColor).
 * Taken from the TYPO3 icons (T3Icons, https://github.com/TYPO3/TYPO3.Icons, MIT license);
 * "arrow" and "text" are own drawings in the same style.
 */
const PATHS = {
  // actions-square
  rectangle: ['M13 2c.6 0 1 .4 1 1v10c0 .6-.4 1-1 1H3c-.6 0-1-.4-1-1V3c0-.6.4-1 1-1zm0-1H3c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h10c1.1 0 2-.9 2-2V3c0-1.1-.9-2-2-2'],
  arrow: ['M3.354 13.354 11.5 5.207V10h1V3.5H6v1h4.793l-8.147 8.146z'],
  text: ['M3 2h10v3h-1.25V3.5h-3V12.5H10V14H6v-1.5h1.25v-9h-3V5H3z'],
  // actions-eye-slash
  blackout: ['M8.071 3h.141C11.889 3.143 15 5.286 15 8.069c0 1.254-.699 2.427-1.849 3.314l-.792-.792c.956-.713 1.572-1.625 1.641-2.562 0-1.154-1.057-2.447-2.538-3.168.148.433.296.866.296 1.443a3.55 3.55 0 0 1-1.101 2.586L6.901 5.133c.315-.272.73-.442 1.144-.442.282 0 .423-.141.423-.282s-.282-.282-.423-.282c-.59 0-1.15.214-1.594.556L5.198 3.43A9.6 9.6 0 0 1 8.071 3M2 8.029c0 2.02 2.64 3.924 6 3.971a8 8 0 0 0 1.96-.272l.85.85A9.4 9.4 0 0 1 8 13c-4.03 0-7-2.286-7-5 0-1.287.7-2.478 1.858-3.374l.712.712C2.582 6.069 2 7.048 2 8.029m2.351-1.91 3.79 3.79-.087.001c-2.074 0-3.703-1.587-3.703-3.606zM2.354 1.646l12 12a.5.5 0 0 1-.708.708l-12-12a.5.5 0 0 1 .708-.708'],
  // actions-crop
  crop: ['M12 11V5c0-.6-.4-1-1-1H6v1h4.6c.3 0 .4.1.4.2V15h1v-3h3v-1z', 'M8.8 11H5.2c-.1 0-.2-.1-.2-.2V1H4v3H1v1h3v6c0 .6.4 1 1 1h5v-1z'],
  // actions-undo
  undo: ['M8 2c-1.8 0-3.4.8-4.5 2l-1-1c-.2-.2-.4-.1-.4.1l-.9 3.8q0 .3.3.3l3.8-.9c.2 0 .3-.3.1-.4l-1-1c.9-1 2.2-1.7 3.7-1.7 2.7 0 4.9 2.2 4.9 4.9S10.8 13 8.1 13c-1.5 0-2.8-.7-3.7-1.7l-.9.7c1.1 1.2 2.7 2 4.5 2 3.3 0 6-2.7 6-6s-2.7-6-6-6'],
  // actions-delete
  clear: ['M7 5H6v8h1zM10 5H9v8h1z', 'M13 3h-2v-.75C11 1.56 10.44 1 9.75 1h-3.5C5.56 1 5 1.56 5 2.25V3H3v10.75c0 .69.56 1.25 1.25 1.25h7.5c.69 0 1.25-.56 1.25-1.25zm-7-.75A.25.25 0 0 1 6.25 2h3.5a.25.25 0 0 1 .25.25V3H6zm6 11.5a.25.25 0 0 1-.25.25h-7.5a.25.25 0 0 1-.25-.25V4h8z', 'M13.5 4h-11a.5.5 0 0 1 0-1h11a.5.5 0 0 1 0 1'],
  // actions-arrow-diagonal-expand
  resetCrop: ['M10.073 1.426a.25.25 0 0 1-.054-.272.25.25 0 0 1 .23-.154h4.25a.5.5 0 0 1 .5.5v4.25c0 .101-.06.192-.154.23a.25.25 0 0 1-.272-.054zM5.926 14.573a.25.25 0 0 1 .054.272.25.25 0 0 1-.23.154H1.5a.5.5 0 0 1-.5-.5v-4.25c0-.101.06-.192.154-.23a.25.25 0 0 1 .272.054z', 'm12.146 3.146-9 9 .707.707 9-9z'],
  // actions-close
  cancel: ['M11.9 5.5 9.4 8l2.5 2.5c.2.2.2.5 0 .7l-.7.7c-.2.2-.5.2-.7 0L8 9.4l-2.5 2.5c-.2.2-.5.2-.7 0l-.7-.7c-.2-.2-.2-.5 0-.7L6.6 8 4.1 5.5c-.2-.2-.2-.5 0-.7l.7-.7c.2-.2.5-.2.7 0L8 6.6l2.5-2.5c.2-.2.5-.2.7 0l.7.7c.2.2.2.5 0 .7'],
  // actions-check
  apply: ['m13.3 4.8-.7-.7c-.2-.2-.5-.2-.7 0L6.5 9.5 4 6.9c-.2-.2-.5-.2-.7 0l-.6.7c-.2.2-.2.5 0 .7l3.6 3.6c.2.2.5.2.7 0l6.4-6.4c.1-.2.1-.5-.1-.7'],
};

const SVG_NAMESPACE = 'http://www.w3.org/2000/svg';

/**
 * A decorative SVG icon; the button that contains it carries the accessible name.
 */
export function icon(name) {
  const svg = document.createElementNS(SVG_NAMESPACE, 'svg');
  svg.setAttribute('viewBox', '0 0 16 16');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  svg.setAttribute('class', 'opinion-annotation__icon');
  for (const d of PATHS[name]) {
    const path = document.createElementNS(SVG_NAMESPACE, 'path');
    path.setAttribute('d', d);
    path.setAttribute('fill', 'currentColor');
    svg.append(path);
  }
  return svg;
}
