// The admin panel loads module scripts as classic scripts, the dialog is an ES module next to this file.
(() => {
  const dialogModuleUrl = new URL('opinion-dialog.js', document.currentScript.src).href;

  document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-opinion-config]') : null;
    if (!button) {
      return;
    }
    event.preventDefault();
    const { openOpinionDialog } = await import(dialogModuleUrl);
    openOpinionDialog(JSON.parse(button.dataset.opinionConfig));
  });
})();
