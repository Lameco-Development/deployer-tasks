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
    if printf '%s' "$output" | grep -qF -- "$expected"; then
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
foreach (['a', 'b', 'c'] as \$name) {
    host("production-\$name")->setHostname("\$name.invalid")->setLabels(['stage' => 'production'])->set('branch', 'development');
}
task('e2e:failed_hook', function () {
    runLocally('touch failed-hook-ran');
});
after('deploy:failed', 'e2e:failed_hook');
PHP
printf 'failed-hook-ran\n' > .gitignore
touch craft # deployer-tasks detects the project type from this file
git add deploy.php craft .gitignore
git commit -q -m one
git push -q origin development

check "clean checkout at the origin tip passes" pass "task lameco:verify_deploy_branch" laptop_dep lameco:verify_deploy_branch staging

check "--branch to another branch stops the deploy" stop "-o branch=feature/x" laptop_dep lameco:verify_deploy_branch staging --branch=feature/x

echo change > notes.txt
check "uncommitted changes stop the deploy" stop "uncommitted changes" laptop_dep lameco:verify_deploy_branch staging
rm notes.txt

git clone -q -b development "$tmp/origin.git" "$tmp/other" 2> /dev/null
(cd "$tmp/other" && git commit -q --allow-empty -m two && git push -q origin development)
check "a local branch behind origin stops the deploy" stop "differs from origin/development" laptop_dep lameco:verify_deploy_branch staging

# Several hosts must not fetch into the same checkout at once ("cannot lock ref"). The
# race only shows when the fetch has to move the tracking ref, so origin moves every run.
raced=0
for run in 1 2 3 4 5; do
  (cd "$tmp/other" && git commit -q --allow-empty -m "race $run" && git push -q origin development)
  output="$(laptop_dep lameco:verify_deploy_branch stage=production 2>&1)"
  if printf '%s' "$output" | grep -q 'cannot lock ref'; then
    echo "FAIL: several hosts fetched at the same time (run $run)"
    printf '%s\n' "$output" | grep 'cannot lock ref' | head -n 2
    raced=1
    failed=1
    break
  fi
done
[ "$raced" -eq 1 ] || echo "ok: several hosts are checked one at a time"

check "in CI the check is skipped" pass "task lameco:verify_deploy_branch" ci_dep lameco:verify_deploy_branch staging

# A failed fetch stops gracefully: deploy:failed hooks (such as deploy:unlock on the servers) must not run.
git remote set-url origin "$tmp/missing.git"
check "an unreachable origin stops the deploy" stop "Could not fetch" laptop_dep deploy staging
if [ -e failed-hook-ran ]; then
  echo "FAIL: an unreachable origin ran the deploy:failed hooks"
  failed=1
else
  echo "ok: an unreachable origin skips the deploy:failed hooks"
fi

exit "$failed"
