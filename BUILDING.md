# Building the plugins

Both plugins ship third-party code that is **generated and committed**: a fresh clone runs on WordPress without building anything. `bin/build.sh` regenerates it from the files below, and builds the plugin zips.

| Committed, generated | Made from | By |
| --- | --- | --- |
| `content-publisher/vendor-prefixed/` | `content-publisher/composer.json` + `composer.lock` | Composer, then Strauss |
| `content-publisher-connector/vendor-prefixed/` | `content-publisher-connector/composer.json` + `composer.lock` | Composer, then Strauss |
| `content-publisher/lib/action-scheduler/` | Action Scheduler, a pinned tag and commit | `bin/build.sh` (git) |
| `content-publisher/build/` | `content-publisher/src/editor/` (see `content-publisher/README.md`) | esbuild (npm) |
| `content-publisher-connector/config/agency.php` | a profile in `bin/profiles/` (committed: `visiby`), or `AGENCY_URL`, `AGENCY_NAME`, `AGENCY_LOGO` | `bin/build.sh config` |

Don't edit any of these by hand: the next build overwrites them.

## Commands

Needs PHP 8.2+, Composer 2, git, rsync and zip; Node 18+ for the review editor (without Node, `build/` is left as committed).

```sh
bin/build.sh              # regenerate everything above (except agency.php), then both zips into dist/
bin/build.sh deps         # only regenerate (both plugins; or: deps publisher | deps connector)
bin/build.sh check        # regenerate, then fail if anything differs from what is committed
bin/build.sh zips connector   # one plugin's zip
bin/build.sh visiby           # the connector zip for Visiby: dist/content-publisher-connector-<version>-visiby.zip
bin/build.sh local            # the connector zip for the dev stack (agency.test + loopback test tool)
bin/build.sh config visiby    # rewrite the committed agency.php from a profile
AGENCY_URL=https://agency.example AGENCY_NAME="Acme" bin/build.sh config        # or for an agency running the Content Publisher plugin
AGENCY_URL=https://agency.example SUFFIX=-acme bin/build.sh zips connector    # a zip for another agency, committed agency.php untouched
```

The first run downloads Strauss and Action Scheduler into `.build-tools/` (gitignored), each checked against a pinned checksum or commit.

## Build profiles

The Connector trusts exactly one OAuth client (the agency), and only lets it return to the addresses in `config/agency.php`. A profile in `bin/profiles/<name>.php` is that config for a known client:

| Profile | client_id | Return addresses |
| --- | --- | --- |
| `visiby` | `visiby` | `https://api.visiby.net/cms-connections/wordpress/callback`, `https://stag-api.visiby.net/cms-connections/wordpress/callback` |
| `local` | `cpub-publisher` | `https://agency.test/…` (the dev stack, `dev/README.md`) and `http://127.0.0.1/callback` |

Only `local` may have a loopback (`http://127.0.0.1`) return address; the build refuses one anywhere else. Never install a `local` build on a live site. The Visiby callback path must match the route visiby-backend's cms-connections module serves: change both together.

## How the prefixing works

The Composer packages (league/oauth2-server, lcobucci/jwt, defuse/php-encryption, nyholm/psr7 and their dependencies in the Connector; league/commonmark and its dependencies in the Publisher) are copied into `vendor-prefixed/` with their namespaces prefixed (`CPub\Connector\Vendor\…`, `CPub\Publisher\Vendor\…`). Another plugin on the same site can then load its own copy of the same library without a clash.

The tool is [Strauss](https://github.com/BrianHenryIE/strauss) 0.30.0, pinned by checksum in `bin/build.sh`. It is configured in each plugin's `composer.json` under `extra.strauss`. There is no `scoper.inc.php`, because php-scoper was never used. Strauss is what produced the committed `vendor-prefixed/`, and keeping it is what makes the output reproducible.

Action Scheduler is **not** prefixed. It is built to be bundled: when several plugins ship copies, the newest version loads. It is the tagged release without its `tests/` and `.github/` folders.

## Upgrading or patching a library

1. In the plugin folder, `composer update vendor/package` (or `composer require vendor/package:^x.y`). This needs packagist.org.
2. `bin/build.sh deps <plugin>`, run the plugin's tests or try it on a local site, then commit `composer.json`, `composer.lock` and the changed `vendor-prefixed/` together.
3. Action Scheduler: change `AS_TAG` and `AS_COMMIT` in `bin/build.sh`, run `bin/build.sh deps publisher`, and commit `lib/`.
4. Strauss: change `STRAUSS_VERSION` and `STRAUSS_SHA256` in `bin/build.sh`.

A patch to a library: prefer upgrading to a release that has the fix. If you must patch, use a Composer patches plugin, never an edit inside `vendor-prefixed/`. A hand edit there is lost on the next build, and `bin/build.sh check` reports it.

## Versions

Each plugin's `composer.json` carries the plugin's version (`"version"`). It is written into `vendor-prefixed/composer/installed.php`, instead of the git branch and commit that Composer would use otherwise. The build stops if it differs from the plugin header's `Version:`. When you release a new version, change both, then refresh the lock's hash with `bin/build.sh lock-hash` (it doesn't change the locked packages).
