..  _administration:

==============
Administration
==============

..  _administration-module:

Admin module "Feedback"
=======================

Administrators find all feedback in :guilabel:`Admin Tools > Feedback`.

List
    Newest first, with date, category, severity, source, sender, the start of
    the message, a screenshot marker and the delivery status.

Detail view
    The full message, the delivery status, the number of delivery attempts
    and the log, the screenshot (click to open it in full size) and all
    collected details. For feedback that is *Failed* or *Pending*, the button
    :guilabel:`Deliver again` starts a new delivery attempt right away; it
    ignores :confval:`extconf-redelivermaxattempts`. The result is shown as a
    message and appended to the log.

The records live on the root level (`pid = 0`), are read-only and are not
shown in the :guilabel:`List` module.

Delivery status
    *Sent* when every delivery channel succeeded, *Failed* when one failed
    (the log tells why, for example a missing recipient or a mail transport
    error), *Pending* when no channel ran.

..  _administration-redeliver:

Deliver feedback again
======================

A feedback stays *Failed* or *Pending* when its delivery did not succeed,
for example while the mail server was down. The console command
`opinion:redeliver` delivers these records again, oldest first, through all
delivery channels.

..  code-block:: bash

    # Failed or pending feedback older than 10 minutes
    vendor/bin/typo3 opinion:redeliver

    # Include feedback of the last minutes as well
    vendor/bin/typo3 opinion:redeliver --min-age=0

    # Only report what would be delivered
    vendor/bin/typo3 opinion:redeliver --dry-run

Feedback younger than `--min-age` minutes (default 10) is skipped, its first
delivery may still be running. Records that reached
:confval:`extconf-redelivermaxattempts` attempts, counting the first one, are
skipped too. The command exits with a non-zero code when a record is still
undelivered afterwards.

To retry regularly, add a Scheduler task :guilabel:`Execute console commands`
with the command `opinion:redeliver`, for example every 30 minutes.

..  _administration-cleanup:

Clean up old feedback
=====================

The console command `opinion:cleanup` deletes feedback records older than
:confval:`extconf-retentiondays`, including their file references and
screenshot files.

..  code-block:: bash

    # Older than retentionDays of the extension configuration
    vendor/bin/typo3 opinion:cleanup

    # Older than 30 days
    vendor/bin/typo3 opinion:cleanup --days=30

    # Only report what would be deleted
    vendor/bin/typo3 opinion:cleanup --dry-run

The command exits with a non-zero code when a record or file could not be
deleted.

To run it regularly, add a Scheduler task :guilabel:`Execute console commands`
with the command `opinion:cleanup`.

..  _administration-privacy:

Data and privacy
================

*   Feedback is only accepted from logged-in backend users who may send
    feedback, at most :confval:`extconf-ratelimit` per user and hour.
*   Screenshots are stored in a non-public file storage below `var/opinion/`,
    outside the web root. TYPO3 serves them through its file dump URL only,
    and only to requests with an administrator session.
*   Blacked-out areas are burnt into the screenshot in the browser; the
    original capture never leaves the page.
*   The browser sends a fixed set of details only, the server drops every
    other key. Cookies, `localStorage` and `sessionStorage` are never read.
*   Every detail except the sender can be switched off, see
    :ref:`configuration-collected-details`.
*   The IP address is stored anonymised.
*   `opinion:cleanup` enforces the retention period.
