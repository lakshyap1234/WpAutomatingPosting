#!/usr/bin/env bash
# Rebuilds the third-party code the two plugins ship with, and the plugin zips.
#
#   bin/build.sh                 deps for both plugins, then both zips into dist/
#   bin/build.sh deps [plugin]   regenerate what is committed: vendor-prefixed/ (both),
#                                lib/action-scheduler/ and build/ (content-publisher)
#   bin/build.sh check           deps, then fail if that changed anything committed
#   bin/build.sh zips [plugin]   deps, then the zips (plugin = publisher | connector)
#   bin/build.sh config          write content-publisher-connector/config/agency.php
#
# Needs: php 8.2+, composer 2, git, rsync, zip, curl or wget (to fetch Strauss once),
# and node 18+ for the review editor (skipped with a warning when node is missing:
# build/ is committed).
#
# The Connector trusts exactly one agency, written into config/agency.php:
#   AGENCY_URL=https://agency.example   the agency site (https)
#   AGENCY_NAME="Agency Name"           shown on the consent screen (default "Agency")
#   AGENCY_LOGO=logo.png                optional, a file in content-publisher-connector/assets/
#   DEMO=1                              also trust a test tool on the approving admin's own
#                                       computer (http://127.0.0.1/callback). Never for live sites.
# "config" writes the committed file. "zips" uses the committed file, unless AGENCY_URL
# is set: then that zip gets its own config (the committed file is left alone).
#   SUFFIX=-something                   appended to zip names
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIST="$ROOT/dist"
TOOLS="$ROOT/.build-tools"   # downloaded tools, gitignored

# ---------------------------------------------------------------- pinned tools
# Strauss prefixes the Composer packages into vendor-prefixed/ (namespaces get
# CPub\<Plugin>\Vendor\), so other plugins' copies of the same libraries can't clash.
# Its settings are in each plugin's composer.json under extra.strauss.
STRAUSS_VERSION=0.30.0
STRAUSS_SHA256=08c1a8e553594745c22294e158129005fd11ed09ed452d7d4f48566f38c66c96
# Action Scheduler is bundled unprefixed in content-publisher/lib/ (it is built to be
# bundled: with several copies on one site, the newest one loads). Pinned to a tag and
# the commit it must point at.
AS_TAG=4.2.0
AS_COMMIT=9e2c6b02e89652bade4445ea2ca5fb12e0fa7242
AS_REPO=https://github.com/woocommerce/action-scheduler.git

declare -A DIRS=([publisher]=content-publisher [connector]=content-publisher-connector)

