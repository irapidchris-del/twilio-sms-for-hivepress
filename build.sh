#!/usr/bin/env bash
#
# Builds the distributable plugin zip(s).
#
# The zip always unpacks to a top-level "twilio-for-hivepress/" folder (no version
# in the folder or main file name), so WordPress installs it to
# wp-content/plugins/twilio-for-hivepress and never shows a folder-mismatch warning.
#
# Usage:
#   ./build.sh                 # build from HEAD, version read from the plugin header
#   ./build.sh 1.2.0           # override the version label on the internal copy
#   ./build.sh 1.2.0 v1.2.0    # build from a specific git ref (tag/branch/commit)
#
# Outputs (in dist/):
#   twilio-for-hivepress.zip            <- upload THIS as the GitHub release asset
#   twilio-for-hivepress-<version>.zip  <- versioned copy for your own tracking
#
# Requires the version bump to be committed first (git archive reads committed content).

set -euo pipefail

SLUG="twilio-for-hivepress"
OUT_DIR="dist"

cd "$(dirname "$0")"

HEADER_VERSION="$(grep -m1 -oiE 'Version:[[:space:]]*[0-9.]+' "${SLUG}.php" | grep -oE '[0-9.]+' || true)"
VERSION="${1:-$HEADER_VERSION}"
REF="${2:-HEAD}"

if [ -z "$VERSION" ]; then
	echo "Could not determine version. Pass it explicitly: ./build.sh 1.2.0" >&2
	exit 1
fi

mkdir -p "$OUT_DIR"

# Release asset: stable name so the "latest release" download link and the updater's
# asset matcher keep working across every release.
git archive --format=zip --prefix="${SLUG}/" -o "${OUT_DIR}/${SLUG}.zip" "$REF"

# Versioned copy (identical contents) for internal tracking.
cp "${OUT_DIR}/${SLUG}.zip" "${OUT_DIR}/${SLUG}-${VERSION}.zip"

echo "Built:"
echo "  ${OUT_DIR}/${SLUG}.zip            (attach as the release asset)"
echo "  ${OUT_DIR}/${SLUG}-${VERSION}.zip (internal copy)"
echo
echo "Top-level folder inside the zip:"
unzip -l "${OUT_DIR}/${SLUG}.zip" | awk 'NR>3 {print $4}' | grep -m1 '/' || true
