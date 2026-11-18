#!/usr/bin/env bash
# End-to-end check of lameco:prepare_current and lameco:remove_stub_release with a real `dep`
# against localhost: each "server" is a directory under a temp dir, so no SSH is needed.
# Usage: bash tests/e2e/prepare-current.sh (after composer install)
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

mkdir -p "$tmp/app"
cd "$tmp/app" || exit 1
cat > deploy.php << PHP
<?php
namespace Deployer;
require 'recipe/common.php';
require '$root/src/tasks.php';
foreach (['placeholder', 'docroot', 'content'] as \$name) {
    localhost(\$name)->set('deploy_path', '$tmp/' . \$name)->set('branch', 'development');
}
// Nothing to build here; the hooks around deploy:symlink stay in place.
task('lameco:build_assets', function () {});
task('lameco:upload_assets', function () {});
// The deploy steps that touch current_path, without uploading code.
task('e2e:publish', ['deploy:setup', 'deploy:release', 'deploy:symlink']);
PHP
touch craft # deployer-tasks detects the project type from this file

mkdir -p "$tmp/placeholder/current/web" "$tmp/docroot/current/web" "$tmp/content/current/web"
printf '%s\n' '<!doctype html><title>Staging niet actief</title>' > "$tmp/placeholder/current/web/index.html"
printf '%s\n' '<?php' > "$tmp/content/current/web/index.php"

# After lameco:deactivate: the placeholder moves into a stub release and stays visible.
check "the placeholder moves into a stub release" pass "placeholder at" "$dep" lameco:prepare_current placeholder
assert "current is a symlink to the stub" -L "$tmp/placeholder/current" -a "$tmp/placeholder/current" -ef "$tmp/placeholder/releases/stub"
assert "the placeholder page is still served" -f "$tmp/placeholder/current/web/index.html"
check "a second run leaves the symlink alone" pass "lameco:prepare_current" "$dep" lameco:prepare_current placeholder
check "the first release goes live" pass "deploy:symlink" "$dep" e2e:publish placeholder
assert "current points at release 1" "$tmp/placeholder/current" -ef "$tmp/placeholder/releases/1"
assert "the stub release is gone" ! -e "$tmp/placeholder/releases/stub"

# A docroot the hosting panel recreated: deploy:setup no longer stops on it.
check "deploy:setup accepts an empty docroot" pass "empty docroot at" "$dep" deploy:setup docroot
assert "current is a symlink to the stub" -L "$tmp/docroot/current" -a "$tmp/docroot/current" -ef "$tmp/docroot/releases/stub"
assert "the docroot still exists" -d "$tmp/docroot/current/web"

# Real content is left alone, and deploy:setup still stops on it.
check "deploy:setup still stops on real content" stop "There is a directory (not symlink)" "$dep" deploy:setup content
assert "the content is untouched" -f "$tmp/content/current/web/index.php" -a ! -L "$tmp/content/current"

rm -rf "$tmp"
exit "$failed"