say() { printf '%s\n' "$*"; }
die() { printf 'bin/build.sh: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null || die "$1 is needed ($2)"; }

sha256() { if command -v sha256sum >/dev/null; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi; }

strauss_phar() {
  local phar="$TOOLS/strauss-$STRAUSS_VERSION.phar"
  if [[ ! -f "$phar" ]]; then
    mkdir -p "$TOOLS"
    local url="https://github.com/BrianHenryIE/strauss/releases/download/$STRAUSS_VERSION/strauss.phar"
    say "    fetching Strauss $STRAUSS_VERSION" >&2
    if command -v curl >/dev/null; then curl -fsSL -o "$phar.part" "$url"; else wget -qO "$phar.part" "$url"; fi
    mv "$phar.part" "$phar"
  fi
  [[ "$(sha256 "$phar")" == "$STRAUSS_SHA256" ]] || { rm -f "$phar"; die "Strauss $STRAUSS_VERSION download doesn't match its pinned checksum; refusing to use it"; }
  printf '%s' "$phar"
}

# The plugin's version (plugin header) must equal composer.json's "version": it is
# written into vendor-prefixed/composer/installed.php, so a mismatch would be committed.
check_versions() {
  local dir="$1" main="$1/$(basename "$1").php"
  local header json
  header="$(sed -n 's/^ \* Version: *//p' "$main" | tr -d '[:space:]')"
  json="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["version"] ?? "";' "$dir/composer.json")"
  [[ "$header" == "$json" ]] || die "$(basename "$dir"): plugin header says $header, composer.json says \"$json\". Set both to the same version (and refresh the lock hash: bin/build.sh lock-hash)."
}

vendor_prefixed() {
  local dir="$1" strauss
  strauss="$(strauss_phar)"
  check_versions "$dir"
  # Keep the autoloader's class name (ComposerAutoloaderInit<suffix>) from one build to
  # the next: Strauss reuses the suffix of an existing autoload.php, and only picks a
  # random one when there is none. So clear the folder but leave a stub with the suffix.
  local suffix=""
  if [[ -f "$dir/vendor-prefixed/autoload.php" ]]; then
    suffix="$(sed -n 's/.*ComposerAutoloaderInit\([0-9A-Za-z_]*\)::getLoader.*/\1/p' "$dir/vendor-prefixed/autoload.php" | head -n1)"
  fi
  [[ -n "$suffix" ]] || suffix="$(php -r 'echo md5($argv[1]);' "$(basename "$dir")")"
  rm -rf "$dir/vendor" "$dir/vendor-prefixed"
  mkdir -p "$dir/vendor-prefixed"
  printf '<?php\nreturn ComposerAutoloaderInit%s::getLoader();\n' "$suffix" > "$dir/vendor-prefixed/autoload.php"

  (cd "$dir" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --no-plugins --quiet)
  (cd "$dir" && php "$strauss" >/dev/null)
  [[ -f "$dir/vendor-prefixed/autoload.php" ]] && grep -q "ComposerAutoloaderInit$suffix::" "$dir/vendor-prefixed/autoload.php" \
    || die "$(basename "$dir"): Strauss did not write vendor-prefixed/autoload.php as expected"
  # The packages' own composer.json files aren't needed at runtime (the autoloader is
  # generated); the shipped vendor-prefixed/ never had them.
  find "$dir/vendor-prefixed" -mindepth 2 -name composer.json -delete
  rm -rf "$dir/vendor"
}

action_scheduler() {
  local lib="$ROOT/content-publisher/lib/action-scheduler" cache="$TOOLS/action-scheduler-$AS_TAG"
  if [[ ! -d "$cache/.git" ]] || [[ "$(git -C "$cache" rev-parse HEAD)" != "$AS_COMMIT" ]]; then
    rm -rf "$cache"
    mkdir -p "$TOOLS"
    say "    fetching Action Scheduler $AS_TAG"
    git -c advice.detachedHead=false clone --quiet --depth 1 --branch "$AS_TAG" "$AS_REPO" "$cache"
  fi
  [[ "$(git -C "$cache" rev-parse HEAD)" == "$AS_COMMIT" ]] || die "Action Scheduler tag $AS_TAG doesn't point at the pinned commit $AS_COMMIT; refusing to use it"
  # The release as tagged, without its own tests, CI files and Composer files (not needed:
  # it loads itself; the shipped lib/ never had them).
  mkdir -p "$lib"
  rsync -a --delete --exclude '/.git' --exclude '/.github/' --exclude '/tests/' --exclude '/composer.json' --exclude '/composer.lock' "$cache/" "$lib/"
}

editor() {
  local dir="$ROOT/content-publisher"
  [[ -f "$dir/package.json" ]] || return 0
  if ! command -v npm >/dev/null; then
    say "    (node isn't installed: build/ left as committed)"
    return 0
  fi
  (cd "$dir" && npm ci --no-audit --no-fund --silent && npm test --silent >/dev/null && npm run build --silent >/dev/null)
}

deps() {
  need php "PHP 8.2+"; need composer "Composer 2"; need git "git"; need rsync "rsync"
  local t
  for t in "$@"; do
    local dir="$ROOT/${DIRS[$t]}"
    say "==> ${DIRS[$t]}: dependencies"
    vendor_prefixed "$dir"
    if [[ "$t" == publisher ]]; then
      action_scheduler
      editor
    fi
  done
}

write_config() {
  : "${AGENCY_URL:?Set AGENCY_URL to the agency site address, for example AGENCY_URL=https://agency.example}"
  AGENCY_URL="${AGENCY_URL%/}" AGENCY_NAME="${AGENCY_NAME:-Agency}" AGENCY_LOGO="${AGENCY_LOGO:-}" DEMO="${DEMO:-0}" \
    php "$ROOT/bin/agency-config.php" > "$1"
}

zips() {
  need zip "zip"
  mkdir -p "$DIST"
  local t
  for t in "$@"; do
    local slug="${DIRS[$t]}" src="$ROOT/${DIRS[$t]}"
    local stage; stage="$(mktemp -d)"
    mkdir -p "$stage/$slug"
    # Only what WordPress needs. Patterns starting with / are the plugin folder's own
    # files (a library's README or package.json inside vendor-prefixed/ or lib/ stays).
    rsync -a \
      --exclude '/vendor/' --exclude '/node_modules/' --exclude '/tests/' --exclude '/src/editor/' \
      --exclude '/composer.json' --exclude '/composer.lock' --exclude '/package.json' --exclude '/package-lock.json' \
      --exclude '/build.mjs' --exclude '/README.md' --exclude '/.gitignore' --exclude '/phpunit.xml.dist' \
      --exclude '.DS_Store' --exclude '.phpunit.result.cache' \
      "$src/" "$stage/$slug/"
    if [[ "$t" == connector ]]; then
      if [[ -n "${AGENCY_URL:-}" ]]; then
        write_config "$stage/$slug/config/agency.php"
      fi
      [[ -f "$stage/$slug/config/agency.php" ]] || die "content-publisher-connector/config/agency.php is missing: run AGENCY_URL=https://… bin/build.sh config"
    fi
    local version zip_path
    version="$(sed -n 's/^ \* Version: *//p' "$src/$slug.php" | tr -d '[:space:]')"
    zip_path="$DIST/$slug-$version${SUFFIX:-}.zip"
    rm -f "$zip_path"
    (cd "$stage" && zip -qrX "$zip_path" "$slug")
    rm -rf "$stage"
    # Smoke check: every PHP file in the zip parses.
    local unpacked; unpacked="$(mktemp -d)"
    (cd "$unpacked" && unzip -q "$zip_path")
    find "$unpacked" -name '*.php' -print0 | xargs -0 -n1 -P4 php -l >/dev/null
    rm -rf "$unpacked"
    say "    $zip_path ($(du -h "$zip_path" | cut -f1))"
  done
}

# composer.lock records a hash of composer.json; after editing composer.json by hand
# (e.g. "version" for a release), refresh it without touching the locked packages.
lock_hash() {
  local t
  for t in "$@"; do
    local dir="$ROOT/${DIRS[$t]}"
    (cd "$dir" && COMPOSER_ALLOW_SUPERUSER=1 composer update --lock --no-install --no-interaction --quiet) \
      || die "${DIRS[$t]}: couldn't refresh the lock hash (composer update --lock needs packagist.org)"
  done
}

targets() { if [[ $# -eq 0 ]]; then echo publisher connector; else for a in "$@"; do [[ -n "${DIRS[$a]:-}" ]] || die "unknown plugin $a (use publisher or connector)"; echo "$a"; done; fi; }

cmd="${1:-all}"
[[ $# -gt 0 ]] && shift
case "$cmd" in
  deps) deps $(targets "$@") ;;
  zips|all) deps $(targets "$@"); zips $(targets "$@") ;;
  check)
    deps $(targets "$@")
    if ! git -C "$ROOT" diff --quiet -- content-publisher content-publisher-connector || [[ -n "$(git -C "$ROOT" status --porcelain -- content-publisher content-publisher-connector)" ]]; then
      git -C "$ROOT" status --short -- content-publisher content-publisher-connector | head -40 >&2
      die "the rebuilt dependencies differ from what is committed (above)"
    fi
    say "OK: the rebuilt dependencies are identical to what is committed" ;;
  config) write_config "$ROOT/content-publisher-connector/config/agency.php"; say "wrote content-publisher-connector/config/agency.php" ;;
  lock-hash) lock_hash $(targets "$@") ;;
  *) die "unknown command $cmd (deps | check | zips | config | lock-hash)" ;;
esac
