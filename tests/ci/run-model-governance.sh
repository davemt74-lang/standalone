#!/usr/bin/env bash
set -euo pipefail

: "${DB_DSN:?DB_DSN is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASS:=}"

php tests/install-db.php

for phase in $(seq 37 99); do
  matched=0
  for test_file in tests/phase"${phase}"-*-db.php; do
    [[ -e "$test_file" ]] || continue
    matched=1
    php "$test_file"
  done
  if [[ "$matched" -eq 1 ]]; then
    echo "Completed targeted model-governance phase ${phase}."
  fi
done
