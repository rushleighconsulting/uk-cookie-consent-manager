#!/usr/bin/env bash
set -euo pipefail

version="${1:-}"
output_directory="${2:-dist/wordpress-org}"
slug="uk-cookie-consent-manager"
target="${output_directory}/${slug}"

if [[ ! "${version}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  echo "Usage: scripts/build-wordpress-org-package.sh <semantic-version> [output-directory]" >&2
  exit 2
fi

if [[ -e "${target}" ]]; then
  echo "Refusing to replace existing WordPress.org package directory: ${target}" >&2
  exit 1
fi

source_version="$(git show "HEAD:uk-cookie-consent-manager.php" | sed -n 's/^ \* Version:[[:space:]]*//p')"
directory_version="$(git show "HEAD:wordpress-org/uk-cookie-consent-manager.php" | sed -n 's/^ \* Version:[[:space:]]*//p')"
stable_tag="$(git show "HEAD:wordpress-org/readme.txt" | sed -n 's/^Stable tag:[[:space:]]*//p')"

if [[ "${version}" != "${source_version}" || "${version}" != "${directory_version}" || "${version}" != "${stable_tag}" ]]; then
  echo "Git tag version, source version, WordPress.org entry version and Stable tag must agree." >&2
  exit 1
fi

temporary="$(mktemp -d)"
trap 'rm -rf "${temporary}"' EXIT

mkdir -p "${temporary}/${slug}" "${output_directory}"
git archive --format=tar HEAD assets includes uninstall.php | tar -xf - -C "${temporary}/${slug}"
git show "HEAD:wordpress-org/uk-cookie-consent-manager.php" > "${temporary}/${slug}/uk-cookie-consent-manager.php"
git show "HEAD:wordpress-org/class-secure-updater.php" > "${temporary}/${slug}/includes/class-secure-updater.php"
git show "HEAD:wordpress-org/readme.txt" > "${temporary}/${slug}/readme.txt"

mv "${temporary:?}/${slug}" "${target}"

(
  cd "${target}"
  find . -type f -print0 | sort -z | xargs -0 sha256sum
) > "${output_directory}/SOURCE-MANIFEST.sha256"

echo "${target}"
