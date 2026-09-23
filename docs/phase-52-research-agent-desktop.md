# Phase 52 — Research Agent Desktop

Phase 52 replaces the inline Research workspace controls with a dedicated desktop layer above each Research Agent conversation.

## Interaction model

- The Research Agent canvas remains the persistent chat timeline.
- A **DESKTOP** control beside the canvas close button opens a full-width workspace layer.
- Documents, folders, bookmarks, uploads, recordings, and linked annotations appear as draggable desktop icons.
- Icon positions persist per Research project and object.
- Double-clicking a folder navigates the desktop into that folder; breadcrumbs return to the desktop root.
- Double-clicking a Research document opens a large desktop document window with the Phase 51 editor, autosave, version history, and Agent-selection actions.
- Active sticky notes appear as traditional draggable/resizable digital sticky notes on the desktop only.
- Trash is represented as a desktop system icon and opens trashed workspace objects for restore.
- Existing Agent-created document cards still deep-link to the same durable document, opening the Desktop automatically.

## Permissions

The Desktop inherits the Research Agent project permissions. Owners, admins, and researchers can create, edit, move, position, trash, and restore objects. Viewers may open/read objects but cannot mutate desktop state.

## Continuity

Desktop layout stores only object references plus coordinates and z-order. Document bodies, annotation text, and source content are not copied into desktop layout state.
