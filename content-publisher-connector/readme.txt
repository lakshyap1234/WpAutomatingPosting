=== Content Publisher Connector ===
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.5.0
License: Proprietary

Install on a client site to let the agency's Content Publisher create posts (drafts, or published when you allow it) and upload images there, after the site's administrator approves (OAuth 2).

== Description ==

The agency starts the connection from its own site. The client's administrator is sent to a consent screen here, sees exactly what is being asked for, and approves or denies. On approval:

* Posts appear under the person the administrator picks: one of the site's Authors or Editors, or a separate "agency-publisher" account (Author, cannot log in). Administrators can't be picked.
* The administrator decides whether the agency may publish directly (changeable later).
* The agency receives an access token (1 hour) and a refresh token (single use; each renewal returns a new one).

What the access allows: create draft (or pending) posts and edit them while they are drafts; if allowed, publish and schedule them and correct or unpublish them; upload images; read categories and tags and add new tags; check the connection. Only posts and images the agency created (each carries a signed mark). Everything else in the REST API is refused for these tokens, including other people's posts, private posts, deleting posts, pages, users, settings and plugins, even where the chosen person's role would allow it.

Cutting access: Settings > Content Publisher > Disconnect, deactivating the plugin, or the agency disconnecting from its side. Each revokes all tokens at once. The same screen shows an activity log of every request the agency made (kept 90 days).

Security:
* Only the agency built into this copy of the plugin can connect, and only back to its own address (config/agency.php, set when the zip is built).
* Authorization code flow with PKCE (S256 only). Codes are single use; using one twice cuts the connection.
* Reusing an old refresh token (a sign it was copied) cuts the connection.
* Only SHA-256 hashes of codes and tokens are stored.
* Tokens work only on the REST API, never in wp-admin. If the chosen person is made an administrator, deleted or can no longer write posts (or the separate account is given more than Author rights), the tokens stop working.
* While a token is used, the chosen person's permissions are cut down to the agency's few (raw HTML, others' posts and term management are never available through it).

= Setup =

1. Plugins > Add New > Upload Plugin, choose the zip, activate (or "Replace current with uploaded" to upgrade).
2. Settings > Content Publisher: check the Readiness table.

= Deleting the plugin =

Removes its tables, keys and settings. The agency-publisher user and its posts are kept; delete the user under Users if wanted.

== Changelog ==

= 0.5.0 =
* Posts appear under one of this site's Authors or Editors, chosen on the approval screen (or a separate agency account, as before). Whoever is chosen, the agency gets only its few permissions, and only for the posts and images it created (signed marks; a copy of a post isn't the agency's). Access stops if that person is deleted, loses the right to write, or is made an administrator.
* Optional: let the agency publish directly (approval screen, or Settings > Content Publisher at any time). It can then publish and schedule its own posts, and correct or unpublish them. Never private posts, never the site's own posts.
* The agency may add new tags (not change or delete existing ones).
* Listings, the user's details and images in the site's own live posts are kept from the agency.
* Upgrading marks the posts the agency created before, so it can still update them.

= 0.4.0 =
* Duplicate protection: a draft or image the agency sends with a one-time key is created once; a retry with the same key gets the existing one back. The key is saved as part of creating the post, so a request that dies midway can't leave a draft a retry would duplicate.
* Needed by Content Publisher 0.5.0, which sends drafts.

= 0.3.0 =
* A renewal repeated within 2 minutes whose reply was lost is re-issued instead of cutting the connection (the unused replacement is retired).
* Approval responses name this site (iss, RFC 9207), protecting the agency against mix-up attacks.

= 0.2.0 =
* OAuth 2 server: consent screen, token, revocation and discovery endpoints; permission gate; agency user; Disconnect; activity log.

= 0.1.0 =
* Readiness checks.
