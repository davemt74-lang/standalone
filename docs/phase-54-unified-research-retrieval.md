# Phase 54 — Unified Research Knowledge & Retrieval

Phase 54 turns each Research Agent workspace into one permission-governed research corpus.

## Indexed evidence

The project-scoped retrieval layer indexes:

- current project Source content
- linked Annotations and captured passages
- Research Docs
- website Bookmarks and captured source text
- Sticky notes
- uploaded document text
- Recording transcripts

Folder membership is preserved so retrieval can be scoped to a folder and its descendants. Linked annotations inside a trashed folder are excluded from the derived corpus and return to retrieval only when that folder is restored.

## Provenance-aware chunks

Evidence is chunked with durable object identity and locators:

- PDF uploads preserve page locators when form-feed page boundaries are available.
- Research Docs preserve section/headline locators.
- Recording transcripts preserve timestamp locators when the configured transcription command returns structured segments.
- Annotations preserve captured-passage or media timestamp locators.
- Source and Bookmark content preserve source-section locators.

The Agent receives retrieval evidence using explicit labels such as:

- `[UPLOAD <id> · Page 6]`
- `[RECORDING <id> · 08:42]`
- `[DOCUMENT <id> · Findings]`

The underlying object remains authoritative; retrieval chunks are derived indexes, never replacement evidence.

## Search

`api/research-retrieval.php` provides project-scoped search and related-evidence discovery.

Search is lexical/full-text by default and works without any external AI provider. Title matches remain searchable even for objects with no extracted text or chunk rows. An optional provider-neutral embedding command can be configured to enable true hybrid retrieval: semantic candidates are considered even when they share no lexical terms with the query. The command receives `{input}` and `{output}`, reads UTF-8 query/chunk text from the input file, and writes a JSON numeric array or `{"embedding":[...]}` to the output file.

Filters include object type, folder scope, processing status, date range, and creator identity.

Every result is permission-checked again against the live authoritative object before it is returned. Indexed Annotations use stricter retrieval visibility: private annotations are owner/admin only, and team annotations require membership in the Annotation's actual Team; project membership alone never exposes private indexed text. Removing a Team member or restricting an Annotation therefore takes effect immediately even when derived chunks remain in the index.

## Research Library

The full-height Research Library drawer is backed by the unified retrieval API rather than client-only filtering.

It supports:

- Sources, Docs, Annotations, Files, Recordings, Transcripts, Bookmarks, and Stickies
- folder and processing-state filters
- date filtering
- evidence snippets and locators
- Related evidence
- multi-select of up to six objects
- **Ask Agent** with the selected authoritative objects as governed context
- index mode/count visibility

## Agent Chat

Research Agent conversations use the latest user prompt to retrieve relevant evidence from the owning Research project. Phase 54 replaces the old broad source/annotation dump when the retrieval index is available, while preserving deterministic Research Workspace / claims / findings context from the existing research system.

Explicit context attachments remain authoritative and are combined with query-driven retrieval. The Agent is instructed to preserve exact retrieval locators in citations.

## Index lifecycle

Migration 052 adds:

- `research_retrieval_projects`
- `research_retrieval_documents`
- `research_retrieval_chunks`
- `research_retrieval_jobs`
- `research_retrieval_queries`

Workspace writes and completed file/transcription processing queue the owning project for refresh. Search also verifies the live project state hash and self-heals a stale or missing index before returning results. Rebuilds are incremental: unchanged chunks retain their semantic embeddings, while changed content or changed provenance locators are regenerated.

The leased retrieval worker is:

`php worker/research-retrieval-worker.php`

If content changes while the worker holds a lease, `rerun_requested` preserves the newer refresh request so it cannot be lost when the current job completes.

## Phase 54 release score rubric

A Phase 54 release is complete only when all ten categories pass:

1. unified corpus architecture
2. retrieval relevance
3. provenance and locator fidelity
4. live permission safety
5. folder-aware retrieval
6. Research Library UX
7. Agent Chat integration
8. index/worker lifecycle and recovery
9. regression and database coverage
10. production packaging and upgrade path
