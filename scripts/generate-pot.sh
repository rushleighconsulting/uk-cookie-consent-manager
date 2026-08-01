#!/usr/bin/env bash
set -euo pipefail

output="${1:-languages/rushleigh-cookie-choices.pot}"
domain="rushleigh-cookie-choices"

if ! command -v wp >/dev/null 2>&1; then
  echo "WP-CLI with the i18n command is required." >&2
  exit 1
fi

mkdir -p "$(dirname "${output}")"
LC_ALL=C wp i18n make-pot . "${output}" \
  --domain="${domain}" \
  --exclude=".github,dist,docs,node_modules,tests,vendor,wordpress-org" \
  --headers='{"Report-Msgid-Bugs-To":"https://github.com/rushleighconsulting/uk-cookie-consent-manager/issues","POT-Creation-Date":"1970-01-01 00:00+0000"}'

