# Profile Phase 2 — Public Identity & Research Showcase

Profile Phase 2 turns the standalone profile into a public presentation layer over existing Annotated objects.

## Boundaries

- Annotations remain authoritative in the annotation system.
- Research remains authoritative in immutable published `research_reports`.
- Collections remain authoritative in `collections` and `collection_items`.
- Profile visibility never makes an underlying private/team object public.
- Research Agents remain private workspace objects; only deliberately published public Research Reports appear.
- The profile introduces no duplicate activity, Research, collection, follow, or publication store.

## Surface

The profile remains outside the universal header/sidebar and exposes:

- Activity — a chronological composition of public annotations, public Research Reports, and public collections.
- Annotations — existing public annotation cards.
- Research — existing immutable public Research Reports.
- Collections — existing public collections.
- About — bio, website, membership date, and public-work totals.

Owners can view the profile as public, choose whether Research/Collections/About tabs are shown, and pin up to three public annotations, reports, or collections.

## Pin safety

Pins are presentation references only. A pin is valid only while the underlying object belongs to the profile owner and remains public. Stale/private pins are pruned before the three-item limit is enforced.

## Social lists

Followers and Following open standalone people lists. Aggregate profile counts remain authoritative, while the lists themselves only reveal profiles currently public and accessible to the viewer. Private or blocked profiles are not disclosed.

## Migration

Migration 061 adds three display preferences and `profile_pins`. It does not alter the visibility of any existing content.
