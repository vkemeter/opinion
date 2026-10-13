..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
================

Opinion adds a feedback dialog to the TYPO3 frontend and backend. Editors who
notice a problem — a cropped image, a typo, a broken layout, an idea — describe
it right where they see it. The extension attaches what the team needs to
reproduce it, stores the feedback and sends it by email.

..  figure:: /Images/FeedbackDialog.png
    :alt: The feedback dialog with message, category, severity and a screenshot
    :class: with-border with-shadow

    The feedback dialog with a screenshot of the current page

..  _features:

Features
========

*   **Two entry points:** the entry "Feedback" in the frontend admin panel and
    a button in the backend toolbar. Both open the same dialog.
*   **Message, category and severity:** categories *Bug*, *Content*, *Idea*,
    *Other*; severities *Low*, *Normal*, *High*.
*   **Screenshot of the current tab** via the browser's screen capture
    (`getDisplayMedia()`), with preview, retake and remove.
*   **Mark up the screenshot** in a full-screen editor: rectangle, arrow and
    black out. Markings are burnt into the image in the browser.
*   **Collected details** about browser, server and the current page or
    backend module, each of which an administrator can switch off.
*   **Stored first, delivered second:** every submission is saved as a record;
    a failing mail transport never loses feedback.
*   **Email delivery** to configurable recipients, the sender as reply-to,
    the screenshot inline and attached.
*   **Admin module "Feedback"** with a list and a detail view.
*   **Non-public screenshot storage** outside the web root, readable for
    administrators only.
*   **Retention:** the console command `opinion:cleanup` deletes old feedback,
    also as a Scheduler task.

..  _requirements:

Requirements
============

*   TYPO3 v14.3
*   PHP 8.4
*   HTTPS for screenshots: browsers allow screen capture in secure contexts only
*   A configured mail transport for the email delivery

..  _languages:

Languages
=========

The user interface is available in English and German. Labels follow the
language of the backend user.
