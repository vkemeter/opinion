..  _known-problems:

==============
Known problems
==============

*   Screenshots need HTTPS and a browser with screen capture
    (`getDisplayMedia()`). Without it the dialog works, but without a
    screenshot.
*   Videos need `MediaRecorder` in addition. Chrome records MP4, other
    browsers may record WebM, which players show without total length.
*   QuickTime cannot play the MP4 files Chrome records. Watch them in the
    module :guilabel:`Feedback` or open a download with Chrome or VLC.
*   A full reload of the backend (logout, switching the user) ends a
    recording; leaving or reloading a frontend page too.
*   Large videos need matching PHP upload limits, see
    :ref:`configuration-video-upload-limits`.
*   The extension is tested in Chrome. Firefox and Safari are not tested yet.
*   Drawing markings on the screenshot needs a pointer (mouse, pen or touch);
    all other controls work with the keyboard.
*   Images on the page and the document size are only part of the admin panel
    details when the page was rendered without cache.

Report problems on
`GitHub <https://github.com/vkemeter/opinion/issues>`__.
