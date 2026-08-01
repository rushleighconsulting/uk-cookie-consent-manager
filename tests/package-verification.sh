#!/usr/bin/env bash
set -euo pipefail

clean_archive="${1:-}"

if [[ -z "${clean_archive}" || ! -f "${clean_archive}" ]]; then
  echo "Usage: tests/package-verification.sh <clean-plugin-archive>" >&2
  exit 2
fi

bash scripts/verify-package.sh "${clean_archive}"

fixture_directory="$(mktemp -d)"
trap 'rm -rf "${fixture_directory}"' EXIT
mkdir -p "${fixture_directory}/rushleigh-cookie-choices/docs"
printf '%s\n' 'Configure UCCM_MANIFEST_PRIVATE_KEY in GitHub Actions.' > "${fixture_directory}/rushleigh-cookie-choices/docs/OPERATING-GUIDE.md"
(
  cd "${fixture_directory}"
  zip -qr documented-secret-name.zip rushleigh-cookie-choices
)
bash scripts/verify-package.sh "${fixture_directory}/documented-secret-name.zip"

fake_signing_key='AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='
printf '%s\n' "${fake_signing_key}" > "${fixture_directory}/rushleigh-cookie-choices/leaked-secret.txt"
(
  cd "${fixture_directory}"
  zip -qr leaked-secret.zip rushleigh-cookie-choices
)

if UCCM_MANIFEST_PRIVATE_KEY="${fake_signing_key}" bash scripts/verify-package.sh "${fixture_directory}/leaked-secret.zip"; then
  echo "Package verification accepted the configured signing key." >&2
  exit 1
fi

printf '%s\n' 'ghp_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA' > "${fixture_directory}/rushleigh-cookie-choices/leaked-secret.txt"
(
  cd "${fixture_directory}"
  zip -qr leaked-token.zip rushleigh-cookie-choices
)

if bash scripts/verify-package.sh "${fixture_directory}/leaked-token.zip"; then
  echo "Package verification accepted a GitHub token pattern." >&2
  exit 1
fi
