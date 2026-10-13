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
    The full message, the delivery status and log, the screenshot (click to
    open it in full size) and all collected details.

The records live on the root level (`pid = 0`), are read-only and are not
shown in the :guilabel:`List` module.

Delivery status
    *Sent* when every delivery channel succeeded, *Failed* when one failed
    (the log tells why, for example a missing recipient or a mail transport
    error), *Pending* when no channel ran.

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
    feedback.
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
