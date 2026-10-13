# Spec: opinion-core

Module of the [Opinion capability map](SPEC.md). Provides everything the entry points
(`opinion-adminpanel`, `opinion-backend-toolbar`) share. It has no UI trigger of its own.

## Objective

Editors (logged-in backend users) should be able to report a problem without leaving the page:
describe it, optionally attach a screenshot, pick a category and severity, and send. The team
receives an email with everything needed to reproduce the issue, and the submission is kept as a
record so nothing is lost when mail delivery fails. Old submissions are removed by a schedulable
cleanup command.

### User stories

- As an editor I open the feedback dialog, describe the problem, pick category and severity, and
  send it — I get a clear success or error message.
- As an editor I capture a screenshot of the current tab, see a preview, and can discard or retake
  it before sending.
- As a developer receiving the mail I see message, category, severity, sender, the screenshot, the
  client environment (browser, OS, viewport, …) and the TYPO3 context (version, context, site,
  page, language, workspace) without asking the editor.
- As an admin I configure recipients and retention once in the extension settings, decide via
  user TSconfig which non-admin users may send feedback, and schedule the cleanup.
- As an integrator I can later add another delivery channel (ticket system) or another
  configuration source without touching the submission flow.

### Acceptance criteria

1. **Access** — Admins may always submit. Non-admin backend users only if their user/group
   TSconfig contains `options.opinion.enable = 1` (default: disabled). Checked by an
   `AccessChecker` service used by the endpoint and by the entry points (to hide the trigger).
2. **Endpoint** — A single POST endpoint accepts `multipart/form-data` (fields + optional screenshot
   file). Without an authenticated backend user or valid request token → `401`; user without access
   → `403`. The response is JSON `{success: bool, title: string, message: string}` with translated
   texts.
3. **Validation** — `message` required, trimmed, 10–5000 chars; `category` ∈ `bug|content|idea|other`;
   `severity` ∈ `low|normal|high`; `source` ∈ `frontend|backend`. Screenshot optional, PNG or JPEG
   detected by content (not by client MIME), ≤ 10 MB. Client info is a JSON object limited to a
   whitelist of keys (see *Data*); unknown keys are dropped. Invalid input → `422` with per-field
   errors; nothing is stored.
4. **Persistence** — A valid submission is stored as a `tx_opinion_feedback` record on the root
   level (`pid = 0`, `rootLevel = 1`, so only admins see the records in the List module). The
   screenshot is stored as a FAL file in the **non-public** storage (see *Screenshot storage*) and
   referenced from the record. Records are read-only in FormEngine.
5. **Screenshot storage (mandatory non-public)** — A local FAL storage "Opinion screenshots" with
   `is_public = 0`, base path outside the web root (`var/opinion/`). Files are named
   `<uuid>.<ext>` in `YYYY/MM/` folders. The storage is created automatically on
   `extension:setup` (`PackageInitializationEvent` listener) if it does not exist; its uid is looked
   up by its resolved base path, not configured by hand. Files have no direct public URL; TYPO3
   serves them only through its HMAC-signed `dumpFile` URL, and a `ModifyFileDumpEvent` listener
   answers `403` for screenshots (and their processed variants) unless the request carries a fully
   authenticated admin session. If the storage is missing or
   not writable, submissions with a screenshot are rejected and logged; text-only submissions work.
6. **Delivery** — After persisting, all registered delivery channels run. v1 ships one: email.
   The outcome is written to the record (`delivery_status` = `sent` | `failed`, `delivery_log`).
   If the mail fails the user is told the feedback was saved but not delivered, and the error is
   logged (PSR-3, channel `opinion`).
7. **Email** — HTML `FluidEmail` to the configured recipients, `replyTo` = sending backend user
   (email + realName/username), subject = configured prefix + `[category/severity]` + first 60 chars
   of the message. Screenshot embedded inline and attached (read from the storage, never linked).
   Client/system data rendered as readable tables. Sender from the `MAIL` defaults.
8. **Configuration** — Read through a `ConfigurationProviderInterface`. v1 implementation reads the
   extension configuration (`ext_conf_template.txt`, Admin Tools → Settings):
   `recipients` (comma-separated emails, required), `subjectPrefix` (default `[Opinion]`),
   `retentionDays` (int, default `180`), `enabled` (bool, default `true`). Missing/invalid
   recipients → submission is stored but delivery fails with status `failed` and a log entry;
   `enabled = false` hides all triggers and the endpoint returns `403`.
