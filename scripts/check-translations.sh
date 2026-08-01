#!/usr/bin/env bash
set -euo pipefail

expected="languages/rushleigh-cookie-choices.pot"
generated="$(mktemp)"
trap 'rm -f "${generated}"' EXIT

bash scripts/generate-pot.sh "${generated}"
cmp --silent "${expected}" "${generated}" || {
  echo "The committed POT file is stale. Run: bash scripts/generate-pot.sh" >&2
  diff -u "${expected}" "${generated}" || true
  exit 1
}

node scripts/check-js-i18n.js
grep -Fq 'msgid "Your cookie choices"' "${expected}"
grep -Fq 'msgid "Checking page %1$d of %2$d (%3$s)…"' "${expected}"
grep -Fq 'msgid_plural "%d reviewed items"' "${expected}"
