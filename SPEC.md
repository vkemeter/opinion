# Capability Map: Opinion — editor feedback tool

Editors report problems on the page they are looking at: a short description, an optional
screenshot captured via `navigator.mediaDevices.getDisplayMedia()`, and automatically collected
client and TYPO3 context. Each submission is stored as a record and delivered by email.

Repository: <https://github.com/vkemeter/opinion> · Composer `vkemeter/opinion` · extension key `opinion`.
Successor of the legacy extension from the same repository (TYPO3 v12, `supseven/opinion`),
rewritten for TYPO3 v14. Only the concept is carried over, not the code.

| Module id | Responsibility | Depends on |
|---|---|---|
| `opinion-core` | Admin module "Feedback" (list, detail view), submission DTO, validation, persistence (record + screenshot), delivery channel abstraction with an email channel, submit endpoint, configuration provider, shared JS dialog (form, screenshot capture, preview/retake, client info collection) | — |
| `opinion-adminpanel` | Admin panel module in the frontend: trigger button, mounts the shared dialog, contributes the frontend context (page, language, site, workspace) | `opinion-core` |
| `opinion-backend-toolbar` | Backend toolbar item with the same dialog, contributes the backend context (module, route, selected page) | `opinion-core` |

Build order: `opinion-core` → `opinion-adminpanel` → `opinion-backend-toolbar`

## Module specs

| Module id | Spec | Status |
|---|---|---|
| `opinion-core` | [SPEC-opinion-core.md](SPEC-opinion-core.md) | Implemented 2026-10-09, manual checks open |
| `opinion-adminpanel` | [SPEC-opinion-adminpanel.md](SPEC-opinion-adminpanel.md) | Implemented 2026-10-09, manual checks open |
| `opinion-backend-toolbar` | [SPEC-opinion-backend-toolbar.md](SPEC-opinion-backend-toolbar.md) | Implemented 2026-10-09, manual checks open |
| `opinion-annotation` | [SPEC-opinion-annotation.md](SPEC-opinion-annotation.md) | Implemented and tested (Chrome) 2026-10-13 |

## Roadmap beyond v1

- **v2:** screenshot annotation (draw/highlight before sending), see `opinion-annotation`.
- **Later:** ticket system delivery (e.g. Jira API) as an additional delivery channel; configuration
  may then move away from the extension configuration — `opinion-core` keeps configuration behind an
  interface for that reason.
