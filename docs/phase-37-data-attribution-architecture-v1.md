# Phase 37 — Data & Attribution Architecture v1

Phase 37 is the first post-V1 intelligence-data layer. It does **not** train a model. It makes Annotated capable of collecting clean, attributable, permissioned knowledge so later retrieval, evaluation, specialized models, and versioned training datasets can be built without retrofitting provenance or consent.

## Architectural boundary

Authoritative Annotated objects remain the source of truth.

The learning path is:

**Production object → Contribution Ledger → Rights / Consent → Derived Corpus → Future Versioned Dataset → Controlled Model Release**

Phase 37 stops at the derived-corpus and response-lineage stages.

It does not:
- train or fine-tune a model
- silently turn public content into training data
- copy private or Team Research into a shared corpus
- treat an AI proposal as a human conclusion
- claim that revocation can surgically remove data from an already-trained historical model
- create a second Research truth graph alongside existing Research Provenance

## Existing provenance vs Data & Attribution

Existing Research Provenance remains the authoritative audit representation of a Research project, its evidence, reviews, report versions, verification state, and immutable receipts.

Phase 37 adds a generalized reuse/attribution layer across Annotated:
- contribution-state hashes and authorship
- cross-object provenance edges used for attribution
- contributor consent
- Source rights
- disposable derived corpus items
- AI response lineage

The generalized graph points back to authoritative Annotated objects. It does not replace them.

## Contribution Ledger

`data_contributions` records append-only contribution states by reference and hash.

Supported v1 contribution types include:
- Annotation commentary
- Claim creation / revised Claim state
- Finding creation / revised Finding state
- public Report Version publication
- human Verification events
- collaborative Review responses
- Agent responses
- confirmed Agent-created Research objects

When an object changes, a new contribution state is recorded and the older state is marked superseded. Historical attribution is retained.

The ledger intentionally does not store reusable source/content text.

## Provenance Edges

`data_provenance_edges` records generalized relationships such as:
- Source Version → Annotation: `captured_as`
- Annotation / Source → Claim: `supports`, `contradicts`, etc.
- Claim → Finding
- Claim / Finding → Report Version: `included_in`
- Research subject → Verification
- Research subject → Review response
- Annotated object → AI response: `used_in_response`

These are attribution/reference edges, not a replacement for the authoritative Research tables.

## Consent model

Visibility and intelligence reuse are separate permissions.

Contributor defaults start closed:
- shared retrieval: OFF
- evaluation: OFF
- training: OFF
- commercial training: OFF
- attribution required: ON

A public Annotation does not become reusable merely because it is public.

Object-specific grants can override the contributor default for a supported object.

Private and Team Research remain outside the shared corpus even if the contributor has enabled model improvement globally.

## Source rights

External Source content has separate rights from user-authored commentary.

`source_rights` classifies a Source as:
- unknown
- user_owned
- licensed
- public_domain
- open_license
- permission_granted
- restricted

Unknown and restricted Sources are closed by default.

Source text enters the derived corpus only when an administrator has explicitly approved the relevant rights for:
1. retrieval
2. excerpt storage
3. model context

Training additionally requires explicit training permission.

Commercial training additionally requires explicit commercial-training permission.

## Derived corpus

`data_corpus_items` is disposable derived state.

It can be regenerated from authoritative Annotated objects plus current rights/consent.

V1 corpus types:
- public user-authored Annotation commentary
- public Report Version title/summary
- explicitly rights-approved Source text

Captured/selected publisher text is not treated as the Annotation author's training contribution.

Claims and Findings are recorded in attribution/provenance but remain outside the shared corpus because they are private Research state in the current product model.

Revoking consent or changing rights invalidates derived corpus eligibility; it does not delete the historical contribution/audit record.

## AI response lineage

Every completed `ai_run` records the concrete Annotated object references supplied to the model.

`data_response_lineage` records:
- response hash
- context-reference hash
- AI run
- optional visible conversation message

`data_response_attributions` records each attributable Annotated object supplied as context and, when known, its human contributor.

Agent Chat displays a compact lineage summary and links to the contributor's permission-checked Data & Attribution view.

The lineage describes **what Annotated context was supplied to the model**. It does not falsely claim token-level causal ownership of the generated prose.

## Human / Agent distinction

Agent output is recorded with `actor_type=agent`.

Confirmed Agent Research writes still use the confirming user as the contributor of the resulting authoritative Research object, while the application preserves Agent action/proposal provenance separately.

Human Verification and Review events remain explicitly human-authored.

## User controls

`/data-attribution.php` provides:
- contribution counts
- recorded response reuse
- active derived-corpus count
- training-eligible count
- contributor-level opt-in controls
- recent contribution states
- permission-checked AI response lineage

## Admin controls

`/admin/data-attribution.php` provides:
- global ledger/provenance/corpus statistics
- Source rights classification
- license / rights-holder metadata
- retrieval/storage/context/training policy controls

## Backfill

`php bin/data-attribution-sync.php [limit] [optional_user_id]`

Backfill is idempotent for unchanged object states. It lets an existing Annotated installation enter Phase 37 without rewriting historical product data.

## Phase 37 release gates

The dedicated database suite proves:
- public does not imply trainable
- default consent is closed
- private content stays out of shared corpus
- object-specific grants work
- contributor commentary is separated from captured Source text
- Source rights default closed
- Source rights can explicitly authorize and later revoke corpus use
- revisions preserve superseded contribution history
- Claim / Finding / Report provenance chains are recorded
- human Verification is attributable without becoming a truth score
- AI response lineage is user-scoped
- backfill is idempotent
- revocation invalidates derived corpus while preserving audit history

The architecture contract also prevents Phase 37 from directly invoking model training or bypassing the rights/consent layer.

## Future phases

Phase 37 intentionally prepares, but does not yet implement:
- dataset registry and frozen dataset manifests
- retrieval/ranking learning
- evaluation datasets
- specialized evidence / contradiction / source-impact models
- contributor economics
- model registry
- controlled fine-tuning
- Annotated Research Model releases

Those should consume this governed substrate rather than reading arbitrary production tables directly.
