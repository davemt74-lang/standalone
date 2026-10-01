# Annotated V1 — Final Product Code Audit and Release Plan
Baseline: `annotated-v1-1-development` at `faed0dea02ef3cf4afe9f6afb841c19f2c866b54`. Score is provisional static/release-readiness, not proof of a deployed installation.

## V1 scope freeze
Deliver one coherent platform: user identity and Chrome MV3 source capture/annotation; research Agents, personal Research Desktop/Library, tasks/missions/reports; approved Sponsor/Research Accounts and governed Sponsored Project submission/review; admin controls and Chrome release manager; notifications, backups, upgrade and operational health. Reuse the existing single Agent Chat and native tasks/automation/notifications. No new platform runtime or app subsystem for V1.

## Provisional baseline 7.1/10 (equal-weight 10-area rubric)
| Area | Score | Evidence and gap |
|---|---:|---|
| Authentication/recovery | 4.0 | Session/CSRF/rate limits present; unsafe `reset-admin.php` shipped in previous full deploy ZIP. First-admin has an owner-setup exposure window. |
| Authorization and privacy | 8.0 | Scoped Sponsored Agent permissions and 4E exact-head green checks; not a whole-product penetration certification. |
| Core user experience | 7.0 | Substantial shell/feeds/admin/screens; real device/browser layout and accessibility remain unverified. |
| Research/Agent architecture | 8.0 | Native Agent Chat confirmation, research tasks, source citations, reports; staging provider/worker journey not yet certified. |
| Sponsored Research | 9.0 | 4A–4E existing E2E, exact-head five checks green; actual scheduled notification delivery not verified. |
| Chrome extension | 7.0 | Valid deployed MV3 ZIP, managed releases; broad all-URL permission and automatic listener warrant consent/Chrome QA. |
| Schema/migrations | 8.5 | Ordered through _129, upgrade ledger and 4E MariaDB/MySQL8; final fresh-install and backup/restore rehearsal outstanding. |
| Workers/observability | 6.0 | Canonical heartbeat and worker roster; operator cron/queue/credentials have not been observed. |
| Deployment/recovery | 5.0 | ZIP and checksum validation works; legacy reset endpoint plus insufficient internal-source HTTP denies; dev and main release promotion unresolved. |
| CI and quality gates | 8.0 | Five 4E section checks green; 829 bundled PHP and 21 bundled app JS syntax-clean locally. Browser and production-like acceptance missing. |

## Blockers and ordered build
**P0 A — HTTP and privileged recovery safety.** Remove legacy unauthenticated recovery endpoint, deny stale URL and internal app/bin/database/docs/tests/worker/storage paths in root Apache rules, supply equivalent Nginx runbook, add an independent static contract and block affected ZIP packages. **An overlay deployment does not delete the old reset-admin.php on a live host; the operator must delete it.**

**P0 B — owner-first install and recovery.** Keep an empty site restricted during installation; certify first admin, session revocation, safe owner-controlled `admin-password-reset.php` marker and all supported forward upgrades on isolated staging.

**P0 C — full product journey.** Test actual browser flows: account and extension pairing; source annotation; personal Research Agent creation/task/report; Sponsor creation and Research Account approval; exact assignment and accepted terms; explicit confirmed progress/submission; original human review; authorized deadline/blocker/revision notices. Denied outsider/revocation/paused/demo/Team boundaries must remain intact.

**P0 D — operations and recovery.** Configure the existing Research Automation cron (not a second scheduler), check real heartbeat and notifications, verify all worker dependencies and encrypted provider keys, rehearse backups and restore on staging, then run post-deploy smoke.

**P1 — polish, not new subsystems.** Profile responsiveness and no horizontal scroll, consistent Admin canvas/search, samples toggle, Sponsored Project details, extension-manager install/update journey and reduced extension origin privileges if compatible. Hold broad new platform features.

**Final V1 gate.** Freeze branch, choose one deliberate version/release tag (existing V1.1.0 Phase 62 identifier is legacy intentional; do not casually mutate compatibility constants), pass focused + complete release regression on the exact candidate, publish full Website/Chrome ZIPs plus SHA-256 and immutable build manifest, promote the accepted release from dev to main and verify deployment.

## Evidence and limitations
The packaged source has 829 syntax-clean PHP files and 21 syntax-clean application JS files in local installed PHP 8.4/Node 22 checks. The Website ZIP intentionally omits full extension source and GitHub workflows; a direct run of the complete repository static suite against the deployed ZIP will fail missing-source tests. Use repository exact-head CI for authoritative source-tree verification.

This is an architectural and code/release audit. No production credentials, live host, browser acceptance, actual cron runtime, payment gateway or destructive restore was accessed. Final 10/10 requires these operational and UX gates, not just a green PR or archive hash.
