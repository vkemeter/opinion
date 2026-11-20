# Changelog

## Unreleased

- Added: the detail view and the email show the reported URL once as link,
  instead of twice as text in the tables of collected details
- Added: the list shows the sender's email address (as at submission time) as
  mailto link
- Added: processing status `new`, `in_progress` or `closed` per feedback,
  shown in the list and set by administrators in the detail view. Needs a
  database schema update; existing feedback becomes `new`

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
