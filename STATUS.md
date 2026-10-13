# Development status

State of 2026-10-13. Read this first when picking the work up again.

## Where the code lives

- Own repository `vkemeter/opinion` (this directory), branch `main`. Extracted
  on 2026-10-13 from `packages/opinion` of the project `q-science-space-typo3`
  (branch `feature/opinion-core`, last commit `088c8c6`); the code is identical.
  The previous Claude session ran there: `fbdd807f-9a77-4ef0-a449-5549b97ed616`.
- Composer `vkemeter/opinion`, namespace `Vkemeter\Opinion\`, extension key
  `opinion`, TYPO3 v14.3, PHP 8.4.
- Documents: [SPEC.md](SPEC.md) (capability map), `SPEC-opinion-*.md`,
  [tasks/plan.md](tasks/plan.md), [tasks/todo.md](tasks/todo.md),
  [README.md](README.md). `tasks/` describes the work in the old project, paths
  there still read `packages/opinion`.

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
| Screenshot annotation (v2) | rectangle, arrow, black out, undo; burnt into the image. Tested by the user in Chrome, frontend and backend, 2026-10-13 |

## Added beyond the original plan

All on request after the plan (T1–T12) was finished:

- admin panel and toolbar entry points (specs written afterwards)
- admin module "Feedback" (records could not be opened, the table is read-only)
- admin panel page information (only its `info` part; cookies, server variables
  and request parameters are left out)
- switches for every collected detail (`CollectableField`, `CollectionFilter`)
- email address mandatory for sending
- "Feedback" in the admin panel bar opens the dialog directly instead of the
  panel with its button (2026-10-13, browser test; not yet tested by the user)

## Open

- Manual check: setting up the cleanup task in the Scheduler.
- Firefox and Safari are not tested; not needed for now (user decision
  2026-10-13), Chrome is the browser in use.
- Possible next steps, not requested yet: ticket system delivery channel.
  A delete action in the admin module is not needed, `opinion:cleanup` covers it.

## Standalone setup (2026-10-13)

- Dev dependencies in `composer.json`, installed into `.Build/` (vendor, bin,
  public).
- `Build/Scripts/runTests.sh`: docker/podman runner with the suites
  `composerUpdate`, `unit`, `functional` (`-d sqlite|mariadb|mysql|postgres`),
  `phpstan`, `cgl`, `lint`.
- `Build/phpunit/*.xml` use the bootstraps of `typo3/testing-framework`.
- `Build/phpstan/phpstan.neon`: level 8, strict rules, no baseline.
- `.php-cs-fixer.php`: rules taken over from the old project (PER-CS 3.0 based).
- `Build/package.json`: Playwright for `Tests/Browser/dialog.test.mjs`.
- `.github/workflows/ci.yml`: quality job, test matrix (PHP 8.4 × four databases,
  PHP 8.5 × SQLite) and the browser test; `runTests.sh -b docker`, because the
  runners also have podman.
- `var/` in the root is written by `ssch/typo3-debug-dump-pass` (via
  `phpstan-typo3`) and ignored.

## Left behind in the old project

To clean up there once it requires the extension from this repository:

- `build/deployment/custom_tasks.php`: `var/opinion` is a shared and writable
  dir. Every project using the extension needs this.
- `config/system/settings.php`: extension configuration values (`opinion`).
- `packages/f_theme/Configuration/Sets/BaseSet/setup.typoscript`:
  `config.admPanel` enabled by the user for the frontend button.

## Checks

```bash
Build/Scripts/runTests.sh -s composerUpdate
Build/Scripts/runTests.sh -s unit                    # 65 tests
Build/Scripts/runTests.sh -s functional              # 55 tests
Build/Scripts/runTests.sh -s functional -d mariadb
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl -n
Build/Scripts/runTests.sh -s lint
node Tests/Browser/dialog.test.mjs                   # 11 scenarios
```

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
