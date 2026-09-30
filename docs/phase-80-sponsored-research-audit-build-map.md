# Phase 80 — Sponsored Research, Researcher Marketplace & Knowledge Licensing
## Architecture Audit & Build Map

### Purpose

Phase 80 must add sponsored / paid human research without creating parallel versions of systems Annotated already owns.

The governing lineage is:

**Research contribution → Knowledge item → Knowledge Base version → Dataset version → Training / Evaluation run → Model release**

Sponsored funding and contributor compensation sit alongside that lineage. They do not replace Research, Attribution, Dataset, Training, Review, Publication, or Billing authorities.

---

## Audit conclusions

### 1. Research identity and permission boundary — REUSE

Canonical authorities:
- `research_agents`
- `research_projects`
- Research Teams / `team_members`
- unified Research Agent shell
- Missions, Tasks, Decisions, Reports

Rule:
A sponsored campaign may create or associate Research work, but it must not create a second Research Project or Research Agent authority.

Commercial Account membership must remain separate from Research Team membership.

### 2. Contribution provenance and consent — REUSE + EXTEND

Existing Phase 37 Data & Attribution already provides:
- `data_contributions`
- `data_provenance_edges`
- `data_contributor_preferences`
- `data_usage_grants`
- `source_rights`
- `data_corpus_items`
- AI response lineage / attributions

Existing consent is already separated into:
- shared retrieval
- evaluation
- training
- commercial training
- attribution requirement

Important existing rule:
**Public visibility never implies reuse or training permission.**

Phase 80 should extend supported contribution/object descriptors where needed, but must not build a second consent or rights subsystem.

Sponsored participation consent is a separate contractual event and must not silently alter Phase 37 training permissions.

### 3. Agent memory / usage evidence — REUSE + EXTEND

Phase 79 Section 3 provides:
- `research_memory_controls`
- `research_memory_events`
- `research_memory_usage`

This is the canonical evidence for which Research knowledge was actually supplied to a Research Agent conversation.

Phase 80 may federate this into contributor usage accounting, but must not replace it.

### 4. Collaborative review and acceptance — REUSE + EXTEND

Existing authorities:
- `research_reviews`
- assignments / reviewer roles
- append-only responses/comments/events
- stale-subject protection
- completion snapshots
- Phase 59 publication review rounds and approval gates

Phase 80 should add sponsored-submission subjects / adapter logic where necessary.

It should not create a second review engine.

Sponsor acceptance and payment eligibility should consume a completed/current review decision plus explicit sponsor acceptance state.

### 5. Publication and immutable reports — REUSE

Existing Phase 59 authority provides:
- publication workflows
- immutable document revision pins
- review rounds
- approval gates
- published report versions
- distribution events
- publication event ledger

Sponsored Research that produces a public/private/team Report should use this pipeline.

"Sponsor accepted" must not mean "published."

### 6. Knowledge / retrieval — REUSE + EXTEND

Existing authorities:
- unified Research Knowledge
- Phase 54 retrieval
- Source / Annotation / workspace / Claims / Findings / Entities
- Phase 79 memory governance

Phase 80 needs a **versioned Knowledge Base release** abstraction that references existing governed knowledge objects.

It should not copy knowledge into a second truth store.

A Knowledge Base version should be an immutable manifest of canonical object/version references, consent snapshots, provenance hashes, and rights state.

### 7. Dataset assembly — REUSE + EXTEND

Existing Phase 38 Dataset Registry already provides:
- draft datasets
- governed corpus-only selection
- immutable frozen manifests
- contributor IDs
- normalized text snapshots
- provenance hashes
- eligibility snapshots
- current-use revalidation

Existing Phase 47 campaign orchestration already demonstrates:
- campaign-scoped dataset selection
- immutable campaign plans
- exact source-object selection
- downstream handoff without stealing authority from Dataset/Training

Phase 80 should reuse these patterns.

A sponsored Knowledge Base may create a Dataset draft, but a human must still freeze it through Dataset Registry.

