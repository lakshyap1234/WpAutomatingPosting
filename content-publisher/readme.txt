=== Content Publisher ===
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.6.0
License: Proprietary

Agency-side plugin. Staff upload a client's post (.txt or .docx); it is structured automatically, reviewed here, and sent as a draft to the client's site through the Content Publisher Connector.

== Description ==

Status: Phase 6. Staff upload posts (.txt, .md or Word .docx, with their image files) under Content Publisher > Add posts. Each post is processed in the background, one at a time per client site: read, structured by the AI (it only assigns lines to the title, headings, paragraphs, lists, tables and images; it never writes text) and checked to render back to exactly the original text. Reviewers open it from Content Publisher > Posts, correct it in the editor with a live preview, choose its tags (and a publish date), and approve or reject it. An approved post goes to its client site in the background: as a draft for the site's editors, or, when the site allows it, published or scheduled straight away. Posts appear on the client site under the Author or Editor the site chose when it approved the connection. A post the agency published can be corrected (reopen, change, approve again: the live post is updated) or unpublished.

Client sites need the Content Publisher Connector 0.4.0 or later (sending refuses older ones, which can't protect against duplicate drafts). Publishing directly, posting under a client user and adding tags need 0.5.0, built for this site (the Status page shows the exact AGENCY_URL to build with).

= Setup =

1. Upload the zip under Plugins > Add New > Upload Plugin and activate it.
2. Open Content Publisher > Status. If "Encryption key" shows a problem, copy the generated line into wp-config.php, then reload the page.
3. Content Publisher > Settings: choose the AI (Google Gemini or Anthropic Claude) and paste its API key. Use a paid key before real client content goes through: free tiers may let the provider use submitted text.

4. Client sites > Settings (per site): send posts as drafts or published (published only works once the client site allows it on its Connector), the default category, whether a post's "Category:" line may override it, and whether the first image becomes the featured image (off by default: many themes already show the featured image above the post).
5. For reliable background processing and sending, have the server call wp-cron.php every minute (a real cron job). Without it, posts are processed while someone has wp-admin open.

= Requirements =

* WordPress 6.9+ (the bundled Action Scheduler 4.2 needs it)
* PHP 8.2+ with the sodium and mbstring extensions; intl recommended (Unicode normalisation); zip or zlib to read Word files
* HTTPS

= Who can do what =

* Administrators: everything, including connecting client sites (capability `cpub_manage`).
* Editors: upload, review, approve and reject posts (capability `cpub_review`).

= Sending, retries and failures =

* Temporary problems (the client site down or slow, a lost reply) are retried after 1, 5 and 15 minutes. Every step can be repeated safely: each image and the draft carry a one-time key, so a retry never makes a second copy.
* Problems someone has to fix (an image that can't be downloaded, the Connector refusing, the site disconnected) stop the send at once with the reason. Fix it, then "Send again" (Posts list or editor), or reopen the post, change it and approve it again.
* Web images are downloaded only from public addresses; addresses on the agency's own network are never fetched.
* Sending never goes further than what was approved: a post approved as a draft goes as a draft even if the site's settings change before it is sent.
* Live posts: when the site allows direct publishing, a published post can be reopened, corrected and approved again (the live post is updated), or unpublished (it becomes a draft there). A correction never puts back a post the client took down, and a post the client scheduled keeps its date. Published posts are checked daily for 90 days, so the editor knows if the client took one down.
* Tags: the reviewer picks them in the editor, from the client site's tags or new ones. New tags are added to the site when the connection allows it (Connector 0.5.0); otherwise they are listed for the site to create.
* Files: our copies of a post's source file and images are kept for 30 days after it goes live, then deleted. After that a post can still be corrected; its images are the ones already on the client site.
* If the client deletes a draft, the post shows it and can be sent again. Without direct publishing, a post the client already published is never overwritten from here.

= Deleting the plugin =

Deactivating keeps all data. Deleting the plugin revokes its access on every connected client site, then removes its tables, settings (including the encrypted AI key), queued jobs and stored files.

== Changelog ==

= 0.6.0 =
* Publishing directly: per site, "Send as: draft or published". Works when the client site allows it (Connector 0.5.0); posts can be scheduled with a publish date. The approve button says what will happen, and asks before anything goes live.
* Live posts: correct (reopen, edit, approve: the live post is updated) and unpublish. A correction never republishes a post the client took down, and keeps a client's schedule. Sending never goes further than what was approved.
* Tags: chosen in the editor from the client site's tags, or new ones (added to the site when allowed).
* Posts appear under the client user the site chose; the editor shows who.
* Files kept 30 days after a post goes live, then deleted (was: at once).
* Daily check also watches published posts (90 days): taken down or deleted on the site is shown.

= 0.5.0 =
* Sending: approved posts go to the client site as drafts in the background (one at a time per site), with their images uploaded to the client's Media Library (alt text and captions kept) and attached to the draft.
* Category from the site's default or the post's "Category:" line; "Tags:" that exist on the site; optional featured image.
* Duplicate protection with the Connector 0.4.0 one-time keys; retries after 1, 5 and 15 minutes for temporary problems; clear failures and "Send again" otherwise. Needs Connector 0.4.0 or later.
* Web images downloaded safely: every address the host resolves to is checked, the connection is pinned to a checked address, and redirects are followed one at a time, each checked.
* New statuses: Approved (waiting to send), Sending, Sent, Send failed, Published. The editor and Posts list link to the draft on the client site.
* Daily check of sent drafts: once the client publishes, our copies of the source file and images are deleted; drafts deleted on the client are noted and can be sent again.

= 0.4.0 =
* Add posts: upload .txt, .md and Word (.docx) posts with their images for a client site. Duplicate uploads are caught.
* Word files are read as text, with their pictures (and alt text and captions) taken out automatically; links are listed for the reviewer.
* Background processing on Action Scheduler, one post at a time per client site, retrying temporary AI problems; failed posts can be retried or set out as plain paragraphs.
* Posts list with statuses, and a review editor: formatting toolbar and guide, live preview, problems with line links, changes against the original text, images by file name (upload or replace during review), approve, reject with a reason, reopen, run the AI again, history.
* Per-site settings: default category, read live from the client site.
* Client files are kept in a private folder with random names and served only to reviewers.

= 0.3.0 =
* Content pipeline, ported from the prototype: text decoding (UTF-8, UTF-16, Windows-1252), AI structuring with validation, feedback retry and repair, tables, images with credits, Markdown writer, Markdown to blocks, and the fidelity check.
* AI providers: Google Gemini (default) and Anthropic Claude. API key stored encrypted.
* Pipeline test page and `wp cpub pipeline` command.
* Status: Unicode normalisation (intl) and AI key checks.

= 0.2.0 =
* Client sites: connect (OAuth 2 + PKCE, iss check), encrypted tokens, locked renewal with lost-reply recovery, daily checks, disconnect, remove.

= 0.1.0 =
* Phase 0: tables, capabilities, Action Scheduler, Status page.
