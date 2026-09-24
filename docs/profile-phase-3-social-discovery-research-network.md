# Profile Phase 3 — Social Discovery & Research Network

Profile Phase 3 connects Annotated's public identity layer to the existing search, follow, notification, feed, and Research Agent systems.

## Architecture

No Phase 3 migration is introduced. Migration 061 remains current.

Phase 3 derives its network from authoritative existing records:

- `users` + `user_preferences` for public/searchable identity
- `follows` and `blocks` for social relationships
- public `annotations` + `sources` for shared evidence
- immutable public `research_reports` + discovery entities for topic affinity
- public `collections` for curation
- existing `notifications` and Research notification preferences
- existing Research Agent `project_annotations`, `project_sources`, Sources/Source Versions, and workspace bookmarks for explicit handoff.

There is no recommendation table, social graph copy, parallel feed, messaging system, or duplicate Research store.

## People discovery

`/people.php` exposes public, searchable, non-blocked profiles. Search also matches public Research titles/summaries. Cards display public Research/annotation counts and public topic/domain context.

Authenticated suggestions are explainable. Candidate ranking can use:

- shared public Research topics/entities
- mutual followed accounts
- shared public Sources.

The UI shows these reasons instead of an opaque recommendation score.

## Existing surface integration

- Explore surfaces Research-oriented people and links to dedicated people discovery.
- Unified Search enriches person results with public Research/topic context.
- Home → Latest keeps one chronological feed and adds public Research Reports/collections from followed users to the existing annotation/bookmark stream.
- Existing annotation Following behavior remains unchanged.

## Notifications

The existing new-follower notification remains authoritative.

After a governed public Research publication succeeds, followers receive a `research_followed_publication` notification. This intentionally uses the existing `research` notification category so `notify_research`, blocks, mutes, dedupe, object access, and notification URLs continue to govern delivery.

## Research handoff

From another user's public profile:

- a public Annotation can be explicitly added to a Research Agent by reference through `project_annotations`
- a public immutable Research Report can be explicitly added as a Source + Source Version + workspace bookmark.

The report Source Version stores:
- extracted evidence text using the normal Source content hash
- the public report ID/version
- the immutable report snapshot SHA-256 separately in provenance metadata.

Re-importing the same immutable report is idempotent. Blocks immediately prevent new handoffs.

## Privacy

Public profile/search visibility, content visibility, blocks, report publication state, and existing Research ACLs remain live authority. Discovery cannot bypass those boundaries. `View as public` does not expose signed-in owner Research handoff controls.