9. **Cleanup command** — `vendor/bin/typo3 opinion:cleanup [--days=N] [--dry-run]` deletes records
   older than N days (default `retentionDays`), their file references and the screenshot files
   (hard delete). Prints a summary, returns non-zero on errors. Schedulable, i.e. selectable by an
   admin as "Execute console commands" task in the Scheduler.
10. **Shared JS dialog** — An ES module exposing `openOpinionDialog(options)` where `options` holds
    the endpoint URL, the source and an entry-point-specific `context` object. It renders a modal
    `<dialog>` with textarea, category and severity selects, "Take screenshot" / "Retake" /
    "Remove" buttons with preview, and Send/Cancel. Keyboard accessible (focus trap, `Esc`
    closes, labels on every control).
11. **Screenshot capture** — Uses `getDisplayMedia({video: {displaySurface: 'browser'},
    preferCurrentTab: true})`, grabs one frame into a canvas, stops all tracks immediately and keeps
    a JPEG blob. The dialog is hidden while capturing so it is not in the image. If the API is
    unavailable (no secure context, unsupported browser) or permission is denied, the screenshot
    controls are disabled with an explanation and text-only submission still works.
12. **Client info** — Collected in JS at submit time: see *Data*. No cookies, no
    `localStorage`/`sessionStorage`, no form contents of the page.
13. **Translations** — All UI texts, email labels and configuration labels in
    `Resources/Private/Language/locallang.xlf` (+ `de.locallang.xlf`), domain `opinion.messages`.

## Data

**Client info (JS, whitelisted keys):** `url`, `title`, `userAgent`, `userAgentData`
(`brands`, `platform`, `mobile`, when available), `language`, `languages`, `timezone`,
`screen` (`width`, `height`, `colorDepth`), `viewport` (`width`, `height`),
`devicePixelRatio`, `colorScheme`, `reducedMotion`, `online`, `timestamp`.

**System info (server, never trusted from the client):** TYPO3 version, application context,
PHP version, backend user uid/username/realName/email, submission time, request IP
(anonymised via `IpAnonymizationUtility`), plus the `context` object from the entry point —
validated server-side (page uid exists, site identifier known) by the entry point's context
provider.

**Record `tx_opinion_feedback`:** `crdate`, `source`, `category`, `severity`, `message`,
`screenshot` (file reference), `client_info` (JSON), `system_info` (JSON), `context` (JSON),
`be_user` (uid of sender), `delivery_status`, `delivery_log`. Schema generated from TCA,
no `ext_tables.sql`.

## Tech Stack

- TYPO3 v14.3 (`typo3/cms-core`, `typo3/cms-backend`), PHP 8.4
- Core Mail API (`FluidEmail`, `MailerInterface`), FAL (local driver, non-public storage),
  `ExtensionConfiguration`, `TcaSchemaFactory`, Symfony Console (`#[AsCommand]`)
- Native ES modules registered via `Configuration/JavaScriptModules.php` (import prefix
  `@vkemeter/opinion/`), no build step, no external JS dependencies
- No new Composer dependencies

## Commands

```bash
ddev composer update vkemeter/opinion           # install the path package
ddev exec 'vendor/bin/typo3 extension:setup'    # tables + screenshot storage
ddev exec 'vendor/bin/typo3 cache:flush'
ddev exec 'vendor/bin/typo3 opinion:cleanup --dry-run'
composer check:stan                             # PHPStan level 8
composer check:cs && composer fix:cs
composer lint                                   # xliff + yaml
composer check:prettier
composer test:unit
composer test:functional
composer check                                  # full suite before commit
```

Mails in DDEV are caught by Mailpit: `ddev launch -m`.

## Project Structure

