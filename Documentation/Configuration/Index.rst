..  _configuration:

=============
Configuration
=============

..  _configuration-extension:

Extension configuration
=======================

:guilabel:`Admin Tools > Settings > Extension Configuration > opinion`

..  confval-menu::
    :name: confval-menu-extension-configuration
    :display: table
    :type:
    :default:

..  confval:: recipients
    :name: extconf-recipients
    :type: string
    :default: (empty)

    Comma-separated email addresses that receive the feedback. Invalid
    addresses are ignored. Without a valid recipient the feedback is still
    stored, its delivery status is "failed".

..  confval:: subjectPrefix
    :name: extconf-subjectprefix
    :type: string
    :default: `[Opinion]`

    Prefix of the email subject. The subject continues with category,
    severity and the beginning of the message, for example
    `[Opinion] [bug/high] The teaser image is cropped`.

..  confval:: retentionDays
    :name: extconf-retentiondays
    :type: int
    :default: 180

    Age in days after which :ref:`opinion:cleanup <administration-cleanup>`
    deletes feedback records and their screenshots.

..  confval:: enabled
    :name: extconf-enabled
    :type: boolean
    :default: 1

    `0` hides the feedback triggers for everybody and rejects submissions.

..  _configuration-email:

Email sender
------------

The email is sent from
`$GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']`. The backend
user who sent the feedback becomes reply-to, so the team can answer directly.

..  _configuration-collected-details:

Collected details
=================

The tab :guilabel:`Collected details` of the extension configuration has a
switch for every detail that is attached to the feedback. All are on by
default. A switched-off detail is not collected by the browser and is removed
on the server, even if a request sends it.

The sender (name, username, email address) is always kept, the team needs it
to answer.

..  _configuration-collected-details-browser:

Browser
-------

Collected by the browser. Cookies, `localStorage` and `sessionStorage` are
never read.

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   Setting
        -   Detail
    *   -   `collect_client_url`
        -   Page URL
    *   -   `collect_client_title`
        -   Page title
    *   -   `collect_client_userAgent`
        -   User agent
    *   -   `collect_client_userAgentData`
        -   Browser brands, platform, mobile (User-Agent Client Hints)
    *   -   `collect_client_language`
        -   Browser language
    *   -   `collect_client_languages`
        -   Preferred languages
    *   -   `collect_client_timezone`
        -   Time zone
    *   -   `collect_client_screen`
        -   Screen size and color depth
    *   -   `collect_client_viewport`
        -   Viewport size
    *   -   `collect_client_devicePixelRatio`
        -   Device pixel ratio
    *   -   `collect_client_colorScheme`
        -   Color scheme (light/dark)
    *   -   `collect_client_reducedMotion`
        -   Reduced motion preference
    *   -   `collect_client_online`
        -   Online state
    *   -   `collect_client_timestamp`
        -   Time in the browser

..  _configuration-collected-details-server:

Server
------

Determined on the server, never taken from the request body.

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   Setting
        -   Detail
    *   -   `collect_system_typo3Version`
        -   TYPO3 version
    *   -   `collect_system_applicationContext`
        -   Application context
    *   -   `collect_system_phpVersion`
        -   PHP version
    *   -   `collect_system_ipAddress`
        -   IP address (anonymised)
    *   -   `collect_system_requestUserAgent`
        -   User agent sent to the server

..  _configuration-collected-details-context:

Page and module
---------------

Sent by the entry point and validated on the server. Entries marked
"admin panel" come from the admin panel tab :guilabel:`Info > General`; images
and document size are only known when the page was rendered without cache,
like in the admin panel itself.

..  list-table::
    :header-rows: 1
    :widths: 40 60

    *   -   Setting
        -   Detail
    *   -   `collect_context_page`
        -   Page uid and title
    *   -   `collect_context_site`
        -   Site (frontend)
    *   -   `collect_context_language`
        -   Language (frontend)
    *   -   `collect_context_workspace`
        -   Workspace (frontend)
    *   -   `collect_context_module`
        -   Module (backend)
    *   -   `collect_context_url`
        -   Module URL (backend)
    *   -   `collect_context_pageType`
        -   Page type (admin panel)
    *   -   `collect_context_cacheState`
        -   Cache state and reasons (admin panel)
    *   -   `collect_context_uncachedElements`
        -   Number of uncached elements (admin panel)
    *   -   `collect_context_parseTime`
        -   Parse time (admin panel)
    *   -   `collect_context_documentSize`
        -   Document size (admin panel)
    *   -   `collect_context_frontendUser`
        -   Simulated frontend user and groups (admin panel)
    *   -   `collect_context_imagesOnPage`
        -   Images on the page (admin panel)

..  _configuration-collected-details-url:

URLs
----

..  confval:: deniedUrlParameters
    :name: extconf-deniedurlparameters
    :type: string
    :default: `ADMCMD_*,logintype,user,pass,redirect_url,tx_felogin_login`

    Query parameters that are removed from the page URL (browser) and the
    module URL (backend) before the feedback is stored and sent, so preview,
    login or session parameters do not end up in the email. Comma-separated
    and case-insensitive; a trailing `*` matches a prefix, `ADMCMD_*` covers
    all preview parameters. For an array parameter like
    `tx_felogin_login[hash]` the base name `tx_felogin_login` is matched. All
    other parameters and the fragment are kept as sent.

    The request token of backend URLs (`token`) is always removed. An empty
    value removes nothing else.

..  _configuration-access:

Who may send feedback
=====================

Administrators always may. Other backend users need the option
`options.opinion.enable` in their user or group TSconfig. It shows the feedback
triggers to the user and accepts their submissions:

..  code-block:: typoscript
    :caption: User or group TSconfig

    options.opinion.enable = 1

Sending feedback also needs a valid email address in the user account, so the
team can answer. Users without one still see the trigger, but get a note with
a link to their user settings instead of the form; the endpoint rejects their
submissions as well.
