#!/usr/bin/env bash
set -euo pipefail

version="${1:-}"
output_directory="${2:-dist}"

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: scripts/build-package.sh <semantic-version> [output-directory]" >&2
  exit 2
fi

mkdir -p "${output_directory}"
archive="${output_directory}/uk-cookie-consent-manager-${version}.zip"

git archive --format=zip --prefix=uk-cookie-consent-manager/ --output="${archive}" HEAD
(
  cd "${output_directory}"
  sha256sum "$(basename "${archive}")" > SHA256SUMS
)

echo "${archive}"