```
packages/opinion/
├── composer.json                    → vkemeter/opinion, PSR-4 Vkemeter\Opinion\ → Classes/
├── ext_conf_template.txt            → recipients, subjectPrefix, retentionDays, enabled
├── Classes/
│   ├── Access/                      → AccessChecker (admin or options.opinion.enable)
│   ├── Command/                     → CleanupCommand (opinion:cleanup)
│   ├── Configuration/               → ConfigurationProviderInterface, ExtensionConfigurationProvider, OpinionConfiguration (readonly DTO)
│   ├── Context/                     → ContextProviderInterface (implemented by entry-point modules)
│   ├── Controller/                  → SubmitController (AJAX endpoint)
│   ├── Delivery/                    → DeliveryChannelInterface, EmailDeliveryChannel, DeliveryResult
│   ├── Domain/                      → Submission (readonly DTO), Category / Severity / Source (enums)
│   ├── EventListener/               → CreateScreenshotStorage (PackageInitializationEvent)
│   ├── Repository/                  → FeedbackRepository (DBAL + FAL references)
│   └── Service/                     → SubmissionFactory, SubmissionValidator, ClientInfoSanitizer, SystemInfoCollector, ScreenshotStorage, SubmissionProcessor
├── Configuration/
│   ├── Backend/AjaxRoutes.php       → opinion_submit
│   ├── JavaScriptModules.php
│   ├── TCA/tx_opinion_feedback.php
│   ├── Icons.php
│   └── Services.yaml
├── Resources/
│   ├── Private/Language/            → locallang.xlf, de.locallang.xlf
│   ├── Private/Templates/Email/     → Opinion.fluid.html
│   └── Public/
│       ├── JavaScript/              → opinion-dialog.js, screenshot.js, client-info.js
│       ├── Css/opinion-dialog.css
│       └── Icons/
├── Tests/Unit/
├── Tests/Functional/
└── README.md                        → installation, configuration, TSconfig, scheduler, storage/deployment notes
```

Entry-point modules add their own classes (`Classes/Adminpanel/`, `Classes/Backend/ToolbarItem/`)
and JS entry files to this same package.

## Code Style

Project conventions from `AGENTS.md` apply (PHPStan 8, English identifiers, constructor DI,
`#[AsEventListener]`, `TcaSchemaFactory`, timestamp exception codes, short label format). Example:

```php
final readonly class SubmissionProcessor
{
    /**
     * @param iterable<DeliveryChannelInterface> $deliveryChannels
     */
    public function __construct(
        private FeedbackRepository $feedbackRepository,
        #[AutowireIterator('opinion.delivery_channel')]
        private iterable $deliveryChannels,
        private LoggerInterface $logger,
    ) {}

    public function process(Submission $submission, OpinionConfiguration $configuration): ProcessingResult
    {
        $feedbackUid = $this->feedbackRepository->add($submission);

        $results = [];
        foreach ($this->deliveryChannels as $channel) {
            $results[] = $channel->deliver($submission, $configuration);
        }

        $this->feedbackRepository->updateDeliveryStatus($feedbackUid, $results);

        return new ProcessingResult($feedbackUid, $results);
    }
}
```

JS: ES modules, `const`/`let`, no globals, DOM built with `document.createElement` + `textContent`
(never `innerHTML` with data), formatted by Prettier.

## Testing Strategy

- **Unit** (`Tests/Unit/`, `composer test:unit`): `SubmissionValidator` (bounds, enums, screenshot
  magic bytes and size), `ClientInfoSanitizer` (whitelist, type coercion, length caps, no cookie /
  storage keys), `SubmissionFactory`, `AccessChecker` (admin, non-admin with/without TSconfig,
  `enabled = false`), email subject building, `ExtensionConfigurationProvider` with
  missing/invalid values. Data providers for edge cases.
- **Functional** (`Tests/Functional/`, `composer test:functional`):
  - `SubmissionProcessor` with a real DB — record + file reference persisted, `delivery_status`
    `sent` with a null mail transport, `failed` with a throwing transport.
  - `SubmitController` — 401 without backend user, 403 for a non-admin without TSconfig, 422 on
    invalid input, 200 on valid input.
  - Screenshot storage — created by the setup listener with `is_public = 0` and a base path outside
    the public path; idempotent on a second run.
  - `CleanupCommand` — deletes only records older than N days incl. references and files;
    `--dry-run` deletes nothing.
- **Manual (DDEV)**: dialog flow incl. screenshot preview/retake in Chrome and Firefox, permission
  denial, text-only submit, mail in Mailpit with inline screenshot, screenshot URL not reachable
  publicly, cleanup task configured and run in the Scheduler.
- Every class written gets a test unless it is pure wiring (Services.yaml, TCA).

## Boundaries

