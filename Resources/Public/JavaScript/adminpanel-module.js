// The admin panel loads module scripts as classic scripts, the dialog is an ES module next to this file.
(() => {
  const dialogModuleUrl = new URL('opinion-dialog.js', document.currentScript.src).href;

  const openDialog = async (button) => {
    const { openOpinionDialog } = await import(dialogModuleUrl);
    openOpinionDialog(JSON.parse(button.dataset.opinionConfig));
  };

  // A click on "Feedback" in the admin panel bar opens the dialog right away instead of the
  // module panel with its button. The capture phase runs before the core listener on the
  // trigger, which then never sees the click. Without the button (no email address) the panel
  // opens as usual and shows the notice.
  document.addEventListener(
    'click',
    (event) => {
      const trigger = event.target instanceof Element ? event.target.closest('[data-typo3-role=typo3-adminPanel-module-trigger]') : null;
      const module = trigger?.closest('.typo3-adminPanel-module');
      if (!module?.querySelector('[data-typo3-tab-target=opinion_feedback]')) {
        return;
      }
      const button = module.querySelector('[data-opinion-config]');
      if (!button) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      openDialog(button);
    },
    { capture: true },
  );

  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-opinion-config]') : null;
    if (!button) {
      return;
    }
    event.preventDefault();
    openDialog(button);
  });
})();
