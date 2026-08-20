#!/usr/bin/env bash
#
# Build the distributable WPPilot ZIPs.
#
# Two variants are produced, because the two distribution channels disagree
# about who owns updates:
#
#   wppilot-<version>.zip        self-hosted / GitHub Releases.
#                                Keeps includes/updater.php and the plugin
#                                header's `Update URI`, so WordPress asks
#                                wppilot.co for updates and does not consult
#                                the .org API.
#
#   wppilot-<version>-org.zip    WordPress.org submission.
#                                Strips the updater and the `Update URI` line,
#                                because .org rejects plugins that override the
#                                update mechanism — and a plugin that keeps the
#                                header would never receive .org updates at all.
#
# Both archives use `wppilot/` as the root folder. That matters: WordPress names
# the installed directory after the archive root, and Pro's `Requires Plugins:
# wppilot` header resolves against it. GitHub's auto-generated "Source code
# (zip)" uses `wppilot-<tag>/` and is therefore NOT installable.
#
# Usage: ./scripts/package.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$(pwd)"
VERSION="$(grep -m1 '^ \* Version:' wppilot.php | sed 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')"

if [ -z "$VERSION" ]; then
  echo "Could not read Version from wppilot.php header" >&2
  exit 1
fi

echo "Packaging WPPilot $VERSION"

# zip(1) is absent on a stock Windows Git Bash. bsdtar (shipped as tar.exe in
# System32 on Windows 10+, and as `tar` on macOS) writes a spec-compliant zip;
# GNU tar cannot write zip at all, and PowerShell's Compress-Archive emits
# BACKSLASH path separators, which produces an archive that will not extract
# correctly on the Linux host the plugin is installed on. Neither is acceptable.
make_zip() {
  local out="$1" src_dir="$2" folder="$3"
  rm -f "$out"

  if command -v zip >/dev/null 2>&1; then
    ( cd "$src_dir" && zip -qr "$out" "$folder" )
    return
  fi

  local bsdtar=""
  for candidate in /c/Windows/System32/tar.exe /usr/bin/bsdtar bsdtar; do
    if "$candidate" --version 2>/dev/null | grep -qi bsdtar; then bsdtar="$candidate"; break; fi
  done
  if [ -n "$bsdtar" ]; then
    ( cd "$src_dir" && "$bsdtar" -a -cf "$out" "$folder" )
    return
  fi

  echo "Need zip(1) or bsdtar to build a spec-compliant archive." >&2
  echo "Do release builds on Linux/macOS or in CI." >&2
  exit 1
}

# Parse every PHP file in a staged tree. The .org variant is produced by editing
# wppilot.php in place, so a bad edit ships a plugin that fatals on activation
# and there is no later gate that would catch it — the archive is opaque to
# `php -l` and to CI once it is a zip.
verify_tree() {
  local dir="$1" label="$2" failed=0
  while IFS= read -r file; do
    php -l "$file" >/dev/null 2>&1 || { echo "Syntax error in $label: ${file#"$dir/"}" >&2; php -l "$file" >&2; failed=1; }
  done < <(find "$dir" -name '*.php' -not -path '*/vendor/*')
  if [ "$failed" -ne 0 ]; then
    echo "Refusing to package $label with syntax errors." >&2
    exit 1
  fi
  echo "  $label: PHP syntax OK"
}

BUILD="$ROOT/build"
rm -rf "$BUILD"
mkdir -p "$BUILD/wppilot"

# Everything the plugin needs at runtime. vendor/ is included deliberately;
# development tooling and sources for built assets are not.
#
# Copy-then-prune rather than rsync, so this runs on a plain Git Bash / macOS /
# Linux shell without extra tooling.
for entry in * .[!.]*; do
  case "$entry" in
    build|dist|node_modules|scripts|src|tests|.git|.github|.gitignore) continue ;;
    package.json|package-lock.json|bun.lockb|tsconfig.json) continue ;;
    composer.json|composer.lock|.phpunit.result.cache|.DS_Store) continue ;;
    phpunit.xml|phpunit.xml.dist|.phpunit.cache|phpcs.xml|phpcs.xml.dist|mago.toml) continue ;;
    .gitattributes|.editorconfig) continue ;;
    *.zip) continue ;;
    '*'|'.[!.]*') continue ;;
  esac
  cp -R "$entry" "$BUILD/wppilot/"
done

# --- Variant 1: self-hosted / GitHub -----------------------------------------
verify_tree "$BUILD/wppilot" "self-hosted build"
make_zip "$ROOT/build/wppilot-$VERSION.zip" "$BUILD" wppilot
echo "  build/wppilot-$VERSION.zip"

# --- Variant 2: WordPress.org ------------------------------------------------
ORG="$BUILD/org"
mkdir -p "$ORG"
cp -r "$BUILD/wppilot" "$ORG/wppilot"

# Remove the self-hosted updater and drop the Update URI header.
#
# Only the header line is edited. The require in wppilot.php is already wrapped
# in `if (file_exists(...))`, precisely so deleting the file is safe — and a
# line-delete on /includes\/updater\.php/ would take that `if` line out too,
# orphaning its closing brace and shipping a plugin that fatals on load. That is
# not hypothetical: it is what this script did until 1.1.0.
rm -f "$ORG/wppilot/includes/updater.php"
sed -i.bak "/^ \* Update URI:/d" "$ORG/wppilot/wppilot.php"
rm -f "$ORG/wppilot/wppilot.php.bak"

if grep -q "Update URI:" "$ORG/wppilot/wppilot.php"; then
  echo "Update URI still present in the .org build" >&2
  exit 1
fi
if [ -f "$ORG/wppilot/includes/updater.php" ]; then
  echo "updater.php still present in the .org build" >&2
  exit 1
fi

# Remove anonymous usage reporting.
#
# The directory requires reporting to be opt-in. Deleting the code is a stronger
# guarantee than shipping it switched off: the .org build cannot report even if
# a future default changes or an option is set by hand. wppilot.php wraps the
# require in file_exists() so the removal is safe, the same as the updater.
rm -rf "$ORG/wppilot/includes/telemetry"

if [ -d "$ORG/wppilot/includes/telemetry" ]; then
  echo "includes/telemetry still present in the .org build" >&2
  exit 1
fi

# Belt and braces: grep the whole tree for the endpoint. This catches a future
# change that references it from a file outside includes/telemetry, which the
# directory delete above would not remove.
if grep -rq "api/wppilot/telemetry" "$ORG/wppilot"; then
  echo "telemetry endpoint still referenced in the .org build" >&2
  grep -rn "api/wppilot/telemetry" "$ORG/wppilot" >&2
  exit 1
fi

verify_tree "$ORG/wppilot" ".org build"
make_zip "$ROOT/build/wppilot-$VERSION-org.zip" "$ORG" wppilot
echo "  build/wppilot-$VERSION-org.zip"

rm -rf "$BUILD/wppilot" "$ORG"
echo "Done."
