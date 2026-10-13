# Implementation Plan: opinion-core

Spec: [SPEC-opinion-core.md](../SPEC-opinion-core.md) · Map: [SPEC.md](../SPEC.md) · Tasks: [todo.md](todo.md)

## Overview

New local package `packages/opinion` (`vkemeter/opinion`, `Vkemeter\Opinion\`, ext key `opinion`).
It provides the shared submission pipeline — access check, validation, persistence with screenshots
in a non-public FAL storage, delivery by email, retention cleanup — and the shared JS dialog. The
entry points (`opinion-adminpanel`, `opinion-backend-toolbar`) are separate plans.

## Architecture Decisions

- **One backend AJAX route** `opinion_submit` (`Configuration/Backend/AjaxRoutes.php`) for both
  entry points. The entry point renders the tokenised URL (`UriBuilder::buildUriFromRoute`) into the
  page. Task 2 verifies early that the route works from a frontend page with a backend session;
  fallback is a frontend middleware route (accepted by the user).
  *Status (T2):* route registered; without session core answers `401`, `GET` gets `405`. The
  frontend spike (token generated in the frontend, POST with backend session) is **still open** —
  automated login was not possible (MFA is required for all users). To be verified manually, at the
  latest in `opinion-adminpanel`.
- **Pipeline:** `SubmitController` → `AccessChecker` → `SubmissionFactory` (+ `SubmissionValidator`,
  `ClientInfoSanitizer`, `SystemInfoCollector`) → `SubmissionProcessor` → `FeedbackRepository` +
  `ScreenshotStorage` → delivery channels (tagged `opinion.delivery_channel`) → status update.
- **Persist first, deliver second** — a mail failure never loses a submission.
- **Records at `pid = 0`** (`rootLevel = 1`), read-only TCA, schema from TCA.
- **Screenshots** in a local FAL storage, `is_public = 0`, base path `var/opinion/` (outside the web
  root), created by a `PackageInitializationEvent` listener and found by its resolved base path.
  Stored relative to the public path (`../var/opinion/`); inside the project root, so no
  `BE.lockRootPath` entry is needed. Deployer shared + writable dir. The storage stays browsable
  (FAL refuses folder reads otherwise); only `is_public` is switched off.
- **Configuration** via `ConfigurationProviderInterface`; v1 = extension configuration.
- **Access:** admins always; non-admins via user TSconfig `options.opinion.enable = 1`.
- **Cleanup** as Symfony command `opinion:cleanup` (schedulable by default via `ConsoleCommandPass`).
- **JS:** native ES modules, no build step, no dependencies; `multipart/form-data` upload.

## Dependency Graph

```
T1 package skeleton
 ├─ T2 access + endpoint skeleton (spike: route from frontend)
 ├─ T3 non-public screenshot storage
 └─ T4 domain + validation ── T5 client/system info + factory
                                  │
          T3 ─────────────┬───────┘
                          T6 persistence (TCA + repository)
                          │
          T2 ─────────── T7 processor + endpoint wired (422/200)
                          │
                          T8 email delivery + configuration
                          │
                          T9 JS dialog (text submit) ── T10 screenshot capture/preview
                          │
                          T11 cleanup command
                          │
                          T12 README + final checks
```

T3 and T4/T5 can run in parallel after T1. T11 only needs T6.

## Task List

### Phase 1: Foundation and risks
- [x] T1: Package skeleton installed
- [x] T2: Access check and endpoint skeleton (incl. frontend spike)
- [x] T3: Non-public screenshot storage (incl. deployment)
- [x] T3b: Admin session required for screenshot file dumps

### Checkpoint A — endpoint reachable from frontend, storage outside web root, tests green

### Phase 2: Submission pipeline
- [x] T4: Enums, Submission DTO and validator
- [x] T5: Client info sanitizer, system info collector and submission factory
- [x] T6: Feedback record (TCA) and repository
- [x] T7: Submission processor wired into the endpoint
- [x] T8: Email delivery channel and extension configuration

### Checkpoint B — curl submission → record + screenshot + mail in Mailpit, failure path tested

### Phase 3: UI and maintenance
- [x] T9: Shared JS dialog with text submission
- [x] T10: Screenshot capture, preview, retake, remove
- [x] T11: Cleanup command

### Checkpoint C — dialog end-to-end in Chrome + Firefox, cleanup in Scheduler

### Phase 4: Finish
- [x] T12: README and full quality run

### Checkpoint D — all spec success criteria met, ready for review

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Backend AJAX route rejects calls from frontend pages (token/session/cookie path) | High | Spike in T2 before anything depends on it; fallback frontend middleware route |
| Local storage outside the public path blocked by `BE.lockRootPath` or path resolution differs between DDEV and Deployer releases | High | T3 first; relative-to-project path, lockRootPath from `Environment::getProjectPath()`, functional test, shared dir in deploy config |
| `PackageInitializationEvent` not fired for path packages in this setup | Med | T3 verifies with `extension:setup`; fallback: create on demand in `ScreenshotStorage` |
| `getDisplayMedia` behaviour differs (Firefox lacks `preferCurrentTab`, Safari restrictions) | Med | Feature detection, graceful text-only fallback, manual test matrix in T10 |
| Large screenshots exceed `upload_max_filesize` / `post_max_size` | Med | JPEG at quality 0.85, client-side downscale to max 2560 px width, server limit 10 MB, clear 413/422 message |
| JSON fields and FAL references with `pid = 0` behave unexpectedly in List module | Low | Functional test + manual check in T6 |
| Personal data in screenshots/client info | Med | Non-public storage, admin-only records, retention cleanup, no cookies/storage contents |

## Open Questions

None — all spec questions resolved on 2026-10-09.
