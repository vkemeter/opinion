# Changelog

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
