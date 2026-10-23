# Content Publisher Connector: API

For the agency's side (the Content Publisher plugin, or Visiby's backend): how to connect to a client site and what a connection can do there. Every path below is under the site's REST API root, normally `https://client.example/wp-json`.

## Connecting

The Connector is an OAuth 2 server for exactly one client: the agency built into its `config/agency.php` (`bin/build.sh <profile>`, see BUILDING.md). That client is public (no secret) and uses the authorization code flow with PKCE (S256 only).

1. **Discovery:** `GET /cpub-connector/v1/oauth/metadata` (RFC 8414 shape). It returns `issuer`, `authorization_endpoint`, `token_endpoint`, `revocation_endpoint`, `scopes_supported`, `connector_version` and `site_name`.
2. **Authorize:** send the site's administrator to `authorization_endpoint` with `response_type=code`, `client_id`, `redirect_uri` (one of those in `agency.php`), `scope` (space-separated), `state` and `code_challenge` with `code_challenge_method=S256`. They approve or deny on a consent screen. You get back `code`, `state` and `iss`. Check that `iss` equals the `issuer` you discovered (RFC 9207).
3. **Token:** `POST /cpub-connector/v1/oauth/token`, form-encoded, with `grant_type=authorization_code`, `client_id`, `redirect_uri`, `code` and `code_verifier`. A code works once: a second use cuts the connection.
4. **Renew:** the same endpoint with `grant_type=refresh_token`, `client_id` and `refresh_token`. Access tokens last 1 hour. Refresh tokens last 90 days and work **once**: each renewal returns a new pair. Presenting a used refresh token cuts the connection. The one exception: a repeat within 2 minutes, while its replacement is still unused, is treated as a lost reply and re-issued once. Renew under a lock, so that two renewals never run at once.
5. **Disconnect:** `POST /cpub-connector/v1/oauth/revoke` with `client_id` and `token` (an access or refresh token that still works). It always answers 200.

Call the API with `Authorization: Bearer cpub_at_…`. Some hosts strip that header; then send the same value in `X-CPub-Authorization`. Tokens work only on the REST API, never in wp-admin.

## Scopes

| Scope | Allows | Feature |
| --- | --- | --- |
| `posts:write` | Create posts, and edit the ones the agency created (`/wp/v2/posts`). Preview links for them. | `preview`, `jsonld` |
| `pages:write` | The same for pages (`/wp/v2/pages`) | `pages` |
| `media:write` | Upload images, change or delete the agency's own (`/wp/v2/media`) | |
| `terms:read` | List categories and tags | |
| `terms:write` | Add new tags (`POST /wp/v2/tags`) | |
| `account:read` | `GET /cpub-connector/v1/connection`, `GET /wp/v2/users/me` (id and name only) | |
| `proposals:write` | Suggest changes to the site's published posts and pages (`/cpub-connector/v1/proposals`) | `proposals`, `jsonld` |

Ask only for what you use: the consent screen lists each one. Everything else in the REST API is refused (403 `cpub_forbidden`), even where the person posts appear under could do it.

## The connection

`GET /cpub-connector/v1/connection` (`account:read`). Use it to check the connection and to decide which buttons to show for this site.

```json
{
  "site_name": "Roofs Ltd",
  "site_url": "https://client.example/",
  "connector_version": "0.5.0",
  "scopes": ["posts:write", "pages:write", "media:write", "terms:read", "account:read", "proposals:write"],
  "connected_since": "2026-10-23T09:12:44",
  "user": { "id": 7, "name": "Visiby", "role": "author", "separate_account": true },
  "can_publish": false,
  "features": { "pages": true, "preview": true, "proposals": true, "jsonld": true },
  "scopes_supported": ["posts:write", "pages:write", "media:write", "terms:read", "terms:write", "account:read", "proposals:write"]
}
```

| Field | |
| --- | --- |
| `connector_version` | This Connector's version |
| `scopes` | What the administrator granted |
| `connected_since` | When the connection became active (UTC) |
| `user` | The person posts appear under. `separate_account`: the Connector's own Agency Publisher account, rather than one of the site's Authors or Editors |
| `can_publish` | The site lets the agency publish and schedule its own posts and pages directly. Otherwise they can only be saved as `draft` or `pending`. The administrator can change this at any time, so check it before each send. |
| `features` | See below |
| `scopes_supported` | Everything this Connector could grant |

**Features.** Gate your buttons on these, not on the version. Each one is true only when this Connector has the feature, the scope was granted, and the person posts appear under can do it:

| Feature | True when | Then you can |
| --- | --- | --- |
| `pages` | `pages:write` was granted, and posts appear under the separate account or one of the site's Editors (WordPress doesn't let Authors write pages) | Create and edit pages |
| `preview` | `posts:write` was granted, or `pages` is true | Get preview links |
| `proposals` | `proposals:write` was granted | Suggest changes to existing published content |
| `jsonld` | `posts:write` or `proposals:write` was granted, or `pages` is true | Send structured data |

A feature is false: if its scope is in `scopes_supported` but not in `scopes`, the administrator needs to reconnect and allow it (for `pages`, possibly choosing someone other than an Author). If a feature or scope is missing altogether, the site needs a newer Connector. New fields may be added to this response; don't treat unknown ones as errors.

## Posts and pages

The standard WordPress endpoints, `POST /wp/v2/posts` and `/wp/v2/pages`, with these limits:

