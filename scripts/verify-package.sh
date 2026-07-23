#!/usr/bin/env bash
set -euo pipefail

archive="${1:-}"

if [[ -z "${archive}" || ! -f "${archive}" ]]; then
  echo "Usage: scripts/verify-package.sh <plugin-archive>" >&2
  exit 2
fi

unzip -t "${archive}"
unzip -l "${archive}" > "${archive}.contents.txt"

if grep -E '/(tests|vendor|\.github|scripts)/|composer\.(json|lock)|package(-lock)?\.json|playwright\.config|php(unit|stan)|phpcs' "${archive}.contents.txt"; then
  echo "Development-only content was found in the plugin archive." >&2
  exit 1
fi

if unzip -p "${archive}" | grep -aE 'gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}|-----BEGIN ([A-Z0-9 ]+ )?PRIVATE KEY-----'; then
  echo "Credential material was found in the plugin archive." >&2
  exit 1
fi

if [[ -n "${UCCM_MANIFEST_PRIVATE_KEY:-}" ]] && unzip -p "${archive}" | grep -aF -- "${UCCM_MANIFEST_PRIVATE_KEY}"; then
  echo "The configured manifest signing key was found in the plugin archive." >&2
  exit 1
fi
