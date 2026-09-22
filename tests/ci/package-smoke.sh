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
  app/release.php app/release-operations.php
  admin/system-health.php admin/intelligence-release-audit.php admin/model-campaigns.php
  bin/release-preflight.php bin/release-backup.php bin/release-backup-verify.php bin/release-restore-plan.php
  database/schema.sql database/migrations/20260922_046_model_improvement_campaigns.sql
  docs/RELEASE-V1.1-RC1.md docs/phase-49-release-candidate-operational-hardening.md
  extension/manifest.json downloads/Annotated-Chrome-Extension.zip
)
for path in "${required[@]}"; do [[ -f "$tmp/site/$path" ]] || { echo "Missing website package file: $path" >&2; exit 1; }; done
[[ -f "$tmp/ext/manifest.json" ]] || { echo "Extension manifest missing at ZIP root." >&2; exit 1; }
[[ ! -e "$tmp/site/config.php" ]] || { echo "Production config.php must never ship in release package." >&2; exit 1; }
[[ ! -e "$tmp/site/.git" && ! -e "$tmp/site/.github" ]] || { echo "Repository internals must not ship." >&2; exit 1; }
outer_sha="$(sha256sum "$extension" | awk '{print $1}')"
embedded_sha="$(sha256sum "$tmp/site/downloads/Annotated-Chrome-Extension.zip" | awk '{print $1}')"
[[ "$outer_sha" == "$embedded_sha" ]] || { echo "Embedded Chrome ZIP differs from standalone Chrome ZIP." >&2; exit 1; }
SITE="$tmp/site" EXT="$tmp/ext" php -r '
$site=getenv("SITE");$ext=getenv("EXT");
$r=json_decode(file_get_contents($site."/RELEASE-MANIFEST.json"),true,512,JSON_THROW_ON_ERROR);
$m=json_decode(file_get_contents($ext."/manifest.json"),true,512,JSON_THROW_ON_ERROR);
if(($r["version"]??"")!=="1.1.0-rc1")throw new RuntimeException("Unexpected application release version.");
if((int)($r["phase"]??0)!==49)throw new RuntimeException("Unexpected release phase.");
if(($r["extension_version"]??"")!=="0.36.0"||($m["version"]??"")!=="0.36.0")throw new RuntimeException("Extension/release version mismatch.");
if(($m["manifest_version"]??0)!==3)throw new RuntimeException("Extension must remain Manifest V3.");
if(($r["latest_migration"]??"")!=="20260922_046_model_improvement_campaigns.sql")throw new RuntimeException("Release manifest does not identify latest migration.");
if(!preg_match("/^[a-f0-9]{64}$/",(string)($r["package_fingerprint"]??"")))throw new RuntimeException("Release package fingerprint missing.");
require $site."/app/release.php"; require $site."/app/release-operations.php";
if(!hash_equals((string)$r["package_fingerprint"],release_package_fingerprint($site)))throw new RuntimeException("Extracted package fingerprint does not match release manifest.");
'
for file in app/release.php app/release-operations.php admin/system-health.php bin/release-preflight.php bin/release-backup.php bin/release-backup-verify.php bin/release-restore-plan.php; do php -l "$tmp/site/$file" >/dev/null; done
echo "Phase 49 release package smoke test passed."
