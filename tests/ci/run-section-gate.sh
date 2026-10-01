#!/usr/bin/env bash
set -euo pipefail

BASE_SHA="${SECTION_GATE_BASE_SHA:-}"
if [[ -z "$BASE_SHA" ]]; then
  echo "SECTION_GATE_BASE_SHA is required." >&2
  exit 2
fi

mapfile -t DB_TESTS < <(git diff --name-only "$BASE_SHA"...HEAD \
  | grep -E '^tests/.*-db\.php$' \
  | grep -v '^tests/ci/' \
  | sort -u || true)

mapfile -t UPGRADE_TESTS < <(git diff --name-only "$BASE_SHA"...HEAD \
  | grep -E '^tests/ci/.*upgrade.*\.php$' \
  | sort -u || true)

if (( ${#DB_TESTS[@]} == 0 && ${#UPGRADE_TESTS[@]} == 0 )); then
  echo "Section gate: no changed DB journeys or upgrade rehearsals."
  exit 0
fi

reset_db() {
  local name="$1"
  TARGET_DB="$name" php -r '
    $dsn=(string)getenv("DB_DSN");
    $user=(string)getenv("DB_USER");
    $pass=(string)getenv("DB_PASS");
    $name=(string)getenv("TARGET_DB");
    $server=(string)preg_replace("/;?dbname=[^;]+/i","",$dsn);
    $pdo=new PDO($server,$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    $q=chr(96).str_replace(chr(96),chr(96).chr(96),$name).chr(96);
    $pdo->exec("DROP DATABASE IF EXISTS ".$q);
    $pdo->exec("CREATE DATABASE ".$q." CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  '
}

for test in "${DB_TESTS[@]}"; do
  echo "::group::Targeted DB journey: $test"
  reset_db annotated_section_gate
  export DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=annotated_section_gate;charset=utf8mb4"
  php tests/ci/prepare-current-schema.php
  php "$test"
  echo "::endgroup::"
done

for test in "${UPGRADE_TESTS[@]}"; do
  echo "::group::Targeted upgrade rehearsal: $test"
  export DB_DSN="mysql:host=127.0.0.1;port=3306;dbname=annotated_section_upgrade;charset=utf8mb4"

  mapfile -t RESET_VARS < <(grep -oE "getenv\(\x27[A-Z0-9_]*RESET[A-Z0-9_]*\x27\)" "$test" \
    | sed -E "s/^getenv\(\x27//;s/\x27\)$//" \
    | sort -u || true)

  if (( ${#RESET_VARS[@]} )); then
    env_args=()
    for reset_var in "${RESET_VARS[@]}"; do env_args+=("$reset_var=1"); done
    env "${env_args[@]}" php "$test"
  else
    php "$test"
  fi
  echo "::endgroup::"
done

echo "Section gate passed: ${#DB_TESTS[@]} DB journey(s), ${#UPGRADE_TESTS[@]} upgrade rehearsal(s)."