### 8. Training / evaluation / model release — REUSE

Existing authorities:
- Phase 39 Evaluation Harness
- Phase 41 Training Registry
- Phase 42 Post-Training Readiness
- Phase 43 Model Release Decision
- later governed deployment / observation

Phase 41 already snapshots and revalidates training rights before execution.

Phase 80 must never queue training, approve a model, activate a model, or change routing automatically.

Commercial-training use must continue to require the existing commercial-training permission.

### 9. Sponsor billing / campaign funding — REUSE + EXTEND

Existing Stripe/account billing owns:
- Stripe Customers
- payment execution
- invoices
- webhook idempotency
- refunds/disputes
- commercial account billing
- credits/debits
- tax/invoice evidence

This is a customer/account billing system.

Phase 80 can use Stripe to collect sponsor funds and reconcile campaign funding, but sponsored research should have its own campaign funding ledger linked to the existing commercial Account and Stripe evidence.

Do not overload subscription invoices or account credits to represent contributor earnings.

### 10. Contributor compensation / payouts — NEW BOUNDED AUTHORITY

Audit found no existing:
- payout ledger
- researcher earnings ledger
- compensation ledger
- marketplace settlement engine
- escrow system

Phase 80 therefore needs a new bounded compensation subsystem.

Recommended boundary:
- Annotated owns earning entitlement, calculation, approval, reversals, disputes, and immutable payout ledger.
- Payment provider owns actual money movement.
- Provider payout integration should be adapter-based and should not be required for the first ledger section.

Do not model researcher earnings as commercial account credits.

### 11. Researcher marketplace / reputation — NEW PRESENTATION + SMALL LEDGER

Audit found no existing researcher marketplace or reputation system.

Phase 80 should derive as much as possible from existing authoritative evidence:
- public Research profile
- accepted sponsored submissions
- completed Missions / Reports
- review outcomes
- contribution provenance
- earned compensation
- subject/topic history

Any reputation score must be explainable and based on explicit events. Avoid opaque permanent scoring.

### 12. Existing Model Improvement Campaigns — PATTERN REUSE ONLY

Phase 47 Model Improvement Campaigns are:
- admin-only
- model-registry scoped
- tied to model remediation
- designed to orchestrate existing Dataset / Evaluation / Training systems

They are not the right storage or identity for sponsored human Research.

Reuse the design patterns:
- immutable campaign plan
- explicit scope
- append-only events
- downstream handoffs
- no authority stealing

Do not repurpose `data_model_improvement_campaigns`.

---

## New Phase 80 canonical concepts

Only these should become new primary authorities:

1. **Sponsored Research Campaign**
   - sponsor/account
   - Research scope
   - brief/objectives/questions
   - budget/funding policy
   - eligibility
   - disclosure
   - lifecycle
   - immutable locked brief/version

2. **Campaign Participation**
   - researcher opt-in
   - participation terms version
   - sponsorship disclosure acknowledgement
   - compensation policy acknowledgement
   - separate data/training consent references
   - eligibility snapshot

3. **Sponsored Submission**
   - immutable submitted snapshot
   - canonical Research object references
   - contribution/provenance references
   - review linkage
   - acceptance state

4. **Compensation Ledger**
   - earning entitlement
   - amount/currency
   - reason/source submission
   - approval
   - reversal/dispute
   - payout-provider state
   - immutable events

5. **Knowledge Base**
   - logical collection identity
   - immutable version manifests
   - canonical object/version refs
   - rights/consent/provenance snapshot
   - sponsor/campaign lineage

6. **Research Marketplace Listing**
   - presentation over campaigns + eligibility
   - no duplicate Research state

---

## Consent model

Keep these separate:

1. **Participation consent**
   - "I agree to participate in this sponsored campaign."

2. **Compensation / terms acceptance**
   - campaign-specific payment/license terms.

3. **Publication permission**
   - handled by existing Research visibility/publication authority.

