# Spec: opinion-adminpanel

Module of the [Opinion capability map](SPEC.md), built on `opinion-core`.
Written after the implementation on request ("implement the buttons, test later").

## Objective

Editors viewing a frontend page with a backend login open the feedback dialog
from the admin panel. The page context is attached automatically.

## Behaviour

- Admin panel module `opinion` ("Feedback"), registered after `info`. Shown only
  when `AccessChecker::hasAccess()` passes (admins, or `options.opinion.enable = 1`).
  The admin panel itself must be enabled for the user (`admPanel.enable.*`).
- The module content shows a short description and a button "Send feedback".
  The button carries the dialog configuration (tokenised endpoint, labels) as
  `data-opinion-config`.
- A click on "Feedback" in the admin panel bar opens the dialog directly, the
  module panel stays closed (added 2026-10-13, saves a click; tested by the
  user in the project).
  `adminpanel-module.js` listens in the capture phase and stops the click
  before the core listener on the module trigger. The module is recognised by
  its tab `opinion_feedback`, the configuration is taken from the button in the
  panel. Users without email address have no button; for them the panel opens
  as before and shows the notice with the link to the user settings.
- Context: `pageUid`, `siteIdentifier`, `languageId`, `workspaceId`, taken from
  the frontend request. `FrontendContextProvider` validates them on submit:
  page exists and is not deleted (adds the title), site exists, language exists
  in the site, workspace is an integer. Anything else is dropped.
- Also attached: the details of the admin panel tab "Info → General" (page
  type, cache state and reasons, uncached elements, parse time, document size,
  simulated frontend user, images on the page with sizes), collected with the
  core `GeneralInformation` submodule. Cookies, server variables and request
  parameters of that submodule are not taken. `FrontendContextProvider`
  sanitizes the data against a schema (at most 50 images).
- The admin panel renders only modules with submodules and short info as tabs:
  `OpinionModule` is the tab, `FeedbackSubModule` holds the button.
- The admin panel loads module scripts as classic scripts; `adminpanel-module.js`
  imports the dialog module dynamically. The dialog CSS is a module resource.
- While a screenshot is taken, dialog and admin panel are hidden.

## Verification

- Functional: `OpinionModuleTest`, `ContextProviderTest`.
- Browser: `dialog.test.mjs` with the admin panel markup of the core template
  (direct opening, other modules untouched, fallback without configuration).
- Manual (open): admin panel tab visible for admin / editor with TSconfig,
  hidden for editor without; dialog opens, submission arrives with page context;
  token generated in the frontend is accepted.
