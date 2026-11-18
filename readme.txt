=== Ars Nova Ops (Plugin Installer) ===
Author: Ars Nova (Jonathan Raabe) + Claude
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.4.0

Admin-only REST endpoints (namespace: ans-ops/v1) that let the Ars Nova
WordPress MCP connector install / update / activate / deactivate / delete
plugins by command. It wraps WordPress core's own Plugin_Upgrader.

Sources accepted by /plugin/install and /plugin/update:
  - slug     : install from the WordPress.org directory by slug
  - url      : download a zip from an allow-listed host (site itself,
               github.com + *.githubusercontent.com, drive.google.com,
               docs.google.com, *.googleusercontent.com)
  - zip_b64  : a base64-encoded plugin zip pushed in the request body
  - drive_file_id : a Google Drive file ID, downloaded AUTHENTICATED through
               ars-nova-google-connector's service account. This is the path
               for paid third-party plugin zips.

               Do NOT try to install those from a Drive share link via `url`.
               Drive serves browsers and servers differently: a link that
               downloads fine in an incognito window still hands WordPress a
               non-zip, and the failure surfaces as an unpack error that names
               the wrong cause. Proven on 2026-08-15. Because the fetch is
               authenticated, the Drive folder stays PRIVATE — share it with
               the service account, never with "anyone with the link".

Safety:
  - Every route requires a logged-in admin (install_plugins / activate_plugins
    / delete_plugins capability) via Application Password auth.
  - On the production host (arsnovasingers.org) install/update/delete refuse
    unless confirm_production=true is sent.
  - Blocked automatically if DISALLOW_FILE_MODS is enabled.

Routes:
  GET  ans-ops/v1/status
  GET  ans-ops/v1/plugin/list
  POST ans-ops/v1/plugin/install   { slug | url | zip_b64, activate?, overwrite? }
  POST ans-ops/v1/plugin/update    { slug | url | zip_b64, activate? }   (overwrite forced)
  POST ans-ops/v1/plugin/status    { plugin: "folder/file.php", active: true|false }
  POST ans-ops/v1/plugin/delete    { plugin: "folder/file.php" }
  POST ans-ops/v1/plugin/delete-dir { dir: "folder" }
  GET  ans-ops/v1/site/options
  POST ans-ops/v1/site/options     { timezone_string | gmt_offset | date_format
                                     | time_format | start_of_week | blog_public }
  POST ans-ops/v1/content/search   { q, post_types?, statuses?, case_sensitive?, limit? }
  POST ans-ops/v1/content/replace  { id, edits:[{find, replace, expect_count?}],
                                     fields?, regex?, dry_run?, confirm_production?,
                                     expect_modified_gmt?, ignore_lock? }

Content search / replace (1.4.0):
  - Both work on RAW stored content (Gutenberg markup, block attributes, HTML),
    which core search does not look at. Need edit_others_posts + edit_others_pages.
  - search: case-insensitive by default; default types are every public type
    plus wp_block and wp_template_part; default statuses publish, future,
    draft, pending, private. Returns id/type/status/title, match_count and
    ~80 characters of context around each match (match shown in [[...]]).
  - replace: dry_run defaults to TRUE and returns per-edit counts, match
    context and a line diff. Each edit must match exactly expect_count times
    (default 1) or nothing is saved. Edits apply in order. Plain strings by
    default; regex=true takes a full PCRE pattern with delimiters, with a
    lowered backtrack limit. fields defaults to ["content"]; "excerpt" allowed.
  - A real save: refused on LIVE without confirm_production=true; refused if
    block comments would stop balancing; refused if the post is open in the
    editor by someone else (ignore_lock=true overrides); refused if the user
    lacks unfiltered_html (kses would strip SVG elsewhere on the page). It
    snapshots the pre-edit state as a revision (undo_revision_id), saves via
    wp_update_post(), then reads the content back and reports verified.

== Changelog ==
= 1.4.0 =
* New POST /content/search - find text in RAW post/page content, including
  block attributes and HTML that core search misses.
* New POST /content/replace - change exactly the text named, with dry run by
  default, exact match counts, block-balance check, undo revision and a
  read-back check. Built so a one-line change no longer means retyping a whole
  page through wp_update_page (INFRA-29).
= 1.3.0 =
* New read-only route GET /site/inspect - returns allow-listed plugin settings
  that core REST does not expose (Tickera's tc_general_setting, the Tickera
  Mailchimp add-on's settings, the WooCommerce email from/reply-to fields).
  Pass ?name=<option> for one, or omit it for all.
* The inspect allow-list is SEPARATE from the writable one used by
  /site/options. That list is shared with the option writer, so anything added
  there would become writable; inspect is read-only by construction.
* Credential-looking values (key, secret, token, password, salt, auth, ...) are
  replaced with ***redacted*** before the response leaves the site, at every
  level of a nested settings array.
* Recursion is depth-capped at 8 so a pathological settings blob cannot hang
  the request.

= 1.2.0 =
* NEW source: drive_file_id — install a plugin zip straight from Google Drive,
  fetched authenticated via ars-nova-google-connector's service account. Added
  because the WordPress connectors moved to Cloud Run, which killed zip_path
  (it now resolves on the connector's container, not on anyone's PC), leaving
  no way to install a licensed third-party plugin without publishing it.
* The Drive download verifies the PK zip magic bytes before touching the
  unzipper, so a permissions failure reports as a permissions failure instead
  of "Incompatible Archive".
* /status now reports drive_ready, so a caller can tell whether the Google
  connector is present before attempting a Drive install.
* NEW routes: GET/POST ans-ops/v1/site/options — read and write the core
  options WordPress core REST omits (timezone_string, gmt_offset, blog_public
  and friends) behind a strict allow-list. Core's /wp/v2/settings leaves no way
  to fix a timezone by API, and a site on a manual UTC offset drifts an hour
  twice a year, which matters when you sell tickets to timed events.

= 1.1.0 =
* Verify plugin deletion actually happened rather than trusting delete_plugins()'s
  return value, which is TRUE on success but FALSE/NULL on no-op.
* Added /plugin/delete-dir for orphan directories left by a failed install.
* Flush the cached plugin scan so /plugin/list reflects reality immediately.

= 1.0.0 =
* Initial release.
