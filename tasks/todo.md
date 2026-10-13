# Tasks: opinion-core

Plan: [plan.md](plan.md) · Spec: [SPEC-opinion-core.md](../SPEC-opinion-core.md)

Every task also clears the standing bar: `composer check:stan`, `composer check:cs` and the
relevant tests green, English code, translated labels, no baseline entries.

---

## T1: Package skeleton installed — DONE (33dd912)

**Description:** Create `packages/opinion` with `composer.json` (`vkemeter/opinion`, PSR-4
`Vkemeter\Opinion\` → `Classes/`, ext key `opinion`, requires `typo3/cms-core` + `typo3/cms-backend`
`^14.3`), `Configuration/Services.yaml`, `Configuration/Icons.php` + icon, `ext_conf_template.txt`
(`recipients`, `subjectPrefix`, `retentionDays`, `enabled`) and `locallang.xlf` / `de.locallang.xlf`
with the config labels. Require it in the root `composer.json` and install.

**Acceptance criteria:**
- [ ] `ddev composer update vkemeter/opinion` installs the package; extension `opinion` is active.
- [ ] The four settings appear under Admin Tools → Settings → Extension Configuration with labels.

**Verification:**
- [ ] `ddev exec 'vendor/bin/typo3 extension:list' | grep opinion`
- [ ] `composer lint`, `composer normalize --dry-run` (part of `composer check`)

**Dependencies:** None

**Files likely touched:** `packages/opinion/composer.json`, `packages/opinion/ext_conf_template.txt`,
`packages/opinion/Configuration/Services.yaml`, `packages/opinion/Configuration/Icons.php`,
`packages/opinion/Resources/Private/Language/{,de.}locallang.xlf`, `composer.json`

**Estimated scope:** M

---

## T2: Access check and endpoint skeleton (incl. frontend spike) — DONE (d1ef634), spike open

**Description:** `AccessChecker` (admin → allowed; non-admin → `options.opinion.enable = 1` in user
TSconfig; `enabled = false` → nobody). Register the AJAX route `opinion_submit` and a
`SubmitController` that returns 401 (no backend user), 403 (no access) or a placeholder 200 JSON.
Spike: render the tokenised URL on a frontend page (temporary, not committed) and `fetch` it with
the backend session — decide backend route vs. frontend fallback and note the result in `plan.md`.

**Acceptance criteria:**
- [ ] Unit tests cover admin, non-admin with/without TSconfig, and disabled extension.
- [ ] POST from a frontend page with backend session reaches the controller (or the fallback is decided and documented).
- [ ] Without backend session the response is 401 JSON.

**Verification:**
- [ ] `composer test:unit`
- [ ] Manual: `fetch(url, {method: 'POST'})` in the browser console on `https://qscience.fonda.at.ddev.site/` logged in / logged out

**Dependencies:** T1

**Files likely touched:** `Classes/Access/AccessChecker.php`, `Classes/Controller/SubmitController.php`,
`Configuration/Backend/AjaxRoutes.php`, `Tests/Unit/Access/AccessCheckerTest.php`

**Estimated scope:** M

---

## T3: Non-public screenshot storage — DONE (0743800)

**Description:** `CreateScreenshotStorage` listener on `PackageInitializationEvent` creates a local
FAL storage "Opinion screenshots" (`is_public = 0`, base path `var/opinion/` relative to the project
root) if missing. `ScreenshotStorage` service stores a validated image blob as
`YYYY/MM/<uuid>.<ext>` and returns the `File`; it throws a dedicated exception if the storage is
missing or not writable. Add `var/opinion` to `BE.lockRootPath` (`config/system/additional.php`) and
to Deployer `shared_dirs` + `writable_dirs` (`build/deployment/custom_tasks.php`).

**Acceptance criteria:**
- [ ] `extension:setup` creates exactly one storage, a second run creates none.
- [ ] Stored files live below `<project>/var/opinion/`, not below `public/`, and the storage is non-public.
- [ ] Functional tests cover creation, idempotency and storing a file.

**Verification:**
- [ ] `composer test:functional`
- [ ] Manual: `ddev exec 'vendor/bin/typo3 extension:setup'`, storage visible in List module (root), test file not reachable by any URL

**Dependencies:** T1

**Files likely touched:** `Classes/EventListener/CreateScreenshotStorage.php`,
`Classes/Service/ScreenshotStorage.php`, `config/system/additional.php`,
`build/deployment/custom_tasks.php`, `Tests/Functional/Service/ScreenshotStorageTest.php`

**Estimated scope:** M

---

## Checkpoint A — after T1–T3
- [ ] `composer test:unit`, `composer test:functional`, `composer check:stan` green
- [ ] Endpoint decision (backend route / fallback) documented in `plan.md`
- [ ] Review with the user before Phase 2

---

## T4: Enums, Submission DTO and validator — DONE

**Description:** Backed enums `Category` (`bug|content|idea|other`), `Severity` (`low|normal|high`),
`Source` (`frontend|backend`); readonly `Submission` DTO; `SubmissionValidator` returning per-field
errors (message 10–5000 chars after trim, enum values, screenshot PNG/JPEG by magic bytes, ≤ 10 MB).

**Acceptance criteria:**
- [ ] Invalid values yield field-keyed error codes, valid input yields none.
- [ ] A file with PNG extension/MIME but other content is rejected.

**Verification:**
- [ ] `composer test:unit` (data providers for bounds and enum values)

**Dependencies:** T1

**Files likely touched:** `Classes/Domain/{Category,Severity,Source,Submission}.php`,
`Classes/Service/SubmissionValidator.php`, `Tests/Unit/Service/SubmissionValidatorTest.php`

**Estimated scope:** M

---

## T5: Client info sanitizer, system info collector and submission factory — DONE

**Description:** `ClientInfoSanitizer` keeps only whitelisted keys with type coercion and length caps.
`SystemInfoCollector` builds server-side data (TYPO3/PHP version, application context, backend user,
time, anonymised IP). `ContextProviderInterface` for entry-point context. `SubmissionFactory` builds a
`Submission` from the PSR-7 request using validator, sanitizer and collector.

**Acceptance criteria:**
- [ ] Unknown keys and any `cookie`/`storage` data are dropped (unit test).
- [ ] System info never takes values from the request body.
- [ ] Factory returns either a `Submission` or validation errors.

**Verification:**
- [ ] `composer test:unit`

**Dependencies:** T4

**Files likely touched:** `Classes/Service/{ClientInfoSanitizer,SystemInfoCollector,SubmissionFactory}.php`,
`Classes/Context/ContextProviderInterface.php`, `Tests/Unit/Service/ClientInfoSanitizerTest.php`

**Estimated scope:** M

---

## T6: Feedback record (TCA) and repository — DONE

**Description:** TCA for `tx_opinion_feedback` (root level, read-only fields, JSON fields, file field
`screenshot`). `FeedbackRepository::add()` inserts the record and the `sys_file_reference`;
`updateDeliveryStatus()` writes `delivery_status` and `delivery_log`.

**Acceptance criteria:**
- [ ] `extension:setup` creates the table; records appear at root level in the List module for admins.
- [ ] Functional test: add with and without screenshot, status update.

**Verification:**
- [ ] `composer test:functional`
- [ ] Manual: record opens read-only, screenshot preview loads via file dump

**Dependencies:** T3, T5

**Files likely touched:** `Configuration/TCA/tx_opinion_feedback.php`,
`Classes/Repository/FeedbackRepository.php`, `locallang.xlf` (+ `de.`),
`Tests/Functional/Repository/FeedbackRepositoryTest.php` (+ fixtures)

**Estimated scope:** M

---

## T7: Submission processor wired into the endpoint — DONE

**Description:** `SubmissionProcessor` persists the submission, runs all tagged delivery channels
(none yet → status `pending`) and records the result. `SubmitController` uses factory + processor:
422 with field errors, 200 with translated success JSON.

**Acceptance criteria:**
- [ ] Functional tests: 401, 403, 422 (nothing stored), 200 (record stored).
- [ ] `DeliveryChannelInterface` + `DeliveryResult` exist and are collected via `#[AutowireIterator]`.

**Verification:**
- [ ] `composer test:functional`
- [ ] Manual: multipart `fetch` from the browser console creates a record

**Dependencies:** T2, T6

**Files likely touched:** `Classes/Service/SubmissionProcessor.php`,
`Classes/Delivery/{DeliveryChannelInterface,DeliveryResult}.php`,
`Classes/Controller/SubmitController.php`, `Tests/Functional/Controller/SubmitControllerTest.php`

**Estimated scope:** M

---

## T8: Email delivery channel and extension configuration — DONE

**Description:** `ConfigurationProviderInterface`, `ExtensionConfigurationProvider`,
`OpinionConfiguration` DTO. `EmailDeliveryChannel` sends a `FluidEmail` (recipients, replyTo backend
user, subject `prefix [category/severity] <first 60 chars>`, inline + attached screenshot, data
tables) using `Resources/Private/Templates/Email/Opinion.fluid.html`.

**Acceptance criteria:**
- [ ] Mail transport failure → status `failed`, log entry, response "saved, not delivered".
- [ ] Missing/invalid recipients → status `failed`, log entry.
- [ ] Mail in Mailpit contains all fields and the inline screenshot; data is escaped.

**Verification:**
- [ ] `composer test:unit` (subject, config provider), `composer test:functional` (sent/failed)
- [ ] Manual: `ddev launch -m`

**Dependencies:** T7

**Files likely touched:** `Classes/Configuration/{ConfigurationProviderInterface,ExtensionConfigurationProvider,OpinionConfiguration}.php`,
`Classes/Delivery/EmailDeliveryChannel.php`, `Resources/Private/Templates/Email/Opinion.fluid.html`,
`locallang.xlf` (+ `de.`), tests

**Estimated scope:** M (tests counted separately)

---

## Checkpoint B — after T4–T8
- [ ] Full pipeline: browser-console multipart POST → record + screenshot in non-public storage + mail in Mailpit
- [ ] Failure path (broken transport) verified
- [ ] `composer test:unit`, `composer test:functional`, `composer check:stan` green
- [ ] Review with the user before Phase 3

---

## T9: Shared JS dialog with text submission — DONE

**Description:** `opinion-dialog.js` exports `openOpinionDialog({endpoint, source, context})`:
native `<dialog>`, textarea, category/severity selects, Send/Cancel, inline field errors from 422,
success/error state. `client-info.js` collects the whitelisted client data. `opinion-dialog.css`.
Register `@vkemeter/opinion/` in `Configuration/JavaScriptModules.php`.

**Acceptance criteria:**
- [ ] Text-only submission works end-to-end and shows the translated response.
- [ ] Keyboard only: open, fill, send, `Esc` closes; every control has a label.
- [ ] No `innerHTML` with data; Prettier clean.

**Verification:**
- [ ] Manual: on a frontend page with backend session, `const m = await import('<_assets URL>/JavaScript/opinion-dialog.js'); m.openOpinionDialog({...})`
- [ ] `composer check:prettier`

**Dependencies:** T8

**Files likely touched:** `Resources/Public/JavaScript/{opinion-dialog,client-info}.js`,
`Resources/Public/Css/opinion-dialog.css`, `Configuration/JavaScriptModules.php`, `locallang.xlf` (+ `de.`)

**Estimated scope:** M

---

## T10: Screenshot capture, preview, retake, remove — DONE

**Description:** `screenshot.js`: feature detection, `getDisplayMedia` with
`displaySurface: 'browser'` / `preferCurrentTab`, one frame to canvas, stop tracks, downscale to max
2560 px, JPEG 0.85 blob. Dialog hides itself while capturing, shows preview with Retake/Remove,
appends the blob to the form data.

**Acceptance criteria:**
- [ ] Preview/retake/remove work; the dialog is not visible in the screenshot.
- [ ] Permission denied or unsupported/insecure context → controls disabled with explanation, text submit still works.
- [ ] Mail shows the captured screenshot.

**Verification:**
- [ ] Manual matrix: Chrome, Firefox (Safari best effort); deny permission; HTTP context

**Dependencies:** T9

**Files likely touched:** `Resources/Public/JavaScript/screenshot.js`,
`Resources/Public/JavaScript/opinion-dialog.js`, `Resources/Public/Css/opinion-dialog.css`, `locallang.xlf` (+ `de.`)

**Estimated scope:** M

---

## T11: Cleanup command — DONE

**Description:** `CleanupCommand` (`opinion:cleanup`, `--days`, `--dry-run`) deletes records older
than N days (default `retentionDays`), their file references and screenshot files; summary output,
non-zero exit on errors.

**Acceptance criteria:**
- [ ] Only records older than N days are removed, including references and physical files.
- [ ] `--dry-run` reports but deletes nothing.
- [ ] Command is selectable in the Scheduler "Execute console commands" task.

**Verification:**
- [ ] `composer test:functional` (`Tests/Functional/Command/CleanupCommandTest.php`)
- [ ] Manual: `ddev exec 'vendor/bin/typo3 opinion:cleanup --dry-run'`, configure task in Scheduler

**Dependencies:** T6 (T8 for `retentionDays`)

**Files likely touched:** `Classes/Command/CleanupCommand.php`, `Classes/Repository/FeedbackRepository.php`,
`Tests/Functional/Command/CleanupCommandTest.php` (+ fixtures)

**Estimated scope:** S–M

---

## Checkpoint C — after T9–T11
- [ ] Dialog end-to-end with screenshot in Chrome and Firefox
- [ ] Cleanup task runs from the Scheduler
- [ ] Review with the user

---

## T12: README and full quality run — DONE

**Description:** `packages/opinion/README.md`: installation, extension configuration, user TSconfig
`options.opinion.enable`, scheduler task, storage/`lockRootPath`/deployment notes, privacy notes.
Run the full suite and walk through the spec's success criteria.

**Acceptance criteria:**
- [ ] README covers setup from zero on a new installation.
- [ ] Every success criterion in `SPEC-opinion-core.md` is checked off.

**Verification:**
- [ ] `composer check`, `composer test:unit`, `composer test:functional`

**Dependencies:** T1–T11

**Files likely touched:** `packages/opinion/README.md`, `SPEC.md` (status)

**Estimated scope:** S

---

## Checkpoint D — complete
- [ ] All acceptance criteria met, ready for review / PR
- [ ] Next: write `SPEC-opinion-adminpanel.md`
