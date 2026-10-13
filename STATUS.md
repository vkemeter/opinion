# Development status

State of 2026-10-09. Read this first when picking the work up again.

claude --resume fbdd807f-9a77-4ef0-a449-5549b97ed616

## Where the code lives

- Project: `q-science-space-typo3`, branch `feature/opinion-core` (local only,
  not pushed). No pull request: the extension will be extracted into its own
  project.
- Package: `packages/opinion` — Composer `vkemeter/opinion`, namespace
  `Vkemeter\Opinion\`, extension key `opinion`, TYPO3 v14.3, PHP 8.4.
- Documents: [SPEC.md](SPEC.md) (capability map), `SPEC-opinion-*.md`,
  [tasks/plan.md](tasks/plan.md), [tasks/todo.md](tasks/todo.md),
  [README.md](README.md).

## Done and tested by the user

| Feature | Notes |
|---|---|
| Feedback dialog | description, category, severity, screenshot (`getDisplayMedia`, preview/retake/remove) |
| Backend toolbar button | sends module, module URL, page |
| Admin panel tab "Feedback" | sends page, site, language, workspace and the "Info → General" data |
| Endpoint `opinion_submit` | backend AJAX route, also used from the frontend |
| Records + screenshots | root level, read-only; screenshots in a non-public FAL storage `var/opinion/` |
| Email delivery | recipients from the extension configuration, sender as reply-to |
| Admin module "Feedback" | list and detail view, the List module hides the table |
| Configurable details | one switch per field in the extension configuration (tab "Collected details") |
| Email address required | users without one get a notice with a link to the user settings |
| `opinion:cleanup` | retention period, `--days`, `--dry-run`, schedulable |

## Added beyond the original plan

All on request after the plan (T1–T12) was finished:

- admin panel and toolbar entry points (specs written afterwards)
- admin module "Feedback" (records could not be opened, the table is read-only)
- admin panel page information (only its `info` part; cookies, server variables
  and request parameters are left out)
- switches for every collected detail (`CollectableField`, `CollectionFilter`)
- email address mandatory for sending

## Open

- Manual checks: Firefox and Safari, setting up the cleanup task in the
  Scheduler.
- Possible next steps, not requested yet: delete action in the admin module,
  screenshot annotation (v2), ticket system delivery channel.

## Coupling to the project, to resolve when extracting

- `build/deployment/custom_tasks.php`: `var/opinion` is a shared and writable
  dir.
- `config/system/settings.php`: extension configuration values (`opinion`).
- `packages/f_theme/Configuration/Sets/BaseSet/setup.typoscript`:
  `config.admPanel` enabled by the user for the frontend button.
- Tests run with this project's setup: `build/phpunit/*.xml`,
  `build/Scripts/runTests.sh`, Playwright from `tests/playwright-base`.

## Checks

```bash
ddev exec 'vendor/bin/phpunit -c build/phpunit/UnitTests.xml packages/opinion/Tests/Unit'   # 65 tests
CI=true build/Scripts/runTests.sh -s functional                                             # 55 tests
CI=true build/Scripts/runTests.sh -s functional -d mariadb
node packages/opinion/Tests/Browser/dialog.test.mjs                                         # 7 scenarios
ddev composer check
```

All green on 2026-10-09. `composer test:*` needs a TTY, hence `CI=true`.
`composer check` reports `config/system/settings.php` whenever TYPO3 rewrote
it after saving the extension configuration.

## Things learned on the way

- The v14 admin panel renders only modules with submodules and short info as
  tabs.
- The admin panel loads module scripts as classic scripts; the dialog is
  imported dynamically.
- `dumpFile` checks only the HMAC; `RestrictScreenshotFileDump` adds the admin
  check, resolving the backend session itself (the eID runs before frontend
  authentication).
- JSON columns need explicit `Types::JSON` on insert, SQLite and MariaDB differ
  otherwise.
- FAL refuses folder reads on non-browsable storages, so the screenshot storage
  stays browsable.
- The project's Rector rules add random exception codes; pass fixed timestamp
  codes.
- MFA is required for all backend users, automated backend logins are not
  possible here.
