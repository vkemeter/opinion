# Changelog

## Unreleased

- Added: dashboard widgets "Open feedback", "New messages" and "Latest
  feedback" in the widget group "Feedback", with the access rules of the
  module. The Core extension dashboard is optional (`suggest`); without it
  the widgets are not registered
- Changed: the detail view of the module "Feedback" saves message and status
  with one form and one button "Save", so neither change is lost
  (routes `opinion_feedback.status` and `opinion_feedback.message` replaced
  by `opinion_feedback.save`). Message and screenshot are side by side, the
  conversation follows as a centred thread, the collected details are
  collapsed by default
- Added: extension setting `conversation` (default on) to switch the
  conversation off
- Changed: the extension settings show labelled tabs "Email" and "Storage";
  `disabledDeliveryChannels` moved to the tab "Basic"
- Added: a conversation per feedback in the module "Feedback": administrators
  and the sender exchange plain-text messages below the feedback, without
  email. A reply of the sender reopens closed feedback. The list shows the
  number of messages and marks unread ones as *New*; the module menu entry
  "Feedback" counts the feedback entries with unread messages in a badge. Needs a database schema update
  (`extension:setup`) for the new tables `tx_opinion_message` and
  `tx_opinion_read`; `opinion:cleanup` deletes messages with their feedback
- Fixed: feedback of editors with screenshot failed in the email delivery
  ("You are not allowed to access that file"); the screenshot is now read on
  behalf of the system, as it is stored
- Added: the screenshot editor can crop the screenshot to the relevant part
  (only that part is sent) and place short text notes in the selected colour
- Changed: the toolbar of the screenshot editor is more compact: small icon
  buttons with tooltips (icons from TYPO3 icons), all tools and actions in one
  row
- **Breaking:** screenshots are delivered through the feedback module
  (backend route `opinion_screenshot`, access inherited from the module) instead of the file dump. File dump
  URLs (`eID=dumpFile`) of screenshots and their processed variants always
  answer `403`, also for administrators; links copied from the detail view
  before stop working. The Core now does the whole session check
- Removed: `AdminRequestChecker` and `AdminRequestCheckerInterface`
- Changed: the record edit form no longer shows the screenshot field

## 1.2.1 – 2026-11-23

- Fixed: errors while writing the screenshot into the storage (folder
  creation, permissions, full disk) are answered with `503` and the hint to
  send without screenshot instead of a bare `500`

## 1.2.0 – 2026-11-20

- **Breaking:** the module "Feedback" moved from Admin Tools to its own entry in
  the module menu. Editors with `options.opinion.enable = 1` and the module
  granted in their backend group see their own feedback there (message,
  screenshot, details, status); delivery details and actions stay with
  administrators. Screenshots are served to administrators and the sender
- Added: processing status `new`, `in_progress` or `closed` per feedback,
  shown in the list and set by administrators in the detail view. Needs a
  database schema update (`extension:setup`); existing feedback becomes `new`
- Added: search (message, sender, reported URL, page title) and filters for
  status, category, severity, source and delivery status in the module list;
  they stay in the URL when paging and returning from the detail view
- Added: the detail view and the email show the reported URL once as link,
  instead of twice as text in the tables of collected details
- Added: the list shows the sender's email address (as at submission time) as
  mailto link
- Changed (internal): `AdminRequestCheckerInterface::isAdminRequest()` is
  replaced by `getAuthenticatedBackendUser()`

## 1.1.0 – 2026-11-20

- Added: extension configuration `disabledDeliveryChannels` switches delivery
  channels off by identifier, e.g. `email`, also custom channels
- Added: delivery status `skipped` ("Not delivered") when no channel is
  enabled; such feedback is not delivered again. Without any channel feedback
  previously stayed `pending` and was retried by `opinion:redeliver`

## 1.0.1 – 2026-11-20

- Fixed: `opinion:redeliver` and "Deliver again" count a feedback record that
  cannot be rebuilt (unknown category, severity or source) as a failed
  attempt and note the reason in its log, so `redeliverMaxAttempts` ends its
  retries instead of failing every run
- Documentation: the endpoint answers `429` above the rate limit and `403`
  without a valid email address of the sender

## 1.0.0 – 2026-10-21

First public release for TYPO3 v14.3 and PHP 8.4/8.5.

- Feedback dialog with description, category, severity and an optional
  screenshot of the current browser tab (`getDisplayMedia()`, HTTPS only)
- Screenshot annotation: rectangle, arrow, black out, undo; the markings are
  burnt into the image
- Entry points: "Feedback" in the frontend admin panel and a button in the
  backend toolbar
- Feedback stored as read-only records on the root level, screenshots in a
  non-public file storage below `var/opinion/`
- Delivery by email; failed deliveries are retried by `opinion:redeliver` or
  the button "Deliver again" in the admin module
- Admin module "Admin Tools → Feedback" with list and detail view
- One switch per collected detail and a denylist for URL parameters
- Email address of the sender required, rate limit per backend user
- `opinion:cleanup` deletes feedback after the retention period
- Site set `vkemeter/opinion` to show the admin panel
