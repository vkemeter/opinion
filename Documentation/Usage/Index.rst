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

:guilabel:`Black out`
    Covers an area with black, for example personal data that must not be
    sent.

Colour
    Red, yellow or blue for rectangle and arrow. Black out is always black.

:guilabel:`Undo` (also :kbd:`Ctrl` + :kbd:`Z` or :kbd:`Cmd` + :kbd:`Z`)
    Removes the last marking.

:guilabel:`Clear all`
    Removes all markings.

:guilabel:`Apply`
    Burns the markings into the screenshot and returns to the dialog.

:guilabel:`Cancel` (also :kbd:`Esc`)
    Returns to the dialog without changes.

Draw with the mouse, a pen or a finger. Opening the editor again shows the
markings, they can still be undone. :guilabel:`Retake` starts without markings.

..  important::
    Only the marked-up image is sent. Blacked-out areas are removed in the
    browser, the original screenshot never reaches the server.
