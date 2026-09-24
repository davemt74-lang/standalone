#!/usr/bin/env bash
set -euo pipefail

php tests/release-contracts.php
php tests/security-contracts.php
php tests/app-shell-contracts.php
php tests/home-runtime-hardening-contract.php
php tests/home-render-integrity-contract.php
php tests/home-agent-feed-contract.php
php tests/profile-standalone-ui-contract.php
php tests/profile-phase2-showcase-contract.php
php tests/profile-phase3-research-network-contract.php
php tests/admin-subscriptions-packages-v1-contract.php
php tests/admin-ai-usage-v1-contract.php
php tests/admin-v1-20-information-architecture-contract.php
php tests/admin-v1-30-account-lifecycle-contract.php
php tests/admin-v1-40-stripe-billing-contract.php
php tests/code-audit-hardening-contract.php
php tests/admin-v1-50-account-membership-contract.php
php tests/admin-v1-60-billing-analytics-contract.php
php tests/admin-v1-70-ai-overage-billing-contract.php
php tests/research-folder-canvas-contract.php
php tests/workspace-library-canvas-contract.php
php -n tests/runtime-compat-contract.php
php -n tests/installer-runtime-compat-contract.php
php tests/concurrency-contracts.php
php tests/rate-limit-contracts.php
php tests/v1-rc-e2e-contract.php
php tests/phase32-unified-continuity-actions.php

while IFS= read -r test_file; do
  php "$test_file"
done < <(find tests -maxdepth 1 -type f -name 'phase*-contract.php' -print | sort -V)
