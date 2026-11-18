#!/usr/bin/env bash
# Checks where the deployer-tasks hooks land in `dep tree deploy`, with a real `dep`.
# Usage: bash tests/e2e/task-order.sh (after composer install)
set -uo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
dep="$root/vendor/bin/dep"
tmp="$(mktemp -d)"
failed=0

cd "$tmp" || exit 1
cat > deploy.php << PHP
<?php
namespace Deployer;
require 'recipe/common.php';
require '$root/src/tasks.php';
localhost('staging');
PHP
touch craft # deployer-tasks detects the project type from this file

tree="$("$dep" tree deploy 2>&1)"

line_of() { # task name -> line number of its first entry in the tree
  printf '%s\n' "$tree" | grep -n -E -- "── $1( |$)" | head -n 1 | cut -d: -f1
}

before() { # description earlier-task later-task
  local first second
  first="$(line_of "$2")"
  second="$(line_of "$3")"
  if [ -n "$first" ] && [ -n "$second" ] && [ "$first" -lt "$second" ]; then
    echo "ok: $1"
  else
    echo "FAIL: $1 ($2 at line ${first:-none}, $3 at line ${second:-none})"
    failed=1
  fi
}

before "php-fpm restarts after the switch" deploy:symlink lameco:restart_php
before "php-fpm restarts before the previous release is removed" lameco:restart_php deploy:cleanup
before "supervisor restarts after the switch" deploy:symlink lameco:restart_supervisor
before "supervisor restarts before the previous release is removed" lameco:restart_supervisor deploy:cleanup
before "current_path is prepared before deploy:setup" lameco:prepare_current deploy:setup

if [ "$failed" -ne 0 ]; then
  printf '%s\n' "$tree"
fi

rm -rf "$tmp"
exit "$failed"
