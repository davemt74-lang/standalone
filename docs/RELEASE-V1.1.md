# Annotated V1.1 Production Release Runbook

This runbook applies to **Annotated V1.1 (`1.1.0`)** on the current Phase 74 release-hardened source tree. The stable product identity remains V1.1; Phase 74 is a schema-free Research Agent simplification release on top of migration 103.

## Fresh install

1. Create an empty MariaDB or MySQL 8 database.
2. Upload the tested website ZIP and open `/install.php`.
3. Complete database configuration and create the first administrator.
4. The installer applies the base schema and every bundled migration through 103.
5. Configure production settings and external private storage.
6. Start the canonical workers listed by `release_worker_specs()`.
7. Run `php bin/release-preflight.php`.
8. Do not open production traffic until System Health has no blocking release failures.

## Upgrade from V1.1 RC1 or a supported V1.1 development state

1. Run `php bin/release-preflight.php` on the current deployment.
2. Run `php bin/release-backup.php --dry-run --json`.
3. Create a matched database/private-storage backup outside the application tree.
4. Verify the backup with `php bin/release-backup-verify.php`.
5. Archive the generated restore plan from `php bin/release-restore-plan.php`.
6. Record the current commit, deploy ZIP SHA-256, package fingerprint, and backup ID.
7. Put application traffic into maintenance mode and stop workers.
8. Deploy the exact tested V1.1 website package while preserving production `config.php` and external private storage.
9. Open `/upgrade.php` as an administrator and apply all pending migrations.
10. Reload `/upgrade.php`; there must be zero pending migrations and no failed migration receipt.
11. A repeated upgrade attempt must be a no-op.
12. Restart workers and run `php bin/release-preflight.php` again.
13. Reopen traffic only after health checks and the post-deploy journey pass.

Phase 62 CI explicitly rehearses migration-046 (RC1-era), migration-056, and migration-058 databases through the original V1.1 migration-059 boundary. Phase 72 CI additionally rehearses representative supported development states at migrations 092, 093, 095, and 097 through migration 098. **Phase 73 CI** rehearses representative baselines at migrations 098, 099, 100, 101, and 102 through the final Phase 73 schema boundary at **migration 103**. These upgrades must preserve existing Collaborative Review state, fabricate no Pattern Memory, Strategic Graph, Strategic Review, or Strategic Briefing rows, end with zero pending migrations, and be repeat-safe. Phase 74 is application-only and intentionally adds no migration 104. **Phase 74 CI** re-runs fresh install, the supported historical upgrade matrix through migration 103, the complete PHP 8.1/8.3 regression suites, model governance, legacy-route compatibility, and the final simplified Research Agent acceptance journey.

## Required workers

Use the canonical schedule reported by `release_worker_specs()`. Phase 61 Portfolio cycles execute inside:

`php bin/research-automations.php --limit=25`

There is no separate Portfolio or Phase 62 worker. Phase 72 also introduces no Action Plan worker, Action Plan scheduler, Action Plan queue, cognition worker, or outcome worker; Action Plans continue to reuse the existing Research Task and Research Program runtimes. Phase 73 introduces no Portfolio Decision worker, Pattern Memory worker, Strategic Graph worker, Strategic Review worker, Strategic Briefing worker, or Organizational Cognition worker. Recurring Strategic Reviews reuse the existing Portfolio cycle clock, and all durable Agent follow-through continues through the existing confirmation ledger. Phase 74 adds no simplification worker, scheduler, queue, or replacement authority layer; it only consolidates presentation and compatibility routing.

## Post-deploy V1.1 journey

Validate with non-admin Team accounts:

