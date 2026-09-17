# Annotated V1 Code Audit

## Scope

Full-codebase review of the PHP web/API application, Chrome MV3 extension, MariaDB schema/migrations, media/transcription/source/AI workers, account/OAuth/session model, privacy/permissions, moderation/rights flows, research workspaces, and CI/release process.

## Baseline score: 7.6 / 10

The baseline had a strong provenance-first architecture and useful feature coverage, but it was not production-grade enough for a 10/10 beta. Material deductions included private evidence stored behind directly addressable paths, ambiguous OAuth linking/email ownership, passive page-text collection from the extension, overly broad Source-Version access, private browser captures entering shared source-monitor/AI state, viewer-role write paths, insufficient first-admin bootstrap protection, worker/version races, MariaDB DDL recovery assumptions, and primarily static CI contracts.

## 10/10 V1 audit rubric

Each category is worth 1 point. “10/10” means every release-blocking requirement below is implemented and passing—not that future features or refactors are impossible.

1. **Authentication & sessions** — hardened cookies/session rotation, CSRF, rate limits, revocable/expiring extension tokens, safe OAuth linking, verified-email semantics, protected first-admin bootstrap.
2. **Authorization & privacy** — centralized public/team/private checks, block enforcement, read-only viewer roles, exact Source-Version permissions, no private-object existence leakage through normal discovery APIs.
3. **Provenance & source integrity** — immutable Source Versions, annotation-specific private versions, monitor-only source baselines, bounded comparisons, deterministic change detection before AI.
4. **Evidence/media security** — evidence stored outside web root, access-controlled media gateway, upload validation, 90-second server/worker enforcement, 240p video derivatives.
5. **Extension privacy & boundary** — no passive page-body transmission, no all-page content-script injection, exact extension redirect/CORS allowlist, HTTPS API configuration except localhost development.
6. **AI safety & provenance** — encrypted provider keys, model access flags, permission-scoped context, prompt-injection boundaries, private source-monitor AI opt-in, audited runs, human-only moderation/legal decisions.
7. **Database & migrations** — ordered/checksummed migrations, advisory upgrade lock, recorded partial DDL failures, idempotent retry design, integrity constraints/indexes, concurrency-safe source/job state.
8. **Workers & reliability** — `SKIP LOCKED` job claiming, retries/statuses, bounded network/provider responses, SSRF protections, authorized media input roots, source-monitor concurrency controls.
9. **Abuse/moderation/research** — rate limits, blocking, rights claims/reports, role-aware team/research writes, privacy-scoped research and AI context.
10. **Testing & release discipline** — PHP 8.1/8.3 lint, JS/Manifest checks, security contracts, MariaDB 10.11 integration tests, green PR CI, mergeable exact head, deploy ZIP integrity.

## Audit hardening highlights

- Browser page text leaves Chrome only after explicit Publish; content scripts are injected on demand rather than on every site.
- Browser-private annotation Source Versions never become shared monitoring baselines. Source monitoring diffs monitor snapshots only.
- Private Source-Version access is tied to the exact annotation/team authorization rather than merely knowing or researching the same URL.
- External source-monitor AI is public-only by default; private/project monitoring requires explicit admin opt-in.
- Native unverified email accounts cannot be silently auto-linked by a later verified Google login; explicit signed-in linking is required.
- First-admin creation requires a deployment bootstrap secret in addition to being the first-user-only route.
- Evidence files are stored outside the web root and served through authorization-aware `/media.php`.
- Team viewers remain read-only for project, team-annotation discussion, and Team Live mutations.
- Real MariaDB integration tests were added to complement static release/security contracts.

## Release rule

The final score becomes **10.0 / 10 only when the exact hardening PR head passes every local/static gate plus both GitHub PHP matrix jobs with MariaDB integration tests and GitHub reports the PR mergeable**. If any gate fails, the score remains below 10 until corrected.
