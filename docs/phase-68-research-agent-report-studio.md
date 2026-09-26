# Phase 68 — Research Agent Report Studio

Phase 68 turns System Reports into a first-class **per-Research-Agent** workspace.

## Object model

Phase 68 deliberately separates three objects:

1. **System Report Definition** — a system-owned processor describing how Research data is transformed.
2. **Report Run** — an immutable-at-generation processing result for one Research Agent at one data-state hash.
3. **Research Document** — an editable/versioned workspace artifact that the user explicitly creates from a Report Run.

A Report Run never becomes a Document automatically.

## Built-in report definitions

The nine Phase 67 processors remain the system catalog:

- Research Brief
- Evidence Audit
- Claims & Verification
- Contradictions & Gaps
- Source Freshness & Change
- Entities & Relationships
- Research Timeline
- Research Action Plan
- Full Intelligence Report

Each definition declares category, default depth, supported scope, and canonical section keys.

## Per-Agent Report Studio

Every Research Agent has its own Reports workspace with:

- **Run Report**
- **Recent Reports**
- **Saved Presets**

Report Studio accepts:

- quick / standard / deep processing depth
- optional focus query
- date window
- selected Sources
- selected Claims
- selected Findings
- selected Entities
- selected Desktop folders
- selected report sections

All scope remains inside the owning Research Agent project.

## Report Runs

A Report Run persists:

- Research Agent + project
- report definition
- title
- rendered result
- structured section manifest
- parameters and scope
- authoritative knowledge manifest
- deterministic input-state hash
- evidence/provenance references
- coverage/diagnostic metrics
- generation mode
- refresh lineage
- optional saved-preset relationship
- optional Research Document relationship

Report Runs are indexed as their own `report` objects in the unified Research Library. Derived Report Runs and Documents created from them are excluded from the authoritative corpus hash used to determine report freshness, preventing self-referential staleness.

## Refresh and comparison

Refresh creates a **new Report Run** from the prior run's configuration. It never overwrites the prior run.

Comparison uses stable object manifests to show added, removed, and changed:

- Claims
- Findings
- Sources
- Entities
- Tasks
- Programs

Claim/Finding/Source changes contribute to the material-change count.

## Create Document

**Create Document** is explicit.

A user may convert the entire Report Run or selected report sections into a normal versioned Research Document. The original Report Run remains unchanged and retains a durable relationship to the created Document.

The Agent can also propose this operation through a separate governed capability:

- `research.create_system_report` — creates a Report Run only
- `research.create_document_from_report` — creates a Research Document from an existing Report Run

Both remain confirmation-governed Agent actions.

## Ask Agent

A Report Run is valid Agent context. **Ask Agent** hands the report to its owning Research Agent together with report provenance. Report context resolves back to its owning project so governed follow-up actions continue to work.

## Saved presets

A user can save a configured report as a per-Agent preset. Presets retain report definition, title template, parameters, and scope.

A preset may optionally point at one existing Research Program owned by the same Agent. Phase 68 stores this relationship only. It does **not** alter the Program worker or create new scheduling authority; automated delivery remains a later phase.

## Migration 082

`20260926_082_research_agent_report_studio.sql`

Migration 082:

- creates `research_report_presets`
- makes `research_system_reports.document_object_id` optional
- adds rendered content, sections, parameters, scope, knowledge manifest, freshness, generation mode, refresh lineage, preset link, and document-created timestamp
- backfills existing Phase 67 reports from their already-created Research Documents
- marks migrated Phase 67 runs as `legacy`
- preserves legacy Document relationships
- changes the report→document foreign key to `ON DELETE SET NULL`

No new worker, scheduler, queue, retrieval index, or document store is introduced.

## Permission boundary

Report Runs inherit the Research Agent/project access boundary. Persisted reports continue to use only project-contained intelligence. Viewer-private or cross-project context may inform live Agent Chat but is not copied into a shared Report Run.

## Phase boundary

Phase 68 establishes the Report Studio, Report Run lifecycle, optional Document conversion, refresh/compare, and reusable presets.

It intentionally does **not** automatically execute presets from Research Programs or deliver reports on a schedule. Those capabilities can build on the Phase 68 Program relationship without changing this phase's authority model.
