# Phase 51 — Research Docs & Floating Sticky Notes

Phase 51 turns each Research Agent into an editable working surface without breaking the Agent conversation timeline.

## Research Docs

Research documents are first-class workspace objects. They open inside the existing Research Agent canvas rather than navigating to a separate document page. Desktop document mode uses the document editor as the primary pane and moves the live Research Agent conversation into a companion pane. Closing the document restores the same chat timeline and scroll position.

Documents autosave with revision numbers and optimistic locking. Every accepted edit creates a durable revision. A stale editor cannot silently overwrite a newer Team edit. Restoring history creates a new current revision instead of rewriting history.

The editor supports headings, paragraphs, emphasis, lists, quotes, code blocks, links, tables, undo/redo, Agent review of selected text, and converting selected text into a sticky note.

## Agent-created documents

The existing governed Agent Action runtime exposes `research.create_document`. The user must confirm the proposed Research change. Execution creates the durable document, then posts a new Agent-authored message into the owning Research Agent conversation with the live document attached as a rich card. The proposal message remains an audit record of the confirmed action; the new delivery message becomes part of the Agent's work timeline. Conversation reloads resolve the document object again, so the card is persistent rather than a client-only effect.

## Floating sticky notes

Sticky notes belong to the Research Agent workspace but float over the Research Agent canvas independently of Chat, Docs, or Files. Their body, six-color palette, x/y position, width, height, and z-order are durable. Dragging, resizing, recoloring, and editing autosave. Deletion moves the note to the existing Trash instead of hard-deleting it.

## Team behavior

The existing Research project and Team roles remain authoritative. Owners, admins, and researchers can edit workspace material. Viewers can read documents and sticky notes without write controls. Document revision locking protects collaborative edits from silent last-write-wins overwrites.

## Continuity

URLs can deep-link to `home.php?agent=<conversation>&doc=<document>`. Workspace state carries only object references, never document content. Agent context retrieval resolves document content only when explicitly needed and permission-checks it at retrieval time.
