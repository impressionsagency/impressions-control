# impressions-control

WordPress plugin: the server-to-server control channel between the Impressions hub/CLI and each client site. One copy per client site.

**Current release: v0.9.3** (2026-11-17). Next: v0.9.5 (fatal recorder shown in /state; optional plugin allow-list for Hub writes), pending release. This repo (`main` + GitHub Releases) is the only source of truth for the plugin. Check the latest release before starting any plugin work. Update this line at every release.

## Releasing
1. Bump `Version:` in `impressions-control.php` and `IMP_VERSION` (both).
2. Zip so the top-level folder is `impressions-control/` and name it `impressions-control-vX.Y.Z.zip`.
3. Create a GitHub Release tagged `vX.Y.Z` and attach that zip. Sites on v0.5.5+ self-update from it (bundled plugin-update-checker).
4. Update this README's "Current release" line and `PLUGIN-REFERENCE.md` in `impressionsagency/imp-hub`.

Cloud Claude sessions cannot create releases (blocked by GitHub); do step 3 from a local session or github.com.

## Host allowlists (both are global, shared by every client site)
- `/media-import`: `MEDIA_IMPORT_ALLOWLIST` in `includes/class-imp-content.php`. Entries starting with `.` are suffix matches; others are exact hostnames. Prefer exact hosts.
- `/design/asset-import`: `IMPORT_HOSTS` in `includes/class-imp-design.php`. A separate list; adding a host to one does not add it to the other.
- The `imp-hub` Worker does no host check of its own.

Full reference: `PLUGIN-REFERENCE.md` in `impressionsagency/imp-hub`.
