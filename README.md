# TYPO3 extension `opinion`

Collects feedback from editors: a description, a category and severity, an
optional screenshot of the current browser tab and details about browser and
TYPO3. Every submission is stored as a record and delivered by email.

The dialog opens from two places:

- **Frontend:** admin panel tab "Feedback" with a button "Send feedback". The
  page, site, language and workspace are attached.
- **Backend:** speech-bubble button in the toolbar. The current module, page
  and module URL are attached.

Both are only shown to users who may send feedback (see below).

Before sending, the screenshot can be marked up in a full-screen editor: frame
an area with a rectangle, point at something with an arrow, or black out
personal data. The markings are burnt into the image; only the marked-up image
is sent.

Admins find all feedback in the module **Admin Tools → Feedback**: a list,
newest first, and a detail view with message, screenshot and all collected
details.

## Requirements

- TYPO3 v14.3, PHP 8.4
- HTTPS for screenshots (`getDisplayMedia()` needs a secure context)

## Installation

```bash
composer require vkemeter/opinion
vendor/bin/typo3 extension:setup
```

`extension:setup` creates the table `tx_opinion_feedback` and the file storage
"Opinion screenshots" (see below).

## Configuration

Admin Tools → Settings → Extension Configuration → `opinion`:

| Setting | Default | Meaning |
|---|---|---|
| `recipients` | – | Comma-separated email addresses. Invalid ones are ignored. |
| `subjectPrefix` | `[Opinion]` | Prefix of the email subject |
| `retentionDays` | `180` | Age after which `opinion:cleanup` deletes feedback |
| `enabled` | `1` | `0` hides the triggers and rejects submissions |

Without valid recipients the feedback is still stored, its delivery status is
"failed".

### Collected details

The tab "Collected details" of the extension configuration has a switch for
every detail, grouped into browser, server and page/module (e.g. IP address,
screen size, time zone, images on the page). All are on by default. A
switched-off detail is not collected by the browser and is removed on the
server, even if a request sends it. The sender (name, username, email) is
always kept.

The sender address is `$GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress']`;
the sending backend user becomes reply-to.

### Who may send feedback

Admins always. Other backend users need this user or group TSconfig:

```typoscript
options.opinion.enable = 1
```

Sending feedback also needs a valid email address in the user account, so the
team can answer. Users without one see the button, but get a note with a link
to their user settings instead of the form; the endpoint rejects their
submissions as well.

For the frontend button the admin panel itself must be shown:

- TypoScript `config.admPanel = 1` on the site
- user TSconfig with at least one enabled admin panel module, e.g.
  `admPanel.enable.opinion = 1` (admins get `admPanel.enable.all = 1` from the
  core)

The "Feedback" tab only appears for users who may send feedback.

### Cleanup

```bash
vendor/bin/typo3 opinion:cleanup             # older than retentionDays
vendor/bin/typo3 opinion:cleanup --days=30
vendor/bin/typo3 opinion:cleanup --dry-run
```

To run it regularly, add a Scheduler task "Execute console commands" with the
command `opinion:cleanup`.

## Data and privacy

- Records live on the root level (`pid = 0`), read-only, and are shown in the
  admin module "Feedback" only, not in the List module.
- Screenshots are stored in a local, **non-public** FAL storage below
  `var/opinion/`, outside the web root. TYPO3 serves them through the
  `dumpFile` URL only; a listener answers `403` unless the request carries an
  admin session.
- Blacked-out areas are burnt into the screenshot in the browser; the original
  capture never leaves the page.
- The browser sends a fixed set of environment details (URL, title, user agent,
  language, time zone, screen and viewport size, pixel ratio, color scheme,
  reduced motion, online state). The server drops every other key. Cookies,
  `localStorage` and `sessionStorage` are never read.
- Feedback from the admin panel also contains the details of its tab
  "Info → General": page type, cache state and reasons, uncached elements,
  parse time, document size, simulated frontend user and the images on the
  page. Images and document size are only known when the page was rendered
  without cache, like in the admin panel itself. Cookies, server variables and
  request parameters of that tab are not taken.
- The IP address is stored anonymised.
- `opinion:cleanup` removes records, references and screenshot files.

### Deployment

`var/opinion/` must survive deployments (shared directory). The storage is
configured relative to the public path (`../var/opinion/`), so it stays valid
when the release directory changes. The path is inside the project root, no
`BE.lockRootPath` entry is needed.

## Integration for further entry points

Build the dialog configuration on the server and pass it to the JavaScript
module:

```php
$configuration = $dialogConfigurationBuilder->build($backendUser, Source::Frontend, ['pageUid' => 12]);
```

```js
import { openOpinionDialog } from '@vkemeter/opinion/opinion-dialog.js';

openOpinionDialog(configuration);
```

Load `EXT:opinion/Resources/Public/Css/opinion-dialog.css` as well. Check
`AccessChecker::hasAccess()` before rendering a trigger.

Extension points:

- `ContextProviderInterface` validates the context an entry point sends
  along. Context of a source without a provider is dropped.
- `DeliveryChannelInterface` adds a delivery channel, e.g. a ticket system.
  All implementations are collected automatically.
- `ConfigurationProviderInterface` replaces the source of the configuration.

## Endpoint

`POST` to the backend AJAX route `opinion_submit`, `multipart/form-data`:
`message`, `category` (`bug|content|idea|other`), `severity`
(`low|normal|high`), `source` (`frontend|backend`), `clientInfo` (JSON),
`context` (JSON), `screenshot` (PNG/JPEG, at most 10 MB).

| Status | Meaning |
|---|---|
| `200` | Stored; `delivered` tells whether all channels succeeded |
| `401` | No backend session (answered by the core) |
| `403` | No access or extension disabled |
| `422` | Invalid input, `errors` holds a message per field |
| `503` | Screenshot storage unavailable |

## Development

The PHP suites run in `ghcr.io/typo3/core-testing-php84` containers (docker or
podman), no local PHP or Composer needed. Dependencies are installed into
`.Build/`.

```bash
Build/Scripts/runTests.sh -s composerUpdate          # install dependencies
Build/Scripts/runTests.sh -s unit
Build/Scripts/runTests.sh -s functional              # SQLite
Build/Scripts/runTests.sh -s functional -d mariadb   # also mysql, postgres
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl -n                  # php-cs-fixer dry run
Build/Scripts/runTests.sh -s lint
Build/Scripts/runTests.sh -h                         # all options
```

The browser test runs the dialog against a mocked endpoint in headless
Chromium, including the screenshot flow. Playwright comes from `Build/`:

```bash
npm --prefix Build ci
npx --prefix Build playwright install chromium
node Tests/Browser/dialog.test.mjs
```

GitHub Actions (`.github/workflows/ci.yml`) runs the same commands on every push
to `main` and on pull requests: lint, CGL and PHPStan; unit and functional tests
on PHP 8.4 with SQLite, MariaDB, MySQL and PostgreSQL and on PHP 8.5 with SQLite;
the browser test.

## Specification

The requirements and decisions are in [SPEC.md](SPEC.md) (capability map) and
the module specs `SPEC-opinion-*.md`, the implementation plan in
[tasks/plan.md](tasks/plan.md), the current development state in
[STATUS.md](STATUS.md).
