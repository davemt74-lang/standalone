# Phase 75 — Research Agent Daily Experience & Production Polish

Phase 75 turns the simplified Phase 74 architecture into the everyday Research experience. The rule remains the same: keep the mature Research engines and authority boundaries; improve the user-facing path around them.

## Section 1 — Research Home & Agent Launcher

Section 1 rebuilds `/research.php` as the Research front door instead of another dense Research dashboard.

### Canonical home

Research home now gives the user five immediate choices:

1. open a recent Research Agent;
2. pin a frequently used Agent as a device-local favorite;
3. enter shared Team research;
4. open Intelligence Portfolios and organization-level attention;
5. continue the most recent Agent activity.

The page has one primary creation action, **+ New Research Agent**, and reuses the existing universal-shell creation dialog and `/api/research-agents.php?action=create` endpoint. No second create flow exists.

### Performance and simplification

The Phase 74 home rendered a chat feed plus task and Program summaries for every Agent. Section 1 removes those per-Agent page-load queries. The launcher uses the canonical `research_agent_list()` result and existing Portfolio/attention composition only.

The old page-local **Advanced Research tools** menu is removed from the Research home. Advanced and legacy routes remain available through their canonical surfaces and Phase 74 compatibility routing; they are no longer presented as primary launch choices.

### Favorites

Favorites are a UI convenience, not Research authority. They are stored in user-scoped browser `localStorage`, can be toggled without a network mutation, and only change launcher ordering. They do not alter Agents, projects, Teams, Programs, Decisions, Reports, Portfolios, permissions, or provenance.

### Data and authority

Section 1 is schema-free. It adds no migration, worker, scheduler, Research store, or background process. Research Agent creation, Team membership, Portfolio data, global attention and the Chat/Knowledge/Research/Reports shell remain authoritative in their existing Phase 47–74 systems.

### Acceptance

Section 1 is complete only when static contracts, the integrated MySQL journey, PHP 8.1/8.3 regression, package inclusion and package smoke all pass on the exact PR head.
