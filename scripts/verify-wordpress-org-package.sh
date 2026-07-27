#!/usr/bin/env bash
set -euo pipefail

package_directory="${1:-}"
assets_directory="${2:-wordpress-org/assets}"

if [[ -z "${package_directory}" || ! -d "${package_directory}" ]]; then
  echo "Usage: scripts/verify-wordpress-org-package.sh <package-directory> [directory-assets]" >&2
  exit 2
fi

plugin_file="${package_directory}/uk-cookie-consent-manager.php"
readme="${package_directory}/readme.txt"
updater="${package_directory}/includes/class-secure-updater.php"

for required in "${plugin_file}" "${readme}" "${updater}"; do
  if [[ ! -f "${required}" ]]; then
    echo "Required WordPress.org distribution file is missing: ${required}" >&2
    exit 1
  fi
done

header_version="$(sed -n 's/^ \* Version:[[:space:]]*//p' "${plugin_file}")"
constant_version="$(sed -n "s/^define( 'UCCM_VERSION', '\([^']*\)' );$/\1/p" "${plugin_file}")"
stable_tag="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${readme}")"

if [[ -z "${header_version}" || "${header_version}" != "${constant_version}" || "${header_version}" != "${stable_tag}" ]]; then
  echo "WordPress.org plugin header, runtime constant and Stable tag do not agree." >&2
  exit 1
fi

if grep -q '^ \* Update URI:' "${plugin_file}"; then
  echo "The WordPress.org entry point declares an external Update URI." >&2
  exit 1
fi

if grep -R -n -E 'update_plugins_github\.com|releases/latest/download/update-manifest|UCCM_MANIFEST_PRIVATE_KEY|create-manifest\.php' "${package_directory}"; then
  echo "External executable-update code was found in the WordPress.org package." >&2
  exit 1
fi

if find "${package_directory}" -type f \( \
  -name 'composer.json' -o -name 'composer.lock' -o -name 'package.json' -o \
  -name 'package-lock.json' -o -name 'pnpm-lock.yaml' -o -name 'phpunit.xml*' -o \
  -name 'phpstan.neon*' -o -name 'phpcs.xml*' -o -name 'playwright.config.js' \
\) -print -quit | grep -q .; then
  echo "Development metadata was found in the WordPress.org package." >&2
  exit 1
fi

for excluded in tests scripts vendor .github wordpress-org; do
  if [[ -e "${package_directory}/${excluded}" ]]; then
    echo "Development-only directory was found in the WordPress.org package: ${excluded}" >&2
    exit 1
  fi
done

python3 - "${assets_directory}" <<'PY'
import pathlib
import struct
import sys

expected = {
    "icon-128x128.png": (128, 128),
    "icon-256x256.png": (256, 256),
    "banner-772x250.png": (772, 250),
    "banner-1544x500.png": (1544, 500),
}
root = pathlib.Path(sys.argv[1])

for name, dimensions in expected.items():
    path = root / name
    if not path.is_file():
        raise SystemExit(f"Required WordPress.org asset is missing: {path}")
    data = path.read_bytes()
    if data[:8] != b"\x89PNG\r\n\x1a\n":
        raise SystemExit(f"WordPress.org asset is not PNG: {path}")
    actual = struct.unpack(">II", data[16:24])
    if actual != dimensions:
        raise SystemExit(f"{name} is {actual[0]}x{actual[1]}, expected {dimensions[0]}x{dimensions[1]}")
PY

echo "WordPress.org package checks passed for ${header_version}."
