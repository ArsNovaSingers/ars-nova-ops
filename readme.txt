=== Ars Nova Ops (Plugin Installer) ===
Author: Ars Nova (Jonathan Raabe) + Claude
Requires at least: 5.8
Requires PHP: 7.4
Stable tag: 1.0.0

Admin-only REST endpoints (namespace: ans-ops/v1) that let the Ars Nova
WordPress MCP connector install / update / activate / deactivate / delete
plugins by command. It wraps WordPress core's own Plugin_Upgrader.

Sources accepted by /plugin/install and /plugin/update:
  - slug     : install from the WordPress.org directory by slug
  - url      : download a zip from an allow-listed host (site itself,
               github.com + *.githubusercontent.com, drive.google.com,
               docs.google.com, *.googleusercontent.com)
  - zip_b64  : a base64-encoded plugin zip pushed in the request body

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

== Changelog ==
= 1.0.0 =
* Initial release.
