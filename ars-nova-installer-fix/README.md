# ars-nova-installer-fix

Renames a plugin's extracted folder during install to match the plugin's own main file.

> **This does not belong in `ars-nova-ops`.** It is parked on the `installer-fix-plugin` branch only because creating its own repo was blocked on 2026-08-13. Move it to `ArsNovaSingers/ars-nova-installer-fix` and delete this branch.

## The problem

GitHub source archives always extract to `<repo>-<ref>` — `ars-nova-core-main/`, `ars-nova-core-e11b366/`. WordPress names the installed plugin folder after that directory, so installing from a plain GitHub URL creates a **second** plugin instead of updating the existing one. `overwrite_package` never matches, because WordPress sees a different plugin.

Observed on staging 2026-08-13: HTTP 500, two copies of Ars Nova Core, the old one still active.

## The rule

> If the extracted directory contains exactly **one** top-level `.php` file with a `Plugin Name:` header, rename the directory to match that file.

Zero candidates or several means no guessing — the source is returned untouched.

## Verified

Staging, 2026-08-13. The same URL install that previously returned HTTP 500 and created `ars-nova-core-main/` now reports:

```
Downloading installation package from https://github.com/.../main.zip…
Unpacking the package…
Installing the plugin…
Removing the current plugin…
Plugin installed successfully.
```

One folder, updated in place.

## Effect

`wp_install_plugin(url: "https://github.com/ArsNovaSingers/<repo>/archive/refs/heads/main.zip", overwrite: true)` updates the plugin correctly, from any branch or commit, with no release ceremony and no hand-built zips. Manual wp-admin uploads of GitHub zips are fixed too.

## Notes

- Applies to plugin installs/updates only (`Plugin_Upgrader`); themes and core are untouched.
- Fails safe: any ambiguity, or a failed move, leaves WordPress behaving exactly as before.
- Removable at any time with no residue.