4. **Shared retrieval permission**
   - existing Phase 37 grant.

5. **Evaluation permission**
   - existing Phase 37 grant.

6. **Training permission**
   - existing Phase 37 grant.

7. **Commercial training permission**
   - existing Phase 37 grant and Phase 41 revalidation.

A sponsor cannot make training consent a hidden side effect of campaign participation.

---

## Funding and compensation flow

Recommended first-class flow:

**Sponsor Account → Campaign funding commitment → accepted submission → earning entitlement → payout ledger → external payout provider**

Campaign funding should be append-only reconciled and must never infer that a researcher was paid merely because the sponsor was charged.

First implementation can support:
- funded amount
- reserved amount
- earned amount
- approved-for-payout amount
- paid amount
- reversed amount

Actual payout transport can be a later section.

---

## Knowledge and model-use lineage

Required durable lineage:

**Sponsored Campaign**
→ **Participation**
→ **Submission**
→ **Data Contribution / Provenance**
→ **Knowledge item**
→ **Knowledge Base version**
→ **Corpus item**
→ **Dataset version**
→ **Training / Evaluation job**
→ **Model version**
→ **Release decision**
→ **Deployment / usage evidence**

No downstream step may discard contributor identity, rights snapshots, or campaign lineage.

---

## Proposed Phase 80 section order

### Section 1 — Sponsored Campaign Foundation
Campaign identity, sponsor/account linkage, Research Agent/Project linkage, brief versioning, budget policy, disclosure, lifecycle, event ledger.

### Section 2 — Participation, Eligibility & Terms
Opt-in, invite/public/private participation, eligibility snapshot, terms/disclosure acknowledgement, consent references.

### Section 3 — Sponsored Submission & Provenance
Immutable submission snapshots over canonical Research objects and Phase 37 contribution/provenance capture.

### Section 4 — Review, Acceptance & Quality Governance
Extend the existing Collaborative Review engine for sponsored submissions; sponsor acceptance remains separate from publication.

### Section 5 — Compensation & Earnings Ledger
Entitlements, budget reservation/release, approval, reversals/disputes, researcher earnings history. No payout-provider transport yet.

### Section 6 — Versioned Knowledge Bases
Immutable manifests referencing canonical knowledge, provenance, consent and rights snapshots.

### Section 7 — Dataset Handoff & Usage Accounting
Knowledge Base → governed corpus/dataset draft; extend usage lineage across Agent retrieval, datasets, evaluation and training.

### Section 8 — Sponsor Funding & Billing
Use commercial Accounts + Stripe for sponsor funding and invoice evidence; campaign funding reconciliation remains local.

### Section 9 — Researcher Marketplace
Campaign discovery, eligibility, participation, researcher work/earnings surfaces, explainable quality signals.

### Section 10 — Sponsor Portal
Campaigns, submissions, budget, accepted work, Knowledge Bases, datasets, usage/impact, invoices.

### Section 11 — Payout Provider Integration
Provider adapter, payout execution, webhook reconciliation, failures/retries, no mutation of immutable earning entitlement.

### Section 12 — Governance, Analytics & Release Hardening
Conflict disclosures, withdrawal/revocation behavior, audit exports, sponsor/researcher analytics, end-to-end release gate.

---

## CI policy for Phase 80

Section PRs:
- title: `[section-gate]`
- Annotated CI
- changed DB journey(s)
- changed migration rehearsal(s)
- MariaDB + MySQL 8 targeted gates
- no historical full regression

Final Phase 80 release PR:
- title: `[release-gate]`
- full MariaDB historical regression
- full MySQL 8 compatibility / upgrade matrix
- package smoke
- release ZIPs

---

## Section 1 design constraint

Section 1 must be campaign foundation only.

It must not prematurely build:
- payouts
- datasets
- marketplace reputation
- sponsor portal
- training automation

The Section 1 schema should be narrow enough that later sections attach to it without redefining campaign identity.
