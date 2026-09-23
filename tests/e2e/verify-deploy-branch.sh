#!/usr/bin/env bash
# End-to-end check of lameco:verify_deploy_branch with a real `dep` against local
# git repos: no SSH, the task only runs local git commands.
# Usage: bash tests/e2e/verify-deploy-branch.sh (after composer install)
set -uo pipefail

root="$(cd "$(dirname "$0")/../.." && pwd)"
dep="$root/vendor/bin/dep"
tmp="$(mktemp -d)"
failed=0

# The laptop cases must not look like CI, also when this script runs in GitHub Actions.
laptop_dep() { env -u GITHUB_ACTIONS "$dep" "$@"; }
ci_dep() { env GITHUB_ACTIONS=true "$dep" "$@"; }

git() { command git -c init.defaultBranch=main -c user.email=test@example.nl -c user.name=test "$@"; }

check() { # description pass|stop expected-substring command...
  local description="$1" outcome="$2" expected="$3" output status
  shift 3
  output="$("$@" 2>&1)"
  status=$?
  if { [ "$outcome" = pass ] && [ "$status" -eq 0 ]; } || { [ "$outcome" = stop ] && [ "$status" -ne 0 ]; }; then
    if printf '%s' "$output" | grep -qF "$expected"; then
      echo "ok: $description"
      return
    fi
  fi
  echo "FAIL: $description (expected $outcome with [$expected], got exit $status)"
  printf '%s\n' "$output" | tail -n 5
  failed=1
}

git init -q --bare "$tmp/origin.git"
git clone -q "$tmp/origin.git" "$tmp/app" 2> /dev/null
cd "$tmp/app" || exit 1
git checkout -q -b development
cat > deploy.php << PHP
<?php
namespace Deployer;
require 'recipe/common.php';
require '$root/src/tasks.php';
host('staging')->setHostname('staging.invalid')->set('branch', 'development');
PHP
touch craft # deployer-tasks detects the project type from this file
git add deploy.php craft
git commit -q -m one
git push -q origin development

check "clean checkout at the origin tip passes" pass "task lameco:verify_deploy_branch" laptop_dep lameco:verify_deploy_branch staging

echo change > notes.txt
check "uncommitted changes stop the deploy" stop "uncommitted changes" laptop_dep lameco:verify_deploy_branch staging
rm notes.txt

git clone -q -b development "$tmp/origin.git" "$tmp/other" 2> /dev/null
(cd "$tmp/other" && git commit -q --allow-empty -m two && git push -q origin development)
check "a local branch behind origin stops the deploy" stop "differs from origin/development" laptop_dep lameco:verify_deploy_branch staging

check "in CI the check is skipped" pass "task lameco:verify_deploy_branch" ci_dep lameco:verify_deploy_branch staging

exit "$failed"
