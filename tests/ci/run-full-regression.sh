#!/usr/bin/env bash
set -euo pipefail

: "${DB_DSN:?DB_DSN is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASS:=}"

bash tests/ci/run-static-contracts.sh

db_tests=(
  tests/install-db.php
  tests/integration-mariadb.php
  tests/integration-security-db.php
  tests/home-schema-preflight-db.php
  tests/research-knowledge-db.php
  tests/research-intelligence-db.php
  tests/research-entities-db.php
  tests/research-reports-db.php
  tests/research-library-db.php
  tests/rich-capture-db.php
  tests/phase6-feeds-db.php
  tests/phase7-public-discovery-db.php
  tests/phase8-live-db.php
  tests/phase9-trust-db.php
  tests/phase10-search-db.php
  tests/phase11-release-db.php
  tests/phase12a-conversations-db.php
  tests/phase12b-agent-chat-db.php
  tests/phase13-annotation-intelligence-db.php
  tests/phase14-research-workspace-db.php
  tests/phase15-agent-driven-research-db.php
  tests/phase16-cognitive-feed-db.php
  tests/phase17-proactive-intelligence-db.php
  tests/phase18-research-automation-db.php
  tests/phase19-cross-research-db.php
  tests/phase20-outcomes-db.php
  tests/phase21-research-reviews-db.php
  tests/phase22-change-impact-db.php
  tests/phase23-research-portfolio-db.php
  tests/phase24-living-research-db.php
  tests/phase25-research-network-db.php
  tests/phase26-research-provenance-db.php
  tests/phase27-research-verification-db.php
  tests/phase28-research-evidence-packs-db.php
  tests/phase29-research-workflow-db.php
  tests/phase30-research-completion-db.php
  tests/research-v1-final-audit-db.php
  tests/phase31-unified-object-handoff-db.php
  tests/phase33-unified-activity-db.php
  tests/phase34-workspace-context-db.php
  tests/phase35-action-center-db.php
  tests/phase36-end-to-end-workspace-journey-db.php
  tests/phase37-data-attribution-db.php
  tests/phase38-dataset-registry-db.php
  tests/phase39-dataset-evaluation-db.php
  tests/phase40-model-registry-db.php
  tests/phase41-training-registry-db.php
  tests/phase42-post-training-readiness-db.php
  tests/phase43-model-release-decision-db.php
  tests/phase44-model-deployment-db.php
  tests/phase45-model-observability-db.php
  tests/phase46-model-improvement-db.php
  tests/phase47-model-campaign-db.php
  tests/phase48-closed-loop-release-audit-db.php
  tests/phase49-release-candidate-ops-db.php
  tests/phase49-5-recent-build-hardening-db.php
  tests/phase50-research-agent-workspace-db.php
  tests/phase51-research-docs-stickies-db.php
  tests/phase52-research-agent-desktop-db.php
  tests/phase53-desktop-files-recordings-db.php
  tests/phase54-unified-research-retrieval-db.php
  tests/phase55-autonomous-research-workspace-db.php
  tests/phase56-continuous-research-monitoring-db.php
  tests/phase57-research-tasks-plans-deliverables-db.php
  tests/phase58-research-programs-recurring-intelligence-db.php
  tests/phase59-collaborative-review-approval-publishing-db.php
  tests/phase60-research-intelligence-portfolios-db.php
  tests/phase61-portfolio-intelligence-operations-db.php
  tests/phase62-v1-1-final-release-db.php
  tests/phase62-v1-1-soak-db.php
  tests/phase63-vp3-connection-db.php
  tests/profile-phase2-showcase-db.php
  tests/profile-phase3-research-network-db.php
  tests/admin-subscriptions-packages-v1-db.php
  tests/admin-ai-usage-v1-db.php
  tests/admin-v1-20-dashboard-db.php
  tests/admin-v1-30-account-lifecycle-db.php
  tests/admin-v1-40-stripe-billing-db.php
  tests/code-audit-hardening-db.php
)

for test_file in "${db_tests[@]}"; do
  php "$test_file"
done
