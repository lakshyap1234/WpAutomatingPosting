# Local development: agency + client on your laptop

Two WordPress sites in Docker, both on HTTPS, under the same names for your browser and for the servers:

| Site | Address | Plugin | Who's there |
| --- | --- | --- | --- |
| Agency | https://agency.test | Content Publisher (from `../content-publisher`) | `admin` (Administrator), `rita` (Editor, can review and send) |
| Client | https://client.test | Content Publisher Connector (from `../content-publisher-connector`) | `admin` (Administrator), `sara` (Editor), `omar` (Author) |

All users have the admin password from `dev/.env`. The plugin folders are mounted from the repo, so code changes show on the next page load. `vendor-prefixed/` is committed, so nothing needs building first.

## Why it's set up this way

Running the two plugins locally hits three blockers. This setup handles all three without changing the plugins.

1. **HTTPS is required.** Client addresses must be `https://` (OAuth), and both readiness pages fail without it. Caddy serves both sites over HTTPS with a certificate from [mkcert](https://github.com/FiloSottile/mkcert). After `mkcert -install`, your browser trusts that certificate.
2. **The agency calls client sites with WordPress's "safe" HTTP functions.** They refuse hosts that resolve to private or loopback addresses, and they check certificates against WordPress's own CA list. The agency's dev mu-plugin (`mu-plugins/agency/`) allows the two dev hostnames only, and checks their certificate against mkcert's CA only. Every other host is treated exactly as on a real site, so real HTTPS sites still use WordPress's CA list and other private addresses are still refused.
3. **The browser and the servers must resolve the same names.** OAuth sends your browser to client.test and back to agency.test, while the token exchange and sending posts go server to server. Your browser finds the names through `/etc/hosts` (127.0.0.1, where Caddy listens). Inside Docker, the same names are aliases of the Caddy container. So both sides reach the same Caddy, with the same certificate.

The Connector trusts exactly one agency, built into its `config/agency.php`. The client's dev mu-plugin (`mu-plugins/client/`) overrides that through the Connector's `cpub_connector_agency` filter, setting it to https://agency.test, so no rebuild is needed.

**The mu-plugins are local only.** They live in `dev/`, outside the plugin folders, so `bin/build.sh` never puts them in a plugin zip. They also do nothing unless `WP_ENVIRONMENT_TYPE` is `local`. Never copy them to a real site.

## Fresh laptop to a connected site

You need:
- **Docker:** Docker Desktop, OrbStack or Docker Engine, with Compose v2.
- **[mkcert](https://github.com/FiloSottile/mkcert#installation):** `brew install mkcert` on macOS, or the package for your system.
- **Ports 80 and 443 free.** The sites must be on the standard port: WordPress's safe HTTP functions only allow ports 80, 443 and 8080.
- **Windows:** use WSL2 and run everything from the WSL shell. Your Windows browser reads `C:\Windows\System32\drivers\etc\hosts`, so add the hosts line there too. Run `mkcert -install` on Windows as well, so Windows browsers trust the certificate.

```sh
dev/setup.sh --add-hosts    # or add "127.0.0.1 agency.test client.test" to /etc/hosts yourself, then dev/setup.sh
```

`setup.sh` does the following, and is safe to run again:
1. Adds the hosts line.
2. Makes the certificate. The first time, `mkcert -install` may ask for your password to add its CA to your system.
3. Writes `dev/.env` with a fresh `CPUB_PUBLISHER_KEY` and admin password.
4. Starts the containers.
5. Installs WordPress on both sites.
6. Activates the plugins.
7. Creates the users.

It ends by printing both addresses and the admin password.

**Encryption key.** The Publisher encrypts the tokens it stores with `CPUB_PUBLISHER_KEY`, 32 random bytes in base64 (`openssl rand -base64 32`). `setup.sh` puts it in `dev/.env`, and the compose file passes it to `wp-config.php` with `getenv()`. Keep `dev/.env` (it's gitignored). With a new key, existing connections can't be decrypted, and you reconnect.

## Connect and send a draft

1. **Check readiness.**
   - On https://agency.test/wp-admin (admin), open **Content Publisher › Status**. Everything should be OK, except **AI**, which warns when no API key is set. That's fine here: see the next section.
   - On https://client.test/wp-admin (admin), open **Settings › Content Publisher**. Readiness should be all OK, and "Trusted agency" should name https://agency.test.
2. **Connect.**
   - On the agency, open **Content Publisher › Client sites**. Enter `client.test` and click **Connect**.
   - Your browser goes to client.test. Log in as admin if asked.
   - On the approval screen, choose **Sara Editor (Editor)** under "Posts appear under", and click **Approve**.
   - You're back on the agency, and the site shows **Connected**.
3. **Upload.** Open **Content Publisher › Add posts**, choose `content-publisher/samples/01-clean.txt`, and click **Upload**.
4. **Process it:** `dev/wp agency action-scheduler run`. The post becomes "Ready for review".
5. **Approve.** In **Content Publisher › Posts**, open the post and click **Approve and send as a draft**.
6. **Send it:** `dev/wp agency action-scheduler run` again.
7. **Check it arrived.** The editor shows "On Client (local) as a draft". On the client, **Posts › Drafts** has "5 Ways to Keep Your Garden Alive Through an Omani Summer" by Sara Editor. Or from the command line: `dev/wp client post list --post_status=draft`.

Steps 4 and 6 also happen by themselves within a minute or so while you have wp-admin open (WP-Cron). Running them by hand makes them immediate, and shows errors in your terminal.

## The AI

Processing a `.txt` or `.docx` post calls an AI to structure it. With `CPUB_DEV_RECORDED_AI=1` (the default in `dev/.env`), an offline stand-in answers instead. It knows only the files in `content-publisher/samples/`, so no key or network is needed for the walkthrough above.

To use the real AI:
1. Add your key under **Content Publisher › Settings**.
2. Set `CPUB_DEV_RECORDED_AI=0` in `dev/.env`.
3. Run `docker compose -f dev/compose.yaml up -d`.

`.md` posts don't use the AI at all.

## Everyday commands

```sh
dev/wp agency action-scheduler run          # process queued posts, send approved ones now
dev/wp agency action-scheduler list --status=pending
dev/wp client post list --post_status=draft
dev/wp agency cpub pipeline wp-content/plugins/content-publisher/samples/03-messy.txt   # the pipeline on one file
docker compose -f dev/compose.yaml logs -f agency                     # PHP errors
docker compose -f dev/compose.yaml down                               # stop (data is kept)
docker compose -f dev/compose.yaml down -v && rm dev/.env             # start over from nothing
```

Always use `dev/wp` for WP-CLI. It runs inside Docker with the site's environment (`CPUB_PUBLISHER_KEY`, `CPUB_DEV_RECORDED_AI`). Without that environment, the Publisher can't decrypt its tokens, and processing fails with "No AI provider is set up".

## When something fails

| Symptom | Cause, fix |
| --- | --- |
| Browser: "Your connection is not private" | `mkcert -install` didn't run, or your browser needs restarting after it. |
| Connect: "A valid URL was not provided" | The agency's dev mu-plugin isn't loaded. Check `WP_ENVIRONMENT_TYPE` is `local`, and that `dev/mu-plugins/agency` is mounted. |
| Connect: "cURL error 60: SSL certificate problem" | `dev/certs/rootCA.pem` is missing or from another mkcert CA. Run `dev/setup.sh` again. |
| Approval screen: "This approval link isn't valid" | The client doesn't trust agency.test. Check `dev/mu-plugins/client` is mounted. |
| "address already in use" on 80/443 | Another web server is running. Stop it; the sites can't move to other ports (see "You need"). |
| Post stuck in "Queued" | Run `dev/wp agency action-scheduler run` and read the error it prints. |

Other hostnames: change `agency.test` and `client.test` in `compose.yaml`, `caddy/Caddyfile` and `setup.sh`. If you change them, also define `CPUB_DEV_HOSTS` (comma-separated) and `CPUB_DEV_AGENCY_URL` in the sites' `WORDPRESS_CONFIG_EXTRA`.