- **Fields.** Posts: `title`, `content`, `excerpt`, `status`, `categories`, `tags`, `featured_media`, `date`, `date_gmt`, `cpub_jsonld`. Pages: `title`, `content`, `excerpt`, `status`, `parent`, `featured_media`, `date`, `date_gmt`, `cpub_jsonld`. Any other field is a 403 `cpub_field_not_allowed`, including `meta`, `template`, `password`, `author`, `sticky` and `menu_order`.
- **Status.** `draft` or `pending`. `publish` and `future` too when `can_publish` is true. Never `private`.
- **Only your own.** A connection can read, edit and list only the posts, pages and images it created, plus anything public. A post or page the site has published can be changed only when `can_publish` is true, and only if it is the agency's own. A page's `parent` must be one of the site's published pages, or a page the agency created.
- **Duplicate protection.** Send `X-CPub-Idempotency-Key` (16 to 64 of `A-Z a-z 0-9 _ -`) with each create. A retry with the same key returns the post or image already made, with status 200 and `X-CPub-Existing: 1`, instead of a second copy.
- **Images.** `POST /wp/v2/media` accepts images only, checked by their content. An image can be attached (`post`) only to a post that is still a draft, and can't be changed once a published post of the site uses it.

### Structured data (`cpub_jsonld`)

Schema.org JSON-LD on a post or page, printed in its `<head>` by the Connector. Send it as an object, a list of objects, or a JSON string; `null` removes it. It is returned only with `context=edit`. It is checked before anything is saved, so an invalid value creates nothing (400 `rest_invalid_param`):

- It must be valid JSON, with `"@context": "https://schema.org"` on each top-level item (only there).
- Each top-level `@type` (including each item of a top-level `@graph`) must be one of: Article, BlogPosting, NewsArticle, FAQPage, HowTo, Organization, LocalBusiness, Product, Service, BreadcrumbList, WebPage, Event, Recipe, VideoObject, Person, Review. Nested types (a `Question` in an `FAQPage`, an `Offer` in a `Product`) may be any schema.org type name.
- At most 32 KB, nested at most 12 levels deep, with at most 20 items.

## Previews

`GET /cpub-connector/v1/preview/{id}`, for one of the agency's own posts (`posts:write`) or pages (`pages:write`) that is a draft, pending or scheduled.

```json
{ "id": 42, "url": "https://client.example/?p=42&cpub_preview=…", "expires_at": "2026-10-23T09:42:44Z", "expires_in": 1800 }
```

Anyone with the link sees the post through the site's theme, without logging in, for 30 minutes. The link shows only that post. It stops working once the post is published, binned or no longer the agency's, or once the site disconnects. Pages it opens are marked `noindex`, aren't cached and send no referrer. Asking for a link to a published post gives 409 `cpub_preview_not_needed`, with the post's address in `data.link`.

## Proposals

For changing the site's **existing** published posts and pages (`proposals:write`). A proposal never changes anything by itself. The site's editors see it under Posts > Proposed changes, side by side with the current version, and apply or reject it. Applying keeps the previous version as a revision.

| Request | |
| --- | --- |
| `GET /cpub-connector/v1/proposals/source/{post}` | The post's own text to start from: `id`, `type`, `link`, `title`, `content` (block markup), `excerpt`, `jsonld`, `modified_gmt` |
| `POST /cpub-connector/v1/proposals` | `post`, plus at least one of `title`, `content`, `excerpt`, `jsonld` (`jsonld: ""` suggests removing it). 201 with the proposal. |
| `GET /cpub-connector/v1/proposals?post=&state=` | Proposals, newest first (100 at most) |
| `GET /cpub-connector/v1/proposals/{id}` | One proposal, including what it proposes |

```json
{
  "id": 3, "post": 18, "post_type": "page", "state": "rejected",
  "fields": ["title", "content"],
  "created_at": "2026-10-23T09:12:44", "decided_at": "2026-10-24T14:02:10",
  "note": "We only do repairs.",
  "proposed": { "title": "Roof repairs", "content": "<!-- wp:paragraph -->…", "excerpt": null, "jsonld": null }
}
```

- **Targets.** Only published posts and pages without a password. Anything else, including drafts, private posts and other post types, gives 404 `cpub_proposal_target`.
- **`state`.** `pending`, `applied`, `rejected` (with the editor's reason in `note`, if they gave one) or `superseded` (a newer proposal for the same post replaced it).
- **Changes.** Proposed text is cleaned as WordPress cleans posts by people without `unfiltered_html`: no scripts or event handlers.
- **Limits.** At most 200 proposals may wait at once (429 `cpub_proposal_limit`). Content is 1 MB at most.

## Errors

Errors are standard WordPress REST errors: `{ "code": "…", "message": "…", "data": { "status": 403 } }`. The `message` is written for people and may be shown to staff. Codes to handle:

| Code | |
| --- | --- |
| `cpub_invalid_token` (401) | Expired: renew it. Revoked or cut off: the site needs to reconnect. |
| `cpub_forbidden` (403) | Not allowed for this connection (scope or route) |
| `cpub_field_not_allowed` (403) | A field outside the lists above |
| `cpub_drafts_only`, `cpub_status_not_allowed` (403) | Publishing isn't allowed, or `private` was asked for |
| `cpub_post_locked`, `cpub_media_locked` (403) | The post is live on the site and can't be changed by the connection |
| `cpub_parent_not_allowed` (403) | A page's `parent` isn't allowed |
| `cpub_bad_key` (400) | A malformed `X-CPub-Idempotency-Key` |
| `rest_invalid_param` (400) | For example, invalid `cpub_jsonld` |
