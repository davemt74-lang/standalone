# Phase 67 — Unified Research Knowledge & System Reports

Phase 67 makes the Research Agent's accumulated information visible and usable as one coherent knowledge system. It does not introduce a second Research store, a second evidence index, or a second document system.

## 67A — One Research knowledge universe

The authoritative Research project remains the boundary for the Agent.

The existing Phase 54 retrieval index continues to own query-driven retrieval. Phase 67 extends that index beyond raw evidence so it can also rank and return structured Research objects:

- Claims
- Findings
- Entities
- Research Tasks
- Research Programs

System Reports remain ordinary Research Docs. Report metadata is added to the indexed document record so the Library can filter Reports without duplicating report text in a second retrieval object.

The existing evidence objects remain indexed exactly as before:

- Sources
- Annotations
- Research Docs
- Bookmarks
- Sticky notes
- uploaded files
- recording transcripts

Every retrieval result is permission checked against its live authoritative object before it is returned.

## 67B — Research Agent Knowledge view

`research-agent-knowledge.php` is a transparent user-facing view of the Agent's current project knowledge.

It exposes:

- Research counts and retrieval-index state
- strongest Findings
- supported Claims
- open evidence gaps
- contradictions and disputes
- Entities and relationship density
- source risks
- monitoring changes
- active Tasks and Programs
- items needing user attention
- deterministic next actions
- recent evidence and structured knowledge
- recent System Reports

The view is not hidden Agent memory. Every item links back to an authoritative Research object.

## 67C — Unified Research Library

The existing full-height Research Library remains the primary search/browse surface inside the Research Agent canvas.

Phase 67 adds Library filters for:

- Claims
- Findings
- Entities
- Reports

Claims, Findings and Entities can be selected with other evidence and sent through **Ask Agent**. Agent Chat resolves each selected structured object through its normal live permission checks and includes the authoritative details and object references in the prompt context.

Tasks and Programs also participate in unified retrieval for Agent relevance and Related discovery, while their existing dedicated Library/operations views remain authoritative for execution state.

## 67D — System Reports

System Reports are different deterministic ways to process the same authorized Research Agent data.

The built-in report catalog is:

1. **Research Brief** — concise current state, strongest Findings, gaps, contradictions and next actions.
2. **Evidence Audit** — corpus coverage, recent evidence, source risk and unsupported areas.
3. **Claims & Verification** — Claim status and evidence-depth matrix.
4. **Contradictions & Gaps** — disputed knowledge and missing evidence.
5. **Source Freshness & Change** — source recency, source changes and monitoring signals.
6. **Entities & Relationships** — people, organizations, places, topics and relationship density.
7. **Research Timeline** — chronological Research history.
8. **Research Action Plan** — prioritized follow-up from gaps, risk, monitoring and task state.
9. **Full Intelligence Report** — comprehensive processing across evidence, knowledge, monitoring and work.

A System Report records:

- report type
- Research Agent and project
- requesting user
- exact input-state hash
- authoritative evidence/object references
- summary metrics
- linked Research Doc
- audit events

The rendered report is written into a normal versioned Research Doc under the managed **System Reports** Desktop folder. The report can therefore be edited, retrieved, reviewed, published and cited through existing Annotated systems.

No separate report-content store is introduced.

## 67E — Research Agent report creation

The governed Agent Action registry adds:

`research.create_system_report`

The Agent may propose a report type and optional title. The existing Agent Action confirmation flow remains authoritative. The model cannot silently create a report.

After confirmation:

1. the current authorized Research state is snapshotted;
2. the selected report processor renders the report;
3. a normal Research Doc is created;
4. report provenance and state hash are recorded;
5. retrieval/autonomy refresh is queued;
6. Agent-created reports are posted back into the Research Agent conversation as document cards.

## 67F — Desktop / Library / Knowledge responsibilities

The Phase 67 product model is:

- **Desktop** — what the user is actively working with.
- **Library** — everything the Research Agent can retrieve and search.
- **Knowledge** — what the evidence currently says and where uncertainty remains.
- **Agent** — what should be done with the information.
- **Reports** — repeatable ways to process the Agent's current data into user-facing outputs.

This preserves the strengths of the existing Desktop instead of turning it into a second Knowledge system.

## Migration

Phase 67 adds migration:

`20260925_080_research_agent_knowledge_system_reports.sql`

It adds:

- `research_system_reports`
- `research_system_report_events`

No worker, scheduler, queue, second retrieval index, or second document store is added.

## Release acceptance

Phase 67 is complete only when all ten dimensions pass:

1. structured knowledge retrieval
2. live permission enforcement
3. Research Library integration
4. Agent Chat structured-object handoff
5. Research Agent Knowledge view
6. System Report catalog and deterministic processors
7. report provenance/state hashing
8. governed Agent report creation
9. Research Doc/Desktop/review/publishing continuity
10. PHP 8.1/8.3, MySQL 8, migration rehearsal, package validation and merged-tree equivalence
