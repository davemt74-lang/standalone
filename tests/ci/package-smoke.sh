#!/usr/bin/env bash
set -euo pipefail
website="${1:-Annotated-Website.zip}"
extension="${2:-Annotated-Chrome-Extension.zip}"
[[ -f "$website" && -f "$extension" ]] || { echo "Release ZIPs are required." >&2; exit 2; }
unzip -t "$website" >/dev/null
unzip -t "$extension" >/dev/null
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/site" "$tmp/ext"
unzip -q "$website" -d "$tmp/site"
unzip -q "$extension" -d "$tmp/ext"
required=(
  index.php install.php upgrade.php RELEASE-MANIFEST.json
  app/release.php app/release-operations.php app/schema-health.php app/research-library.php app/research-agents.php app/research-agent-workspace.php app/research-agent-workspace-ui.php app/research-retrieval.php app/research-autonomy.php app/research-monitoring.php app/research-tasks.php app/research-programs.php app/research-reviews.php app/research-publishing.php api/research-agents.php api/research-workspace-objects.php api/research-workspace-upload.php api/research-retrieval.php api/research-autonomy.php api/research-monitoring.php api/research-tasks.php api/research-programs.php api/research-publications.php research-workspace-file.php worker/research-file-worker.php worker/research-transcription-worker.php worker/research-retrieval-worker.php worker/research-autonomy-worker.php worker/research-monitor-worker.php worker/research-task-worker.php worker/research-program-worker.php assets/js/research-agent-shell.js assets/js/research-agent-workspace-ui.js research.php research-monitoring.php research-tasks.php research-programs.php research-reviews.php research-publications.php saved.php teams.php annotation.php profile.php
  admin/system-health.php admin/intelligence-release-audit.php admin/model-campaigns.php
  bin/release-preflight.php bin/release-backup.php bin/release-backup-verify.php bin/release-restore-plan.php
  database/schema.sql database/migrations/20260922_046_model_improvement_campaigns.sql database/migrations/20260922_047_research_agents.sql database/migrations/20260923_048_research_agent_workspace_core.sql database/migrations/20260923_049_research_docs_floating_stickies.sql database/migrations/20260923_050_research_agent_desktop.sql database/migrations/20260923_051_research_desktop_uploads_recordings.sql database/migrations/20260923_052_unified_research_retrieval.sql database/migrations/20260923_053_autonomous_research_workspace.sql database/migrations/20260923_054_continuous_research_monitoring.sql database/migrations/20260923_055_research_tasks_plans_deliverables.sql database/migrations/20260923_056_research_programs_recurring_intelligence.sql database/migrations/20260923_057_collaborative_review_approval_publishing.sql database/migrations/20260923_058_research_intelligence_portfolios_executive_briefing.sql database/migrations/20260923_059_portfolio_intelligence_operations_follow_through.sql database/migrations/20260923_060_vp3_account_connection_research_ingestion.sql database/migrations/20260923_061_profile_public_identity_showcase.sql database/migrations/20260924_062_subscriptions_packages_accounts.sql database/migrations/20260924_063_ai_usage_metering.sql database/migrations/20260924_064_account_lifecycle_membership_entitlements.sql database/migrations/20260924_065_stripe_billing_checkout.sql database/migrations/20260924_066_code_audit_hardening.sql database/migrations/20260924_067_ai_quota_checkout_idempotency.sql
  docs/RELEASE-V1.1-RC1.md docs/RELEASE-V1.1.md docs/phase-49-release-candidate-operational-hardening.md docs/phase-51-research-docs-floating-stickies.md docs/phase-52-research-agent-desktop.md docs/phase-53-desktop-files-recordings.md docs/phase-54-unified-research-retrieval.md docs/phase-55-autonomous-research-workspace.md docs/phase-56-continuous-research-monitoring.md docs/phase-57-research-tasks-plans-deliverables.md docs/phase-58-research-programs-recurring-intelligence.md docs/phase-59-collaborative-review-approval-publishing.md docs/phase-60-research-intelligence-portfolios-executive-briefing.md docs/phase-61-portfolio-intelligence-operations-follow-through.md docs/phase-62-v1-1-final-release-validation-production-cutover.md tests/phase62-final-release-contract.php tests/phase62-v1-1-final-release-db.php tests/phase62-v1-1-soak-db.php tests/ci/phase62-upgrade-supported-baselines.php docs/phase-63-vp3-account-connection-research-ingestion.md tests/phase63-vp3-connection-contract.php tests/phase63-vp3-connection-db.php tests/ci/phase63-upgrade-from-059.php app/vp3-connector.php app/profile-showcase.php vp3/connect.php vp3/callback.php vp3-library.php docs/profile-phase-2-public-identity-research-showcase.md app/profile-showcase.php profile-connections.php tests/profile-phase2-showcase-contract.php tests/profile-phase2-showcase-db.php tests/ci/profile-phase2-upgrade-from-060.php docs/profile-phase-3-social-discovery-research-network.md app/profile-network.php people.php tests/profile-phase3-research-network-contract.php tests/profile-phase3-research-network-db.php docs/admin-subscriptions-packages-v1.md app/subscriptions.php admin/packages.php tests/admin-subscriptions-packages-v1-contract.php tests/admin-subscriptions-packages-v1-db.php tests/ci/subscriptions-v1-upgrade-from-061.php docs/admin-ai-usage-v1.md app/ai-usage.php admin/usage.php tests/admin-ai-usage-v1-contract.php tests/admin-ai-usage-v1-db.php tests/ci/ai-usage-v1-upgrade-from-062.php docs/admin-v1-20-information-architecture-dashboard.md app/admin-ui.php admin/accounts.php tests/admin-v1-20-information-architecture-contract.php tests/admin-v1-20-dashboard-db.php docs/admin-v1-30-account-lifecycle-membership-entitlements.md app/account-admin.php admin/account.php tests/admin-v1-30-account-lifecycle-contract.php tests/admin-v1-30-account-lifecycle-db.php tests/ci/admin-v1-30-upgrade-from-063.php docs/admin-v1-40-stripe-billing-checkout.md app/stripe-billing.php admin/billing.php billing.php stripe/webhook.php tests/admin-v1-40-stripe-billing-contract.php tests/admin-v1-40-stripe-billing-db.php tests/ci/admin-v1-40-upgrade-from-064.php docs/code-audit-hardening-10-10.md tests/code-audit-hardening-contract.php tests/code-audit-hardening-db.php tests/ci/code-audit-upgrade-from-065.php tests/ci/code-audit-upgrade-from-066.php
  extension/manifest.json downloads/Annotated-Chrome-Extension.zip
)
for path in "${required[@]}"; do [[ -f "$tmp/site/$path" ]] || { echo "Missing website package file: $path" >&2; exit 1; }; done
[[ -f "$tmp/ext/manifest.json" ]] || { echo "Extension manifest missing at ZIP root." >&2; exit 1; }
[[ ! -e "$tmp/site/config.php" ]] || { echo "Production config.php must never ship in release package." >&2; exit 1; }
[[ ! -e "$tmp/site/.git" && ! -e "$tmp/site/.github" ]] || { echo "Repository internals must not ship." >&2; exit 1; }
if find "$tmp/site" -type l -print -quit | grep -q .; then echo "Release package must not contain symlinks." >&2; exit 1; fi
outer_sha="$(sha256sum "$extension" | awk '{print $1}')"
embedded_sha="$(sha256sum "$tmp/site/downloads/Annotated-Chrome-Extension.zip" | awk '{print $1}')"
[[ "$outer_sha" == "$embedded_sha" ]] || { echo "Embedded Chrome ZIP differs from standalone Chrome ZIP." >&2; exit 1; }
SITE="$tmp/site" EXT="$tmp/ext" php -r '
$site=getenv("SITE");$ext=getenv("EXT");
$r=json_decode(file_get_contents($site."/RELEASE-MANIFEST.json"),true,512,JSON_THROW_ON_ERROR);
$m=json_decode(file_get_contents($ext."/manifest.json"),true,512,JSON_THROW_ON_ERROR);
if(($r["version"]??"")!=="1.1.0")throw new RuntimeException("Unexpected application release version.");
if((int)($r["phase"]??0)!==62)throw new RuntimeException("Unexpected release phase.");
if(($r["channel"]??"")!=="stable")throw new RuntimeException("Unexpected release channel.");
if(($r["extension_version"]??"")!=="0.36.0"||($m["version"]??"")!=="0.36.0")throw new RuntimeException("Extension/release version mismatch.");
if(($m["manifest_version"]??0)!==3)throw new RuntimeException("Extension must remain Manifest V3.");
$migrationFiles=glob($site."/database/migrations/*.sql")?:[];sort($migrationFiles,SORT_STRING);$expectedMigration=$migrationFiles?basename((string)end($migrationFiles)):"";if(($r["latest_migration"]??"")!==$expectedMigration||$expectedMigration==="")throw new RuntimeException("Release manifest does not identify latest packaged migration.");
if(!preg_match("/^[a-f0-9]{64}$/",(string)($r["package_fingerprint"]??"")))throw new RuntimeException("Release package fingerprint missing.");
require $site."/app/release.php"; require $site."/app/release-operations.php";
if(!hash_equals((string)$r["package_fingerprint"],release_package_fingerprint($site)))throw new RuntimeException("Extracted package fingerprint does not match release manifest.");
$status=release_installed_manifest_status($site);if(!$status["pass"])throw new RuntimeException($status["detail"]);
'
for file in home.php profile-connections.php admin/usage.php app/ai-usage.php admin/accounts.php admin/account.php admin/billing.php billing.php stripe/webhook.php app/admin-ui.php app/account-admin.php app/stripe-billing.php research.php research-monitoring.php research-tasks.php research-programs.php research-reviews.php research-publications.php research-intelligence-portfolios.php research-intelligence-command-center.php profile.php research-workspace-file.php app/research-library.php app/research-agents.php app/research-agent-workspace.php app/research-agent-workspace-ui.php api/research-agents.php api/research-workspace-objects.php api/research-workspace-upload.php api/research-retrieval.php api/research-autonomy.php api/research-monitoring.php api/research-tasks.php api/research-programs.php api/research-publications.php worker/research-file-worker.php worker/research-transcription-worker.php worker/research-retrieval-worker.php worker/research-autonomy-worker.php worker/research-monitor-worker.php worker/research-task-worker.php worker/research-program-worker.php app/research-retrieval.php app/research-autonomy.php app/research-monitoring.php app/research-tasks.php app/research-programs.php app/research-reviews.php app/research-publishing.php app/research-intelligence-portfolios.php app/research-intelligence-operations.php api/research-intelligence-portfolios.php app/vp3-connector.php app/subscriptions.php vp3/connect.php vp3/callback.php vp3-library.php admin/packages.php admin/users.php app/bootstrap.php app/runtime-compat.php app/schema-health.php app/release.php app/release-operations.php admin/system-health.php bin/release-preflight.php bin/release-backup.php bin/release-backup-verify.php bin/release-restore-plan.php; do php -l "$tmp/site/$file" >/dev/null; done
php -n "$tmp/site/tests/runtime-compat-contract.php" >/dev/null
echo "Phase 62 V1.1 final release package smoke test passed."
echo "Phase 55 autonomous Research package extensions passed."
echo "Phase 56 Continuous Research Monitoring package extensions passed."
echo "Phase 57 Research Tasks, Plans & Deliverables package extensions passed."

echo "Phase 58 Research Programs & Recurring Intelligence package extensions passed."

echo "Phase 59 Collaborative Review, Approval & Publishing package extensions passed."

echo "Phase 60 Research Intelligence Portfolios & Executive Briefing package extensions passed."

echo "Phase 61 Portfolio Intelligence Operations & Executive Follow-Through package extensions passed."

echo "Phase 62 V1.1 stable production package extensions passed."

echo "Phase 63 VP3 Account Connection & Research Ingestion package extensions passed."

echo "Profile Phase 2 public identity & Research showcase package extensions passed."

echo "Profile Phase 3 Social Discovery & Research Network package extensions passed."

echo "Subscriptions & Packages V1 package extensions passed."
echo "Admin V1.20 information architecture & dashboard package extensions passed."
echo "Admin V1.30 account lifecycle, membership & entitlement package extensions passed."
echo "Admin V1.40 Stripe billing, checkout & self-service package extensions passed."
echo "Code audit hardening 10/10 package extensions passed."
