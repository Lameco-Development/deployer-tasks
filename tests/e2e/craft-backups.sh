#!/usr/bin/env bash
# End-to-end check of lameco:craft_backups with a real `dep` against localhost: each "server" is a
# directory under a temp dir, so no SSH is needed.
# Usage: bash tests/e2e/craft-backups.sh (after composer install)
set -uo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
dep="$root/vendor/bin/dep"
tmp="$(mktemp -d)"
failed=0

check() { # description pass|stop expected-substring command...
  local description="$1" outcome="$2" expected="$3" output status
  shift 3
  output="$("$@" 2>&1)"
  status=$?
  if { [ "$outcome" = pass ] && [ "$status" -eq 0 ]; } || { [ "$outcome" = stop ] && [ "$status" -ne 0 ]; }; then
    if printf '%s' "$output" | grep -qF -- "$expected"; then
      echo "ok: $description"
      return
    fi
  fi
  echo "FAIL: $description (expected $outcome with [$expected], got exit $status)"
  printf '%s\n' "$output" | tail -n 5
  failed=1
}

assert() { # description test-arguments...
  local description="$1"
  shift
  if test "$@"; then
    echo "ok: $description"
  else
    echo "FAIL: $description"
    failed=1
  fi
}

backup() { # host name timestamp(YYYYMMDDhhmm) -> a dump Craft could have written at that time
  printf 'CREATE TABLE %s (id int);\n' "$2" > "$tmp/$1/shared/storage/backups/$2"
  touch -t "$3" "$tmp/$1/shared/storage/backups/$2"
}

mkdir -p "$tmp/app"
cd "$tmp/app" || exit 1
cat > deploy.php << PHP
<?php
namespace Deployer;
require 'recipe/common.php';
require '$root/src/tasks.php';
foreach (['production', 'staging', 'empty', 'full'] as \$name) {
    localhost(\$name)->set('deploy_path', '$tmp/' . \$name);
}
PHP
touch craft # deployer-tasks detects the project type from this file

for host in production staging; do
  mkdir -p "$tmp/$host/shared/storage/backups"
  # A zip from a Craft update in the control panel, then three deploys that ran migrations.
  backup "$host" site--2025-02-07-095304--v4.12.0.sql 202502070953
  (cd "$tmp/$host/shared/storage/backups" && zip -q site--2025-02-07-095304--v4.12.0.sql.zip site--2025-02-07-095304--v4.12.0.sql \
    && touch -t 202502070953 site--2025-02-07-095304--v4.12.0.sql.zip && rm site--2025-02-07-095304--v4.12.0.sql)
  backup "$host" site--2026-09-14-131204--v5.10.1.sql 202609141312
  backup "$host" site--2026-11-20-093113--v5.11.1.sql 202611200931
  backup "$host" site--2026-11-23-095007--v5.12.1.sql 202611230950
done

dir="$tmp/production/shared/storage/backups"
check "production keeps two backups" pass "lameco:craft_backups" "$dep" lameco:craft_backups production
assert "the newest backup is zipped" -f "$dir/site--2026-11-23-095007--v5.12.1.sql.zip"
assert "the one before it is zipped" -f "$dir/site--2026-11-20-093113--v5.11.1.sql.zip"
assert "no uncompressed dump is left" -z "$(find "$dir" -name '*.sql')"
assert "older backups are removed" "$(find "$dir" -type f | wc -l | tr -d ' ')" -eq 2
assert "the zip holds the dump" "$(unzip -p "$dir/site--2026-11-23-095007--v5.12.1.sql.zip")" = "CREATE TABLE site--2026-11-23-095007--v5.12.1.sql (id int);"
# Craft prunes by modification time too, so a zip must keep the time of the dump it replaced.
assert "the zip keeps the dump's time" "$dir/site--2026-11-23-095007--v5.12.1.sql.zip" -nt "$dir/site--2026-11-20-093113--v5.11.1.sql.zip"

# The next migrating deploy writes a new dump; the oldest of the two zips goes.
backup production site--2026-11-25-101500--v5.12.2.sql 202611251015
check "a second run passes" pass "lameco:craft_backups" "$dep" lameco:craft_backups production
assert "the new dump is zipped" -f "$dir/site--2026-11-25-101500--v5.12.2.sql.zip"
assert "the previous newest stays" -f "$dir/site--2026-11-23-095007--v5.12.1.sql.zip"
assert "the oldest zip goes" ! -e "$dir/site--2026-11-20-093113--v5.11.1.sql.zip"

dir="$tmp/staging/shared/storage/backups"
check "staging keeps one backup" pass "lameco:craft_backups" "$dep" lameco:craft_backups staging
assert "only the newest is left, zipped" "$(ls "$dir")" = "site--2026-11-23-095007--v5.12.1.sql.zip"

check "a project without backups passes" pass "lameco:craft_backups" "$dep" lameco:craft_backups empty

# A zip that fails, as on a full disk, must keep the dump and must not fail a deploy that is already live.
mkdir -p "$tmp/bin" "$tmp/full/shared/storage/backups"
printf '#!/bin/sh\necho "zip I/O error: No space left on device" >&2\n: > "$3"\nexit 14\n' > "$tmp/bin/zip"
chmod +x "$tmp/bin/zip"
backup full site--2026-11-23-095007--v5.12.1.sql 202611230950
check "a failed zip warns instead of failing" pass "could not be zipped" env PATH="$tmp/bin:$PATH" "$dep" lameco:craft_backups full
assert "the dump is kept" -f "$tmp/full/shared/storage/backups/site--2026-11-23-095007--v5.12.1.sql"
assert "the partial zip is removed" ! -e "$tmp/full/shared/storage/backups/site--2026-11-23-095007--v5.12.1.sql.zip"

rm -rf "$tmp"
exit "$failed"