- **Always:** require an authenticated backend user, a valid token and access via `AccessChecker`;
  validate everything server-side; build system info on the server; keep screenshots in the
  non-public storage; escape all data in the email template and the dialog; run `composer check`
  and the tests before committing; English in code, translated labels in XLF.
- **Ask first:** new Composer or JS dependencies; changes outside `packages/opinion` (deployment
  shared dirs, `BE.lockRootPath`, `config/system/*`, root `composer.json` require); changing the
  record schema after it has shipped; storing additional personal data; changing the endpoint
  contract once an entry point uses it.
- **Never:** send or store cookies, session ids or tokens; store screenshots in a public storage or
  under the web root; trust client-provided user, page or system data; touch `packages/f_base/` or
  `_frontend/`; add entries to the PHPStan baseline.

## Success Criteria

Status after T12 (2026-10-09). ✅ automated test or verified in DDEV, ⏳ manual
check with a backend login still open.

- [x] `vkemeter/opinion` installs via the path repository; `extension:setup` creates `tx_opinion_feedback` and the non-public screenshot storage. ✅ DDEV + `ScreenshotStorageTest`
- [x] A functional test proves: valid submission → record + screenshot reference + mail sent → status `sent`. ✅ `SubmitControllerTest`, `SubmissionProcessorTest`, `EmailDeliveryChannelTest`
- [x] A functional test proves: mail transport failure → record kept, status `failed`, error logged, JSON says "saved, not delivered". ✅ `SubmissionProcessorTest`, `SubmitControllerTest::missingRecipientsStillStoreTheFeedback`
- [x] Requests without backend user/token → 401, non-admins without `options.opinion.enable = 1` → 403, invalid payloads → 422 and nothing stored. ✅ 401 by core (curl), 403/422 `SubmitControllerTest`
- [ ] A screenshot file cannot be fetched via any public URL; an admin can open it from the record in the backend. ✅ storage outside web root, `RestrictScreenshotFileDumpTest`; ⏳ admin preview in the backend
- [ ] A mail in Mailpit shows message, category, severity, sender, inline screenshot, client and system info. ✅ content in `EmailDeliveryChannelTest`; ⏳ rendering in Mailpit
- [ ] `opinion:cleanup` removes records, references and files older than the retention period and shows up as selectable command in the Scheduler. ✅ `CleanupCommandTest` incl. schedulable; ⏳ task set up in the Scheduler module
- [x] The dialog works from a plain test page calling `openOpinionDialog()` (screenshot, preview, retake, remove, send), and text-only submission works when `getDisplayMedia` is denied or unavailable. ✅ `Tests/Browser/dialog.test.mjs` (Chromium); ⏳ Firefox/Safari by hand
- [x] `composer check`, `composer test:unit`, `composer test:functional` pass. ✅ (`composer test:*` need a TTY or `CI=true`)
- [ ] ⏳ Endpoint token generated in the frontend is accepted (spike from T2), verified in `opinion-adminpanel`.

## Decisions

| Topic | Decision |
|---|---|
| PHP namespace | `Vkemeter\Opinion\` (StudlyCaps per TYPO3 coding guidelines; Composer/JS/ext key lowercase) |
| Configuration | Extension configuration for now, behind `ConfigurationProviderInterface` |
| Record location | Root level (`pid = 0`) — independent of sites/pages, admins only |
| Screenshot storage | Non-public local FAL storage outside the web root, created on setup — mandatory |
| Retention | `opinion:cleanup` command, schedulable, default 180 days |
| Collected details | One switch per detail in the extension configuration (`collect_<section>_<name>`, all on by default), enforced on the server; the sender is always kept |
| Access | All admins; non-admins via user TSconfig `options.opinion.enable = 1` |
| Categories / severities | `bug, content, idea, other` / `low, normal, high` |
| Endpoint | One backend AJAX route `opinion_submit` for frontend and backend; a frontend route is an accepted fallback if the spike in `opinion-adminpanel` shows the backend route cannot be used from the frontend |
| Screenshot access | `dumpFile` requests for screenshots require an admin backend session (listener on `ModifyFileDumpEvent`) |
| Deployment | `var/opinion/` becomes a Deployer shared + writable dir. `BE.lockRootPath` is not needed: the path is inside the project root, which core allows, and is stored relative to the public path (`../var/opinion/`) so it survives release switches |

## Open Questions

None — file dump restriction decided 2026-10-09 (admin session required).
