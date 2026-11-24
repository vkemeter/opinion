..  _usage:

=====
Usage
=====

Feedback can be sent by every backend user who may send feedback, see
:ref:`configuration-access`. A backend login is always required, also in the
frontend.

..  _usage-frontend:

Frontend: admin panel
=====================

On a frontend page, click :guilabel:`Feedback` in the admin panel. The dialog
opens directly. The page, site, language and workspace as well as the details
of the admin panel tab :guilabel:`Info > General` are attached.

When the page already has open feedback (*New* or *In progress*) sent from the
frontend, the admin panel shows :guilabel:`Feedback on this page` with the
number. It lists that feedback with text, date and status, so you see whether
a problem was reported already. The list shows the feedback of every sender,
without their names; feedback sent from the backend is not listed here.
Administrators open a feedback from the list in the module
:guilabel:`Feedback`, in a new tab.

..  figure:: /Images/AdminPanelPageFeedback.png
    :alt: The admin panel tab "Feedback on this page" with two open feedback entries
    :class: with-border with-shadow

    Open feedback of the current page in the admin panel

..  _usage-backend:

Backend: toolbar
================

In the backend, click the speech-bubble button in the toolbar. The current
module, the module URL and the selected page are attached.

When an administrator answers your feedback and you have access to the module
:guilabel:`Feedback`, its entry in the module menu shows a badge with the
number of feedback entries with new messages. Answer in the detail view of the
feedback, below the message (see :ref:`administration-module`).

..  _usage-page-module:

Backend: page module
====================

The page module shows a collapsed box above the content when the page has
open feedback, with their number. Opened, it lists the feedback grouped into
feedback sent from the frontend and from the backend, each with status, date
and text. Administrators open a feedback from there in the module
:guilabel:`Feedback`. The box is shown to everybody who may send feedback and
may open the page.

..  figure:: /Images/PageModuleFeedback.png
    :alt: The page module with the opened box "Open feedback on this page" above the content
    :class: with-border with-shadow

    Open feedback of a page in the page module

..  _usage-dashboard:

Backend: dashboard widgets
==========================

With the TYPO3 dashboard installed (Composer package `typo3/cms-dashboard`),
add the widgets of the group :guilabel:`Feedback` to your dashboard:

:guilabel:`Open feedback`
    The number of feedback that is *New* or *In progress*.

:guilabel:`New messages`
    The number of feedback entries with messages you have not read yet; 0
    while :confval:`extconf-conversation` is switched off.

:guilabel:`Latest feedback`
    The five newest entries with date, category, status and the start of the
    message, each linked to its detail view.

Each widget has a button to the module :guilabel:`Feedback`. The widgets show
the same feedback as the module: administrators all of it, editors with access
to the module only their own, other users nothing.

..  figure:: /Images/DashboardWidgets.png
    :alt: A dashboard with the widgets "Open feedback", "New messages" and "Latest feedback"
    :class: with-border with-shadow

    The three widgets of the group "Feedback"

..  _usage-dialog:

The feedback dialog
===================

..  figure:: /Images/FeedbackDialog.png
    :alt: The feedback dialog
    :class: with-border with-shadow

    The feedback dialog

Description
    What is wrong or what could be better, between 10 and 5000 characters.

Category
    By default *Bug*, *Content*, *Idea* or *Other*; administrators can
    configure their own list, see :confval:`extconf-categories`.

Severity
    *Low*, *Normal* or *High*.

Screenshot
    Optional, see below.

:guilabel:`Send` stores the feedback and delivers it by email. The dialog
confirms when it is stored; if the email could not be sent, it says so — the
feedback is stored nevertheless.

Your feedback appears in the module :guilabel:`Feedback` together with its
status, if the module is granted to you. Search and filter above the list
help to find it again.

..  _usage-screenshot:

Take a screenshot
=================

#.  Click :guilabel:`Take screenshot`.
#.  The browser asks what to share. Choose the current tab.
#.  The dialog hides itself while the picture is taken and shows a preview
    afterwards.

:guilabel:`Retake` takes a new screenshot, :guilabel:`Remove` drops it.

Screenshots need a secure (HTTPS) connection and a current browser. If the
browser cannot take screenshots, or the permission is denied, the dialog
explains it and the feedback can still be sent without one.

..  _usage-annotation:

Mark up the screenshot
======================

Click :guilabel:`Mark up` below the preview to open the screenshot in a
full-screen editor.

..  figure:: /Images/AnnotationEditor.png
    :alt: The editor with a rectangle, an arrow, a text note and a blacked-out area on the screenshot
    :class: with-border with-shadow

    Marking up the screenshot

:guilabel:`Rectangle`
    Frames an area.

:guilabel:`Arrow`
    Points at an element; drag from the start to the tip.

:guilabel:`Text`
    Click on the image, type a short note (one line, up to 200 characters)
    and press :kbd:`Enter`. The text is drawn in the selected colour with a
    dark outline. :kbd:`Esc` discards the text and keeps the editor open.
    Placed text cannot be edited or moved; undo it and place it again.

:guilabel:`Black out`
    Covers an area with black, for example personal data that must not be
    sent.

:guilabel:`Crop`
    Drag a frame around the relevant part; only this part is sent, at full
    resolution. A new frame replaces the previous one. The area outside the
    frame is dimmed while editing.

:guilabel:`Reset crop`
    Removes the frame, the whole screenshot is sent again. The crop is not
    part of :guilabel:`Undo`.

Colour
    Red, yellow or blue for rectangle, arrow and text. Black out is always
    black.

:guilabel:`Undo` (also :kbd:`Ctrl` + :kbd:`Z` or :kbd:`Cmd` + :kbd:`Z`)
    Removes the last marking.

:guilabel:`Clear all`
    Removes all markings.

:guilabel:`Apply`
    Burns the markings into the screenshot and returns to the dialog.

:guilabel:`Cancel` (also :kbd:`Esc`)
    Returns to the dialog without changes.

Draw with the mouse, a pen or a finger. Opening the editor again shows the
whole screenshot with the markings and the crop frame; markings can still be
undone and the crop changed. :guilabel:`Retake` and :guilabel:`Remove` discard
markings and crop.

..  important::
    Only the marked-up image is sent. Blacked-out areas are removed in the
    browser, the original screenshot never reaches the server.
