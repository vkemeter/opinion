..  _installation:

============
Installation
============

..  _installation-composer:

Install with Composer
=====================

..  code-block:: bash

    composer require vkemeter/opinion

The extension is not available in the TYPO3 Extension Repository (TER), only
with Composer.

..  _installation-setup:

Set up the extension
====================

..  code-block:: bash

    vendor/bin/typo3 extension:setup

This creates

*   the table `tx_opinion_feedback` for the feedback records,
*   the file storage "Opinion screenshots" with the base path `var/opinion/`.

Afterwards configure at least the email recipients, see
:ref:`configuration-extension`.

..  _installation-storage:

Screenshot storage
==================

Screenshots are stored in a local, non-public file storage below
`var/opinion/` in the project root, outside the web root. The storage is
configured relative to the public path (`../var/opinion/`), so it stays valid
when a deployment switches the release directory. As the path is inside the
project root, no entry in `$GLOBALS['TYPO3_CONF_VARS']['BE']['lockRootPath']`
is needed.

..  _installation-deployment:

Deployment
==========

`var/opinion/` must survive deployments. Configure it as a shared and
writable directory of your deployment tool, for example with Deployer:

..  code-block:: php
    :caption: deploy.php

    add('shared_dirs', ['var/opinion']);
    add('writable_dirs', ['var/opinion']);

..  _installation-admin-panel:

Frontend: enable the admin panel
================================

The frontend entry point lives in the admin panel, which has to be shown for
the user:

*   TypoScript of the site:

    ..  code-block:: typoscript

        config.admPanel = 1

*   User TSconfig with at least one enabled admin panel module. Administrators
    get `admPanel.enable.all = 1` from the core, other users need for example:

    ..  code-block:: typoscript

        admPanel.enable.opinion = 1
