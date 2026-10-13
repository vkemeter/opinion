# Spec: opinion-backend-toolbar

Module of the [Opinion capability map](SPEC.md), built on `opinion-core`.
Written after the implementation on request ("implement the buttons, test later").

## Objective

Editors open the feedback dialog from the backend toolbar, with the module and
page they are working on attached.

## Behaviour

- Toolbar item `OpinionToolbarItem` (index 15, speech-bubble icon), shown only
  when `AccessChecker::hasAccess()` passes.
- The button carries the dialog configuration as `data-opinion-toolbar-config`;
  `toolbar-item.js` (import map `@vkemeter/opinion/`) opens the dialog.
- Context is read when the button is clicked: module name of the module frame
  (`.module[data-module-name]`, fallback: router `module` attribute), the frame
  URL (path and query) and its `id` parameter as page uid.
  `BackendContextProvider` keeps only registered modules and existing pages and
  removes the request token from the URL.
- The dialog CSS is added through the PageRenderer.

## Verification

- Functional: `OpinionToolbarItemTest`, `ContextProviderTest`.
- Manual (open): button visible for admin / editor with TSconfig, hidden for
  editor without; submission from different modules carries module, page, URL.
