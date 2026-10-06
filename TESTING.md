# Tests

Both plugins have PHPUnit suites that run inside WordPress, using WordPress's own test library. GitHub Actions runs them on every push (`.github/workflows/tests.yml`), on the latest WordPress and on 6.9.

| Suite | Config | What it covers |
| --- | --- | --- |
| Connector | `content-publisher-connector/phpunit.xml.dist` | OAuth (approval, codes, PKCE, refresh rotation), what a token may do on the client's site, REST, uploads, the status page |
| Golden files | `content-publisher/phpunit.xml.dist`, `GoldenSamplesTest` | `samples/*.txt` through the real pipeline, compared with `content-publisher/tests/golden/` |
| Publisher | `content-publisher/phpunit.xml.dist` | Everything in the agency plugin, including the golden files |

Tests are not shipped: `bin/build.sh` leaves `tests/` and `phpunit.xml.dist` out of the zips.

## Running them with wp-env

Needs Docker, Node 18+ and Composer 2 (or PHP and Composer on your laptop; see the note below).

```sh
composer install                     # at the repo root: PHPUnit and the polyfills, into ./vendor
npx @wordpress/env start             # WordPress + its test library, PHP 8.2 (.wp-env.json)

npx @wordpress/env run tests-cli --env-cwd=cpub-repo vendor/bin/phpunit -c content-publisher-connector/phpunit.xml.dist
npx @wordpress/env run tests-cli --env-cwd=cpub-repo vendor/bin/phpunit -c content-publisher/phpunit.xml.dist
```

`.wp-env.json` mounts the whole repo at `cpub-repo` inside the containers. The tests load each plugin themselves, so no plugin is activated on the wp-env sites. Add `--filter TokenPermissionsTest` (or any test name) to run part of a suite.

No PHP on your laptop: run Composer in the container instead, `npx @wordpress/env run tests-cli --env-cwd=cpub-repo composer install`.

Another WordPress version: `WP_ENV_CORE=WordPress/WordPress#6.9 npx @wordpress/env start --update`.

## Running them without Docker

With PHP 8.2, MySQL or MariaDB, and a WordPress test library ([wordpress-develop](https://github.com/WordPress/wordpress-develop)'s `tests/phpunit`, with its `wp-tests-config.php` filled in):

```sh
composer install
echo "WP_TESTS_DIR=/path/to/wordpress-develop/tests/phpunit" > .wp-tests.env   # gitignored
composer test             # both plugins
composer test:connector   # one suite
composer test:golden      # only the golden files
```

`WP_TESTS_DIR` in the environment works too, and takes priority over `.wp-tests.env`.

## What a token may do on a client's site

`content-publisher-connector/tests/TokenPermissionsTest.php` has one test per rule. Each test also checks that nothing changed on the site.

| Rule | Test |
| --- | --- |
| Editing (or deleting) a post the agency didn't create: 403 | `test_editing_a_post_the_agency_did_not_create_is_403` |
| Sending a field outside `RouteGate::WRITABLE_FIELDS` (meta, template, password, author, sticky): 403 | `test_a_field_outside_writable_fields_is_403_on_create`, `..._on_update`, `test_the_writable_fields_are_exactly_these` |
| Setting `private`: 403, even with the publish grant | `test_private_is_403_with_or_without_the_publish_grant` |
| Publishing or scheduling without the publish grant: 403 | `test_publishing_without_the_publish_grant_is_403`, `test_each_publishing_layer_holds_on_its_own` |
| Token sent to `admin-ajax.php?rest_route=`: nobody is signed in | `test_a_token_sent_to_admin_ajax_with_rest_route_is_not_authenticated` |
| Uploading anything but an image (PHP named `.png`, HTML, text, PDF, SVG): refused | `test_a_non_image_upload_is_refused` |
| Reusing a refresh token: connection revoked | `test_reusing_a_refresh_token_revokes_the_connection` |
| Reusing an authorization code: connection revoked | `test_reusing_an_authorization_code_revokes_the_connection` |
| The chosen person made administrator: token refused (401), connection revoked | `test_the_token_stops_working_when_the_chosen_person_is_made_administrator`, `..._gets_administrator_powers_another_way` |

**One deliberate exception to refresh-token reuse.** If the agency presents the same refresh token again within 2 minutes (`TokenStore::REISSUE_GRACE`), and the replacement it got was never used, the Connector assumes the agency lost its reply. It issues one new pair, instead of cutting the connection. A stolen copy used in that window is still caught: the agency's next renewal then presents a retired token, and the whole connection is cut. `ReissueAndIssuerTest` covers this. The reuse test above checks the cases outside the exception: after the replacement was used, after the 2 minutes, and a third presentation.

Publishing is blocked by two independent layers: `Auth/Sandbox.php` withholds `publish_posts`, and `RouteGate::drafts_only` refuses the status. A request through the REST API is stopped by whichever runs first, so `test_each_publishing_layer_holds_on_its_own` checks each one directly. A bug in either one alone then fails a test.

## Golden files

For each `content-publisher/samples/NN-name.txt`, `GoldenSamplesTest` takes the recorded structure map `NN-name.json` (what the AI answered for that file), so no AI is called. It runs the real pipeline (`Pipeline::run`, including the check that nothing was lost or changed), and compares with three files in `content-publisher/tests/golden/`:

| File | Contents |
| --- | --- |
| `NN-name.md` | The Markdown the reviewer sees and edits |
| `NN-name.blocks.html` | The block content sent to the client site |
| `NN-name.json` | Title, counts (headings, paragraphs, lists, tables, images), warnings, and the lines left out (tags, category) |

The golden files were also checked against the prototype's recorded output (`tests/fixtures/golden-samples.json`, from the original JavaScript), so they aren't just today's output written down. `test_golden_blocks_agree_with_the_prototype` keeps checking that.

**Changing the output on purpose** (a fix in the Markdown writer or the converter):

```sh
UPDATE_GOLDEN=1 composer test:golden      # or the same variable with wp-env's run command
git diff content-publisher/tests/golden/  # read every change: this is what clients will receive
```

If a sample's output differs from the prototype's, `test_golden_blocks_agree_with_the_prototype` fails too. Then either the change is a bug, or the prototype's output is the one that's wrong: say which in the commit message.

**Adding a sample:** put `NN-name.txt` and its structure map `NN-name.json` in `samples/`, run with `UPDATE_GOLDEN=1`, review, and commit all five files. Every `.txt` in `samples/` must have a map and golden files, or the test fails.
