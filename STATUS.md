# Development status

State of 2026-10-20. Read this first when picking the work up again.

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
| Review fixes (2026-10-20) | no error after a failed status update, URL parameter denylist `deniedUrlParameters`, fetch timeout, counter without live region, strict browser test. Tested by the user in Chrome, 2026-10-20 |
| Site set `vkemeter/opinion` | sets `config.admPanel` via the setting `opinion.adminPanel.enable`. Tested by the user, 2026-10-20 |

## Added beyond the original plan

All on request after the plan (T1–T12) was finished:

- admin panel and toolbar entry points (specs written afterwards)
- admin module "Feedback" (records could not be opened, the table is read-only)
- admin panel page information (only its `info` part; cookies, server variables
  and request parameters are left out)
- switches for every collected detail (`CollectableField`, `CollectionFilter`)
- email address mandatory for sending
- "Feedback" in the admin panel bar opens the dialog directly instead of the
  panel with its button (2026-10-13, tested by the user in the project)
- fixes from an external review and the site set (2026-10-20, see below)

## Open

- From the external review (2026-10-20), agreed with the user:
  - next branch: command `opinion:redeliver` for failed/pending deliveries
    (open: limit of attempts?), per-user rate limit on the submit route
  - cleanup: drop `public: true` where not needed, replace `$GLOBALS` where a
    request is available, ESLint, move the planning files before a TER release
  - kept on purpose: `ConfigurationProviderInterface` and
    `AdminRequestCheckerInterface`, the tests stub the final classes through them
  - known limitation: the annotation editor needs a pointer, no keyboard
  - idea: a status report checking table and screenshot storage (a reset
    database without `extension:setup` only showed the screenshot message)
- Manual check: setting up the cleanup task in the Scheduler.
- Firefox and Safari are not tested; not needed for now (user decision
  2026-10-13), Chrome is the browser in use.
- Possible next steps, not requested yet: ticket system delivery channel.
  A delete action in the admin module is not needed, `opinion:cleanup` covers it.

## Standalone setup (2026-10-13)

- Dev dependencies in `composer.json`, installed into `.Build/` (vendor, bin,
  public).
- `Makefile`: wraps all tasks (`install`, `test*`, `check`, `lint`, `cgl`,
  `phpstan`, `fix`, `docs`, `docs-test`, `ci`, `clean`); `PHP`, `DB`,
  `CONTAINER` as variables.
- `Build/Scripts/runTests.sh`: docker/podman runner with the suites
  `composerUpdate`, `unit`, `functional` (`-d sqlite|mariadb|mysql|postgres`),
  `phpstan`, `cgl`, `lint`.
- `Build/phpunit/*.xml` use the bootstraps of `typo3/testing-framework`.
- `Build/phpstan/phpstan.neon`: level 8, strict rules, no baseline.
- `.php-cs-fixer.php`: rules taken over from the old project (PER-CS 3.0 based).
- `Build/package.json`: Playwright for `Tests/Browser/dialog.test.mjs`.
- `Documentation/`: TYPO3 documentation (reST, `guides.xml`), rendered with
  `ghcr.io/typo3-documentation/render-guides`; screenshots in
  `Documentation/Images/` taken with Playwright from the real dialog. Publishing
  on docs.typo3.org needs the extension in the TER and approval by the
  documentation team, then the webhook `https://docs-hook.typo3.org`.
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
  `config.admPanel = 0`; it wins over the opinion site set, which is a
  dependency of the theme set and loaded before it. Not to be changed for now
  (user decision 2026-10-20).

## Checks

`make ci` runs all of them (`make help` for single tasks).

```bash
Build/Scripts/runTests.sh -s composerUpdate
Build/Scripts/runTests.sh -s unit                    # 75 tests
Build/Scripts/runTests.sh -s functional              # 57 tests
Build/Scripts/runTests.sh -s functional -d mariadb
Build/Scripts/runTests.sh -s phpstan
Build/Scripts/runTests.sh -s cgl -n
Build/Scripts/runTests.sh -s lint
node Tests/Browser/dialog.test.mjs                   # 11 scenarios
docker run --rm -v $(pwd):/project ghcr.io/typo3-documentation/render-guides:latest --config=Documentation --minimal-test
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