1. Create/open a Research Agent.
2. Add evidence and a Claim.
3. Create/complete a Research follow-up task.
4. Run/inspect a Research Program.
5. Create a versioned Research Doc.
6. Request human review and approval.
7. Publish an immutable report through Phase 59.
8. Add Programs to an Intelligence Portfolio.
9. Run/observe a Portfolio intelligence cycle.
10. Review the Executive Briefing.
11. Record an executive decision and create a follow-up task.
12. Confirm Decision Memory and Now/Command Center reflect the follow-through.
13. Run the next Portfolio cycle and verify briefing comparison continuity.
14. Revoke a Team member and verify Research, Portfolio, and notification access disappears immediately.
15. Create or open an Accepted Research Decision.
16. Explicitly create a Draft Action Plan from that Decision and confirm a retry with the same idempotency key does not duplicate it.
17. Add milestones and existing Research Tasks; link an existing Research Program for recurring follow-through.
18. Request Team Review and confirm approval remains advisory until the owner explicitly activates the Action Plan.
19. Activate the Action Plan and verify an immutable execution baseline is captured.
20. Record execution evidence and confirm material variance appears in Agent Strategic Memory and the Action Plan Command Center.
21. Complete existing Research Tasks and milestones, then explicitly complete the Action Plan.
22. Confirm completion alone does not create Outcome Memory and the Command Center flags the completed plan as awaiting outcome.
23. Explicitly record the final human outcome and verify the durable chain **Decision → Action Plan → Execution → Outcome Memory**.
24. Confirm the observed outcome creates the existing Decision reconsideration signal but does not change Decision status by itself.
25. Resolve/apply reconsideration explicitly and verify only that action changes the Decision.
26. Revoke a Team member and verify Action Plan, Team Review, and final outcome-provenance access disappears immediately.
27. From an Intelligence Portfolio, create two native Decisions and verify historical Portfolio decision records remain visible alongside native Decision Memory.
28. Confirm Portfolio Decision/Action Plan rollups reflect the authoritative Phase 71/72 records without duplicating them.
29. Refresh Cross-Decision Pattern Memory and verify repeated assumptions, risks, variance, outcomes, or lessons are backed by exact durable members.
30. Record a Strategic Graph relationship and verify its endpoint revisions/state hashes are frozen without changing either source object.
31. Create a frozen Strategic Review, complete the existing Collaborative Review, and verify later strategic changes appear as drift rather than rewriting the packet.
32. Create an Executive Strategic Briefing from an approved Strategic Review and confirm publication remains blocked until its existing Team Review is current and unanimously approved.
33. Confirm the approved Strategic Briefing enters the existing Phase 59 publication workflow rather than a parallel publisher.
34. Open Organizational Cognition and verify explainable Decision reconsideration, execution, Strategic Graph, review/briefing attention, and exact Decision analogues include provenance.
35. Ask the Research Agent for governed follow-through and verify the proposal is pending and non-mutating until explicit confirmation.
36. Confirm a governed proposal creates only its safe entry state (for example a Draft Decision or Draft Action Plan), and verify changed Portfolio/Decision state makes an older proposal stale.
37. Revoke a Team member and verify Portfolio, Strategic Review, Strategic Briefing, Team Review, Pattern/Graph, and Organizational Cognition access disappears immediately.
38. Open global Research and verify the primary model is only **Research Agents | Portfolios**.
39. Open one Research Agent and verify the primary tabs are exactly **Chat | Knowledge | Research | Reports**, preserving the same Agent/Project/conversation identity.
40. Verify Knowledge is **Library | Insights | Changes**, Research is **Missions | Tasks | Decisions | Follow-through | Recurring**, and Reports is **Create | Recent | Scheduled | Published**.
41. Verify generic legacy Project, Knowledge, Research Brief, Automations, Review Center, old Portfolio, and Command Center URLs resolve into their canonical Phase 74 surfaces without duplicating state.
42. Verify object-specific legacy inspectors remain usable where required and that `report.php` / `report-status.php` remain Trust & Safety moderation routes outside Research.
43. Revoke a Team member and verify the simplified shell immediately loses access without creating a duplicate Agent, Project, or permission cache.

## Chrome extension

The V1.1 package includes the Manifest V3 Chrome component version **0.36.0**. Confirm the standalone extension ZIP matches the website-embedded ZIP byte-for-byte.

## Rollback

Migrations are forward-only. Never run an older application binary against a partially upgraded database.

If rollback is required:

1. Stop traffic and every worker.
2. Restore the matched pre-deploy database and private-storage backup.
3. Restore the previous known-good application package and configuration.
4. Restart workers.
5. Run the prior release health checks.
6. Reopen traffic only after application/data versions are consistent.

Restore planning is generated by Annotated, but destructive restore execution remains a deliberate operator action.

## Release evidence to archive

- merged Phase 74 Section 8 SHA and tree SHA
- CI and full-regression run IDs
- stable package fingerprint
- website ZIP SHA-256
- Chrome ZIP SHA-256
- backup ID and verification result
- preflight output
- post-deploy health evidence
- exact deployed V1.1 package commit SHA (and `v1.1.0` tag SHA when deploying the canonical stable tag)
