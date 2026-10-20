import { openOpinionDialog } from './opinion-dialog.js';

/**
 * Adds the module the editor is working in to the dialog context. Module,
 * page and URL are read from the module frame and validated on the server.
 */
function collectBackendContext() {
  const context = {};
  try {
    const frame = document.querySelector('#typo3-contentIframe');
    const location = frame?.contentWindow?.location;
    if (location && location.origin === window.location.origin) {
      context.url = location.pathname + location.search;
      const pageUid = new URLSearchParams(location.search).get('id');
      if (pageUid) {
        context.pageUid = pageUid;
      }
      context.module = frame.contentDocument?.querySelector('.module[data-module-name]')?.getAttribute('data-module-name') || undefined;
    }
  } catch {
    // The module frame may show a page of another origin
  }
  context.module ??= document.querySelector('typo3-backend-module-router')?.getAttribute('module') || undefined;

  return context;
}

document.addEventListener('click', (event) => {
  const button = event.target instanceof Element ? event.target.closest('[data-opinion-toolbar-config]') : null;
  if (!button) {
    return;
  }
  event.preventDefault();
  let config;
  try {
    config = JSON.parse(button.dataset.opinionToolbarConfig);
  } catch (error) {
    console.error('Opinion: the feedback dialog could not be opened.', error);
    return;
  }
  const enabled = config.collect?.context;
  const context = Object.fromEntries(
    Object.entries(collectBackendContext()).filter(([key]) => !enabled || enabled.includes(key === 'pageUid' ? 'page' : key)),
  );
  openOpinionDialog({ ...config, context: { ...config.context, ...context } });
});
