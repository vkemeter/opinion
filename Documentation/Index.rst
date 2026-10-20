..  _start:

=======
Opinion
=======

:Extension key:
    opinion

:Package name:
    vkemeter/opinion

:Version:
    |release|

:Language:
    en

:Author:
    Volker Kemeter

:License:
    This document is published under the
    `Open Publication License <https://www.opencontent.org/openpub/>`__.

:Rendered:
    |today|

----

..  image:: /Images/Opinion-Logo.svg
    :alt: Opinion
    :width: 212px
    :class: mb-4

Editors report problems on the page they are looking at: a short description,
a category and severity, an optional screenshot of the current browser tab that
can be marked up, and details about browser, page and TYPO3. Every submission is
stored as a record and delivered by email.

----

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What the extension does, where the dialog opens and which data it
        collects.

    ..  card:: :ref:`Installation <installation>`

        Install the extension with Composer, set up the database table and
        the screenshot storage.

    ..  card:: :ref:`Configuration <configuration>`

        Email recipients, retention, collected details and who may send
        feedback.

    ..  card:: :ref:`Usage <usage>`

        Send feedback from the admin panel or the backend toolbar, take and
        mark up a screenshot.

    ..  card:: :ref:`Administration <administration>`

        Read feedback in the admin module, clean up old records, data and
        privacy.

    ..  card:: :ref:`Developer corner <developer>`

        Add entry points or delivery channels, the submit endpoint, running
        the tests.

..  toctree::
    :caption: Table of contents
    :hidden:
    :titlesonly:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Administration/Index
    Developer/Index
    KnownProblems/Index

..  Meta Menu

..  toctree::
    :hidden:

    Sitemap
