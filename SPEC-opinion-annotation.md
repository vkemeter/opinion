# Spec: opinion-annotation — screenshot annotation (v2)

Map: [SPEC.md](SPEC.md) · Builds on: [SPEC-opinion-core.md](SPEC-opinion-core.md)

## Objective

Editors mark what they mean directly on the screenshot before sending: frame an area, point at an
element, black out personal data. Fewer follow-up questions, and data that must not leave the page
can be removed before it is stored and mailed.

## Scope

- Button "Mark up" next to Retake/Remove once a screenshot exists.
- Opens a full-screen editor (own modal `<dialog>` above the feedback dialog) with the screenshot on
  a canvas, scaled to fit.
- Tools: **rectangle** (frame), **arrow**, **black out** (opaque black area). Colours for rectangle
  and arrow: red (default), yellow, blue. Black out is always black.
- **Undo** (button, `Ctrl`/`Cmd` + `Z`) and **clear all**.
- **Apply** burns the shapes into the image (JPEG, same encoding as the capture) and updates the
  preview; **Cancel** / `Esc` keeps the previous state.
- Reopening the editor shows the existing shapes on the original capture, so they stay undoable.
  Retake and Remove discard the shapes.

## Decisions

- Only the annotated image is sent. Server, record, storage, email and admin module are unchanged.
  Blacked-out areas never reach the server.
- Shapes are kept in image pixel coordinates; line width scales with the image width.
- No dependencies, no build step — plain ES module like the rest of the dialog
  (`annotation.js` rendering, `annotation-editor.js` UI).
- Drawing needs a pointer (mouse, pen, touch). All other controls are keyboard operable and
  labelled; tool and colour buttons expose their state via `aria-pressed`.

## Out of scope

Freehand drawing, text labels, moving or resizing existing shapes, keeping the original image.

## Success criteria

- [x] Rectangle, arrow and black out appear in the uploaded JPEG at the drawn position.
- [x] Undo removes the last shape, clear all removes every shape.
- [x] Cancel and `Esc` close only the editor and leave the screenshot unchanged.
- [x] Reopening shows the existing shapes; retake starts without shapes.
- [x] All new labels exist in English and German.
- [x] Browser test covers the above; existing suites stay green.

## Status

Implemented 2026-10-13. Covered by two scenarios in `Tests/Browser/dialog.test.mjs` (Chromium).
Tested by the user in Chrome in the backend toolbar and the admin panel on 2026-10-13: everything
is transmitted as expected. Firefox, Safari and touch devices are not tested, not needed for now.
