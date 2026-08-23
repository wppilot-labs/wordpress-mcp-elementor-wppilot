#!/usr/bin/env bash
#
# Build the distributable WPPilot ZIP.
#
# One archive, one name: `wppilot.zip`, attached to every GitHub release.
#
# The file name deliberately carries no version. GitHub only resolves
# /releases/latest/download/<asset> for an asset whose name never changes, and
# that URL — behind wppilot.co/download/latest — is what the website, the
# purchase emails and WPPilot Pro's dependency notice all point at. A pinned
# download is still available per release, because every release keeps its own
# copy at /releases/download/<tag>/wppilot.zip, which is what the update
# endpoint hands to WordPress.
#
# There is no WordPress.org variant. WPPilot is not listed in the directory and
# is not going to be, so the second archive this script used to build — updater
# stripped, telemetry deleted, `Update URI` removed — existed only to satisfy
# rules that do not apply. It was also a hazard on a public release page: two
# near-identical file names, one of which silently never receives an update
# again. See docs/, and the free-vs-directory note in readme.txt.
#
# The archive uses `wppilot/` as the root folder. WordPress names the installed
# directory after the archive root, and while Pro now finds the free plugin by
# its `wppilot.php` file name rather than its directory, a predictable directory
# is what every support answer and path in the docs assumes. GitHub's
# auto-generated "Source code (zip)" uses `wppilot-<tag>/` and is therefore NOT
# installable.
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

# Parse every PHP file in the staged tree before it is sealed. Once the archive
# is a zip it is opaque to `php -l` and to CI, so a file that does not parse
# ships a plugin that fatals on activation with no later gate to catch it.
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

verify_tree "$BUILD/wppilot" "release build"
make_zip "$ROOT/build/wppilot.zip" "$BUILD" wppilot
echo "  build/wppilot.zip"

rm -rf "$BUILD/wppilot"
echo "Done. Attach this file to the GitHub release for $VERSION."
