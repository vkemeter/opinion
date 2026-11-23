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

..  _usage-backend:

Backend: toolbar
================

In the backend, click the speech-bubble button in the toolbar. The current
module, the module URL and the selected page are attached.

When an administrator answers your feedback and you have access to the module
:guilabel:`Feedback`, its entry in the module menu shows a badge with the
number of feedback entries with new messages. Answer in the detail view of the
feedback, below the message (see :ref:`administration-module`).

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
    *Bug*, *Content*, *Idea* or *Other*.

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
    :alt: The editor with a rectangle, an arrow and a blacked-out area on the screenshot
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
