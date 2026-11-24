# Changelog

## Unreleased

- `opinion:cleanup --days=0` deletes feedback regardless of age, and the new
  option `--status` (e.g. `--status=closed` or `--status=new,closed`) deletes
  only feedback with these processing statuses, combined with the age
  (`--days`, default `retentionDays`)

## 2.0.1 – 2026-11-24

- Security: the reported URL is linked in the detail view and the email only
  when its host belongs to a configured site (site or language base), in the
  detail view also when it is the host of the backend; other URLs and URLs
  with a backslash are shown as text
- Security: `deniedUrlParameters` also removes parameters from the fragment
  (`#access_token=…`, `#/reset?token=…`); anchors and routes are kept.
  Feedback stored before is not changed
- Fix: the dashboard widgets "Open feedback" and "New messages" no longer
  show the button to the module "Feedback" to users who may not open it

## 2.0.0 – 2026-11-23

Upgrade from 1.x:

1. Update the database schema: `vendor/bin/typo3 extension:setup`
   (new tables `tx_opinion_message`, `tx_opinion_read`, new column
   `tx_opinion_feedback.page_uid`).
2. Run the upgrade wizard once: `vendor/bin/typo3 upgrade:run opinion_pageUid`.
3. Optional: `composer require typo3/cms-dashboard` for the dashboard widgets;
   allow editors the widgets and add `admPanel.enable.opinion = 1` where the
   admin panel list should appear.

- **Breaking:** screenshots are delivered through the backend route
  `opinion_screenshot` (access inherited from the module "Feedback") instead
  of the file dump. File dump URLs (`eID=dumpFile`) of screenshots and their
  processed variants always answer `403`, also for administrators; links
  copied from the detail view before stop working. The Core does the whole
  session check now
- **Breaking:** the POST routes `opinion_feedback.status` and
  `opinion_feedback.message` are replaced by `opinion_feedback.save`
- Added: a conversation per feedback in the module "Feedback": administrators
  and the sender exchange plain-text messages as a thread, without email. A
  reply of the sender reopens closed feedback. The list shows the number of
  messages and marks unread ones as *New*; the module menu entry "Feedback"
  counts the feedback entries with unread messages. `opinion:cleanup` deletes
  messages with their feedback. Extension setting `conversation` (default on)
  switches it off
- Added: open feedback of a page is shown where people work on it: the admin
  panel lists the open frontend feedback of the current page under "Feedback
  on this page" (links to the module for administrators), the page module
  shows the open frontend and backend feedback of the page in a collapsible
  box
- Added: dashboard widgets "Open feedback", "New messages" and "Latest
  feedback" in the widget group "Feedback", with the access rules of the
  module. The Core extension dashboard is optional (`suggest`)
- Added: the screenshot editor crops the screenshot to the relevant part (only
  that part is sent) and places short text notes in the selected colour
- Changed: the detail view saves message and status with one form and one
  button "Save", so neither change is lost; message and screenshot are side by
  side, the conversation follows as a centred thread, the collected details
  are collapsed by default
- Changed: the toolbar of the screenshot editor shows small icon buttons with
  tooltips (icons from the TYPO3 icons), all tools and actions in one row
- Changed: the extension settings show labelled tabs "Email" and "Storage";
  `disabledDeliveryChannels` moved to the tab "Basic"
- Changed: the record edit form no longer shows the screenshot field
- Fixed: feedback of editors with screenshot failed in the email delivery
  ("You are not allowed to access that file"); the screenshot is now read on
  behalf of the system, as it is stored
- Fixed: senders and authors without real name are shown with their username
  in the module instead of an empty name
- Removed: `AdminRequestChecker` and `AdminRequestCheckerInterface`

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
