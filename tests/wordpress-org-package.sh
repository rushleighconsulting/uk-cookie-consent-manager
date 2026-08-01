#!/usr/bin/env bash
set -euo pipefail

version="$(sed -n 's/^ \* Version:[[:space:]]*//p' rushleigh-cookie-choices.php)"
temporary="$(mktemp -d)"
trap 'rm -rf "${temporary}"' EXIT

bash scripts/build-wordpress-org-package.sh "${version}" "${temporary}/build"
bash scripts/verify-wordpress-org-package.sh "${temporary}/build/rushleigh-cookie-choices"

if grep -R -q 'GitHub releases' "${temporary}/build/rushleigh-cookie-choices"; then
  echo "The WordPress.org package retained GitHub update-channel copy." >&2
  exit 1
fi

if ! grep -q 'WordPress.org Plugin Directory' "${temporary}/build/rushleigh-cookie-choices/includes/class-secure-updater.php"; then
  echo "The WordPress.org package did not install the native update-status implementation." >&2
  exit 1
fi
