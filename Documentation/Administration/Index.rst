..  _administration:

==============
Administration
==============

..  _administration-module:

Module "Feedback"
=================

The module :guilabel:`Feedback` has its own entry in the module menu.
Administrators see all feedback there. Editors who may send feedback and have
the module granted (see :ref:`configuration-access`) see only the feedback
they sent themselves, with message, screenshot, collected details and the
status; delivery details and all actions stay with administrators.

..  figure:: /Images/ModuleDetailView.png
    :alt: The detail view with message, status, screenshot, the conversation as a thread and the collapsed details
    :class: with-border with-shadow

    Detail view of a feedback, here as seen by its sender

List
    Newest first, with date, category, severity, source, sender and their
    email address, the start of the message, a screenshot or video marker, the
    status, the conversation (number of messages, *New* when there are
    messages you have not read yet) and the delivery status.

Search and filter
    Above the list, the search finds feedback whose message, sender name or
    username, reported URL or page title contains the term. Filters for
    status, category, severity, source and, for administrators, delivery
    status narrow the list further. Search and filter stay in the URL, so they
    survive paging and the way back from the detail view, and can be
    bookmarked. :guilabel:`Reset` shows all feedback again. Editors search
    their own feedback only.

Detail view
    The full message and the screenshot (click to open it in full size) or
    the video with a download link side by side, below them the status, for
    administrators the delivery status,
    the number of delivery attempts and the log. Then the conversation with
    the form to save, and the collected details as sections that are
    collapsed until you click them; for a video the context at the end of
    the recording is one of them (*At the end of the recording*) when it
    differs from the start. For feedback that is *Failed* or *Pending*, the button
    :guilabel:`Deliver again` starts a new delivery attempt right away; it
    ignores :confval:`extconf-redelivermaxattempts`. The result is shown as a
    message and appended to the log.

Status
    Every feedback starts with the first configured status, by default *New*.
    Administrators set any configured status (by default *New*,
    *In progress* or *Closed*, see :confval:`extconf-statuses`) in the form
    below the conversation and save it
    with :guilabel:`Save`, together with a message or alone, so neither gets
    lost. When the form is invalid, nothing of it is saved. The status is independent of the delivery: it
    neither starts nor changes a delivery. Feedback stored before version 1.2
    gets the status *New* when the database schema is updated
    (:bash:`vendor/bin/typo3 extension:setup` or the database analyzer).

Conversation
    Below message and screenshot, administrators and the sender write
    messages to each other as a thread: plain text, oldest first, with author
    and time; the messages of the sender on the left, the others on the
    right.
    Administrators write in every conversation, editors in the conversations
    of their own feedback. Messages cannot be edited or deleted. No email is
    sent for them; the email for new feedback stays as it is. When the sender
    answers a closed feedback, its status changes to
    :confval:`extconf-reopenstatus` (by default *In progress*); a
    message of an administrator does not change the status, the status
    selected in the form is saved as it is. The conversation can be switched
    off with :confval:`extconf-conversation`.

Unread messages
    Opening the detail view marks its messages as read for you only; another
    administrator still sees them as unread. The module menu entry
    :guilabel:`Feedback` shows the number of feedback entries with unread
    messages as a badge, as of the last backend load.

Reported URL
    The detail view and the email show the URL the feedback was sent from
    once, below the sender: the browser URL, or the backend module URL when
    the browser URL is not collected. It is linked only when it starts with
    `http://` or `https://`; in the detail view it opens in a new tab.

The records live on the root level (`pid = 0`), are read-only and are not
shown in the :guilabel:`List` module.

Delivery status
    *Sent* when every delivery channel succeeded, *Failed* when one failed
    (the log tells why, for example a missing recipient or a mail transport
    error), *Pending* while the delivery has not finished, *Not delivered*
    when no delivery channel is enabled (see
    :confval:`extconf-disableddeliverychannels`).
    *Not delivered* feedback is never delivered again.

..  _administration-redeliver:

Deliver feedback again
======================

A feedback stays *Failed* or *Pending* when its delivery did not succeed,
for example while the mail server was down. The console command
`opinion:redeliver` delivers these records again, oldest first, through all
enabled delivery channels.

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

..  _administration-upgrade:

Upgrade to version 2
====================

Version 2 stores the page of a feedback in its own field. After the database
schema update (:bash:`vendor/bin/typo3 extension:setup`), run the upgrade
wizard :guilabel:`Opinion: page of existing feedback` once, so feedback sent
before is listed per page as well:

..  code-block:: bash

    vendor/bin/typo3 upgrade:run opinion_pageUid

Feedback without collected page (`collect_context_page`, see
:ref:`configuration-collected-details-context`)
belongs to no page and is never listed per page.

..  _administration-cleanup:

Clean up old feedback
=====================

The console command `opinion:cleanup` deletes feedback records older than
:confval:`extconf-retentiondays`, including their file references,
screenshot and video files, conversation messages and read state.

..  code-block:: bash

    # Older than retentionDays of the extension configuration
    vendor/bin/typo3 opinion:cleanup

    # Older than 30 days
    vendor/bin/typo3 opinion:cleanup --days=30

    # All feedback, regardless of age
    vendor/bin/typo3 opinion:cleanup --days=0

    # Closed feedback older than retentionDays
    vendor/bin/typo3 opinion:cleanup --status=closed

    # All closed feedback, regardless of age
    vendor/bin/typo3 opinion:cleanup --days=0 --status=closed

    # Only report what would be deleted
    vendor/bin/typo3 opinion:cleanup --dry-run

`--status` takes one or more processing statuses, comma-separated
(one of :confval:`extconf-statuses`, by default `new`, `in_progress`,
`closed`), and is combined with the age. An unknown
status or a negative `--days` stops the command before anything is deleted.

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
    outside the web root. They are delivered only through the feedback module
    in the backend, to administrators and to the sender of the feedback. The
    TYPO3 file dump URL (`eID=dumpFile`) always answers `403` for them.
*   Videos are stored and delivered like screenshots. The email never
    carries a video, it links the detail view instead. Video is off by
    default (:confval:`extconf-videoenabled`); the dialog tells the sender
    before every recording that everything visible and typed in the tab is
    in the video.
*   Blacked-out areas are burnt into the screenshot in the browser; the
    original capture never leaves the page.
*   The browser sends a fixed set of details only, the server drops every
    other key. Cookies, `localStorage` and `sessionStorage` are never read.
*   Every detail except the sender can be switched off, see
    :ref:`configuration-collected-details`.
*   The IP address is stored anonymised.
*   Conversation messages may contain personal data. They are shown only to
    administrators and the sender of the feedback, are never sent by email
    and are deleted with their feedback by `opinion:cleanup`. Messages of a
    backend user deleted since stay and are shown with the author "Deleted
    user".
*   Everybody who may send feedback sees the text, date and status of the
    open feedback of the page they view, also of other senders, in the
    admin panel (frontend feedback) and in the page module (frontend and
    backend feedback); names of senders are not shown.
*   `opinion:cleanup` enforces the retention period.
