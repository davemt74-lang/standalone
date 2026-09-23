# Phase 63 — VP3 Account Connection & Research Ingestion

Phase 63 connects standalone Annotated to the user's VP3 account. It does **not** turn standalone Annotated into another VP3 plugin or duplicate the Annotated feature already present inside VP3.

## 63A — VP3 account authorization

VP3 is authoritative for VP3 identity and VP3 artifact permissions. Annotated uses a first-party authorization-code connection with explicit scopes:

- `account.identity.read`
- `meetings.transcripts.read`
- `meetings.intelligence.read`

Annotated stores access/refresh credentials encrypted with the existing Annotated application encryption key. VP3 stores only token hashes.

## 63B — Sign in with VP3 / Connect VP3

Annotated supports:

- **Continue with VP3** for a VP3 identity already linked to Annotated, or for creation of a new passwordless Annotated account.
- **Connect VP3 Account** for an already signed-in Annotated user.

Annotated never auto-links an existing Annotated account merely because the VP3 email matches. A matching-email user must sign in to Annotated first and explicitly connect VP3.

One VP3 account can be linked to only one Annotated account.

## 63C — VP3 Library

The Research workspace exposes a VP3 Library. The initial artifact implementation covers both VP3 Meetings and the general VP3 transcription workspace, using their existing authoritative permissions, transcripts and Transcription/Meeting Intelligence.

Users may browse only transcriptions or meetings their VP3 account can access. Each item shows meeting metadata, transcript/AI-summary availability, the current VP3 version, a link to the original, and whether a newer version is available.

The connector data model is generic enough to add other VP3 transcription/recording artifact types later without replacing the account connection.

## 63D — Research Agent ingestion

A user explicitly chooses a Research Agent and imports the transcript, AI summary, or both.

The VP3 transcription/meeting becomes a normal Annotated **Source** with immutable **Source Versions**.

- Annotated `content_hash` remains the hash of the imported evidence bytes.
- VP3's remote version hash is retained separately as `target_content_hash` plus provenance metadata.
- The selected Research Agent receives a normal versioned transcript document and/or `source_summary` document.
- Workspace metadata points back to the exact VP3 artifact, Source, and Source Version.
- The project gets the normal `project_sources` relationship so Claims/Findings can trace to VP3 evidence.

## 63E — Versioning, permissions & disconnect

Re-importing the same VP3 version is a no-op. When VP3 changes, Annotated marks the import **update available** and a user-controlled update creates a new Source Version plus Research Doc revisions. Prior versions remain intact.

If an artifact disappears from the connected account's permitted VP3 library, Annotated marks the import **source unavailable** rather than deleting it.

Disconnecting VP3 revokes future access and wipes connection credentials while preserving explicitly imported Source Versions and Research Agent documents.

## 63F — Two-repository release gate

Standalone Annotated validates migration 060, connection security, provenance, versioning, disconnect behavior, upgrade safety, and package inclusion.

VP3 validates Connected Sites authorization, scoped tokens, meeting permission filtering, account UI/revocation, and projection from its existing transcript/Meeting Intelligence stores.

Neither repository creates a second transcript system, AI-summary store, Research system, or identity authority.
