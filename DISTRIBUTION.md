# Distributing the Connector

What it takes to hand the Content Publisher Connector to self-serve customers: the licence, Plugin Check, updates, and the PHP version it needs. Two items here are decisions for Virender; each has a recommendation.

## Licence (decision)

**Recommendation: release the Connector under GPL-2.0-or-later. The Publisher plugin can stay proprietary while it is only used in-house.**

- **WordPress.org only lists GPL-compatible plugins.** WordPress's position is that a plugin built on its APIs is a derivative work of WordPress, which is GPL. Handing customers a "Proprietary" zip goes against that, and Plugin Check reports it as an error, so its CI job stays red until this changes.
- **Every library the Connector bundles is GPL-compatible:** league/oauth2-server, league/event, league/uri, defuse/php-encryption, nyholm/psr7, the psr/* interfaces and paragonie/random_compat are MIT; lcobucci/jwt is BSD-3-Clause.
- **Little is given away.** The Connector is only the door on the client's site: it is useless without the agency's service, which stays private. GPL covers the plugin's code, not Visiby's backend or its name.
- **Self-serve customers trust it more.** A GPL plugin that site owners can read is easier to install on a client site than a closed one.
- **The Publisher plugin** runs on the agency's own site and isn't handed to anyone. The GPL only asks something of whoever distributes the code, so it can stay proprietary. If it is ever distributed, it should be GPL too. (Its bundled libraries are MIT, BSD-3-Clause, or nette's BSD/GPL dual licence, so that would be easy.)

**If agreed,** the change is: `License: GPL-2.0-or-later` and `License URI: https://www.gnu.org/licenses/gpl-2.0.html` in the plugin header and `readme.txt`, `"license": "GPL-2.0-or-later"` in `composer.json` (then `bin/build.sh lock-hash connector`), and a `LICENSE` file with the GPL text.

## Lowest PHP version (decision)

**Recommendation: keep PHP 8.2.**

How this was checked:
- The Connector's dependencies were resolved for PHP 8.1 (Composer with `config.platform.php` 8.1).
- Its own code was scanned for features that need 8.2.
- It was **not** run on a real PHP 8.1: that couldn't be set up on the development machine. If 8.1 is wanted, add 8.1 to the CI matrix after pinning, and run the test suites there.

What was found:
- **The Connector's own code** uses nothing that needs 8.2.
- **Its two core libraries do need 8.2:** the locked league/oauth2-server 9.4.1 and lcobucci/jwt 5.6.0 both require PHP 8.2 or later.
- **Supporting 8.1 means going back to older versions:** league/oauth2-server 9.3.0 (November 2025) and lcobucci/jwt 5.3.0 (April 2024, two and a half years old). `composer audit` finds no known vulnerabilities in that set today. But the libraries that sign and check every access token would stay frozen at old versions, and every future security release would have to be checked for 8.1 support.
- **PHP 8.1 itself stopped receiving security fixes on 31 December 2025.** A host still on 8.1 is running an unsupported PHP.

On PHP 8.1, the Connector shows a clear "PHP 8.2 or newer is required" notice instead of failing (`src/Requirements.php`).

## Updates

Until the Connector is listed on WordPress.org, it updates itself from the agency's own channel:

- **WordPress.org is never asked.** The plugin header `Update URI: cpub-connector` tells WordPress (5.8+) not to check WordPress.org for this plugin, so a different plugin there with the same name can never be offered in its place. WordPress asks `src/Updates.php` instead.
- **WordPress shows the update as usual.** It reads a manifest at the build's `update_url` (cached for 12 hours, or 1 hour after a failed check) and shows the update on the Plugins screen, with auto-updates if the site uses them.
- **Every download is checked.** The update is installed only if the downloaded zip matches the manifest's SHA-256. The zip must be `https://` and on the same host as the manifest.

**Setting it up:**

1. Choose where the files live (Virender): any `https://` address that can serve two static files, for example `https://api.visiby.net/connector/update.json` or a storage bucket.
2. Put that address in the profile: `'update_url' => 'https://…/update.json'` in `bin/profiles/visiby.php`. A site only ever checks the address its zip was built with, so set this **before** the first zip goes to customers.
3. For each release, raise the version (plugin header and `composer.json`) and add a `= x.y.z =` changelog entry to `readme.txt`. Then run `bin/build.sh visiby`. It writes the zip and `<zip name>.update.json` to `dist/`.
4. Upload the zip to the folder `update_url` is in, **then** upload the manifest to `update_url` itself. The manifest names the zip, so it must go last.

The manifest is written by `bin/update-manifest.php`:

```json
{
  "version": "0.6.0",
  "download_url": "https://…/content-publisher-connector-0.6.0-visiby.zip",
  "sha256": "…",
  "requires": "6.5",
  "requires_php": "8.2",
  "tested": "7.1",
  "changelog": "<ul><li>…</li></ul>"
}
```

**When the plugin is listed on WordPress.org,** remove the `Update URI` header and `src/Updates.php`. WordPress.org doesn't allow plugins to update from elsewhere.

## Plugin Check

The `plugin-check` CI job builds the zip as it ships, installs it next to [Plugin Check](https://wordpress.org/plugins/plugin-check/), and runs `wp plugin check`. Errors fail the job; warnings are listed only. Two things are left out on purpose:
- `vendor-prefixed/`: the third-party libraries, which aren't ours to change.
- The `plugin_updater` check: it flags the update channel above, which is intended, and only matters for a WordPress.org listing.

**Status:**
- **The WordPress Coding Standards rules that Plugin Check uses** (escaping, nonces, input handling, SQL, i18n) were run on the shipped code. They now find no errors; five were fixed.
- **The readme's short description** is now 150 characters or less.
- **The generated `config/agency.php`** now refuses to run outside WordPress.
- **The licence** is the one error expected to remain, until the decision above.
- **The CI job itself hasn't run yet.** Its first run will show anything Plugin Check finds beyond the coding standards.
