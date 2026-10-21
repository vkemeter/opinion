..  _installation:

============
Installation
============

..  _installation-composer:

Install with Composer
=====================

..  code-block:: bash

    composer require vkemeter/opinion

..  _installation-ter:

Install from the TER
====================

Without Composer, install the extension from the
`TYPO3 Extension Repository <https://extensions.typo3.org/extension/opinion>`__
in :guilabel:`Admin Tools > Extensions`. Activating it creates the table and
the screenshot storage, the next section is not needed then.

..  warning::

    Without Composer, the TYPO3 `var/` directory is :file:`typo3temp/var/`
    inside the web root, and the screenshots are stored in
    :file:`typo3temp/var/opinion/`. The `.htaccess` shipped with TYPO3 denies
    access to :file:`typo3temp/var/` on Apache; on other web servers such as
    nginx, deny it in the server configuration.

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

*   The site set :guilabel:`Opinion: editor feedback` (`vkemeter/opinion`)
    sets `config.admPanel = 1`. Add it to the site, in the site configuration
    or in :file:`config/sites/<site>/config.yaml`:

    ..  code-block:: yaml
        :caption: config/sites/<site>/config.yaml

        dependencies:
          - vkemeter/opinion

    The site setting :guilabel:`Show the admin panel`
    (`opinion.adminPanel.enable`, default on) switches it off again for a
    single site. Without the set, add `config.admPanel = 1` to the TypoScript
    of the site yourself.

*   User TSconfig with at least one enabled admin panel module. Administrators
    get `admPanel.enable.all = 1` from the core, other users need for example:

    ..  code-block:: typoscript

        admPanel.enable.opinion = 1

    Site sets cannot provide user TSconfig, so this and
    :ref:`options.opinion.enable <configuration-access>` stay in the user or
    group records.
