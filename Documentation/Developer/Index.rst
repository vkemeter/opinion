..  _developer:

================
Developer corner
================

..  _developer-architecture:

How a submission is processed
=============================

#.  An entry point (admin panel, backend toolbar) renders a trigger and the
    dialog configuration, built by
    :php:`\Vkemeter\Opinion\Dialog\DialogConfigurationBuilder`.
#.  The JavaScript dialog posts the form to the backend AJAX route
    `opinion_submit`.
#.  :php:`\Vkemeter\Opinion\Controller\SubmitController` checks access,
    builds and validates the submission and validates the context with the
    matching context provider.
#.  :php:`\Vkemeter\Opinion\Processing\SubmissionProcessor` stores the record
    and the screenshot, then runs every delivery channel and records the
    result. A failing channel never loses the submission.

..  _developer-entry-point:

Add an entry point
==================

Build the dialog configuration on the server, after checking access:

..  code-block:: php

    use Vkemeter\Opinion\Access\AccessChecker;
    use Vkemeter\Opinion\Dialog\DialogConfigurationBuilder;
    use Vkemeter\Opinion\Domain\Source;

    if ($this->accessChecker->hasAccess($backendUser)) {
        $configuration = $this->dialogConfigurationBuilder->build(
            $backendUser,
            Source::Frontend,
            ['pageUid' => 12],
        );
    }

Pass it to the page, load the dialog CSS
`EXT:opinion/Resources/Public/Css/opinion-dialog.css` and open the dialog with
the JavaScript module:

..  code-block:: javascript

    import { openOpinionDialog } from '@vkemeter/opinion/opinion-dialog.js';

    openOpinionDialog(configuration);

The import map entry `@vkemeter/opinion/` is available in the backend. The
context is validated again on submit by the context provider of the source;
keys that are switched off in the extension configuration are dropped.

..  _developer-delivery-channel:

Add a delivery channel
======================

Implement :php:`\Vkemeter\Opinion\Delivery\DeliveryChannelInterface`, for
example to create a ticket. Implementations are tagged automatically and run
next to the email channel for every stored submission. Report failures through
the result instead of throwing, they end up in the delivery log.

..  code-block:: php
    :caption: EXT:my_sitepackage/Classes/Opinion/TicketDeliveryChannel.php

    namespace MyVendor\MySitepackage\Opinion;

    use TYPO3\CMS\Core\Resource\File;
    use Vkemeter\Opinion\Configuration\OpinionConfiguration;
    use Vkemeter\Opinion\Delivery\DeliveryChannelInterface;
    use Vkemeter\Opinion\Delivery\DeliveryResult;
    use Vkemeter\Opinion\Domain\Submission;

    final readonly class TicketDeliveryChannel implements DeliveryChannelInterface
    {
        public function getIdentifier(): string
        {
            return 'ticket';
        }

        public function deliver(Submission $submission, ?File $screenshot, OpinionConfiguration $configuration): DeliveryResult
        {
            // Create the ticket from $submission->message, $submission->category, ...
            return DeliveryResult::success($this->getIdentifier(), 'Ticket created');
        }
    }

..  _developer-context-provider:

Validate the context of an entry point
======================================

The context an entry point sends along is untrusted. Implement
:php:`\Vkemeter\Opinion\Context\ContextProviderInterface` to validate it;
:php:`supports()` decides by the source (`frontend`, `backend`). Context of a
source without a provider is dropped. The extension ships providers for both
sources.

..  _developer-configuration-provider:

Replace the configuration source
================================

The configuration is read through
:php:`\Vkemeter\Opinion\Configuration\ConfigurationProviderInterface`; the
default implementation reads the extension configuration. To read it from
somewhere else, implement the interface and point the alias to your class in
a package that depends on `vkemeter/opinion`:

..  code-block:: yaml
    :caption: EXT:my_sitepackage/Configuration/Services.yaml

    services:
      Vkemeter\Opinion\Configuration\ConfigurationProviderInterface:
        alias: MyVendor\MySitepackage\Opinion\ConfigurationProvider

..  _developer-endpoint:

Submit endpoint
===============

`POST` to the backend AJAX route `opinion_submit` as `multipart/form-data`.
The route URL contains the request token and is part of the dialog
configuration (`endpoint`).

..  list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Field
        -   Content
    *   -   `message`
        -   10 to 5000 characters
    *   -   `category`
        -   `bug`, `content`, `idea` or `other`
    *   -   `severity`
        -   `low`, `normal` or `high`
    *   -   `source`
        -   `frontend` or `backend`
    *   -   `clientInfo`
        -   JSON, details collected by the browser
    *   -   `context`
        -   JSON, context of the entry point
    *   -   `screenshot`
        -   Optional, PNG or JPEG, at most 10 MB

..  list-table::
    :header-rows: 1
    :widths: 25 75

    *   -   Status
        -   Meaning
    *   -   `200`
        -   Stored; `delivered` tells whether all channels succeeded
    *   -   `401`
        -   No backend session (answered by the core)
    *   -   `403`
        -   No access or extension disabled
    *   -   `422`
        -   Invalid input, `errors` holds a message per field
    *   -   `503`
        -   Screenshot storage unavailable

..  _developer-tests:

Running the tests
=================

The `Makefile` bundles all tasks, `make help` lists them:

..  code-block:: bash

    make install                       # Composer and Playwright dependencies
    make test                          # unit, functional and browser tests
    make test-functional DB=mariadb    # DB=sqlite|mariadb|mysql|postgres, PHP=8.4|8.5
    make check                         # lint, coding guidelines, PHPStan, documentation
    make fix                           # fix the coding guidelines
    make ci                            # everything the CI runs

The targets wrap the following commands.
The PHP suites run in `ghcr.io/typo3/core-testing-*` containers with docker
or podman, no local PHP or Composer needed:

..  code-block:: bash

    Build/Scripts/runTests.sh -s composerUpdate
    Build/Scripts/runTests.sh -s unit
    Build/Scripts/runTests.sh -s functional -d mariadb
    Build/Scripts/runTests.sh -s phpstan
    Build/Scripts/runTests.sh -s cgl -n
    Build/Scripts/runTests.sh -h

The browser test runs the dialog against a mocked endpoint in headless
Chromium:

..  code-block:: bash

    npm --prefix Build ci
    npx --prefix Build playwright install chromium
    node Tests/Browser/dialog.test.mjs

Render this documentation locally:

..  code-block:: bash

    docker run --rm --pull always -v $(pwd):/project -it \
        ghcr.io/typo3-documentation/render-guides:latest --config=Documentation

The result is in `Documentation-GENERATED-temp/Index.html`.
