# Agent Context

This repository is the canonical source for the WordPress plugin
`ag-sync-bridge`.

Read this file before changing code. It is written for coding agents that need
to work on the plugin without re-discovering the operational context.

## Repository Facts

- GitHub repository: `andrea52k/wordpress-plugin-ag-sync-bridge`
- Repository visibility: public
- WordPress plugin folder slug: `ag-sync-bridge`
- Main plugin file: `ag-sync-bridge.php`
- WordPress plugin basename: `ag-sync-bridge/ag-sync-bridge.php`
- GitHub release asset name must be exactly: `ag-sync-bridge.zip`
- Current version is defined in both:
  - header `Version` in `ag-sync-bridge.php`
  - constant `AG_SYNC_BRIDGE_VERSION` in `ag-sync-bridge.php`

Never rename the slug, folder, or main plugin file. Existing sites depend on
that basename for activation and updates.

## Installed Sites Used During Development

Local XAMPP sites:

- `C:\xampp\htdocs\disinfestazione`
- `C:\xampp\htdocs\disinfestazione2`
- `C:\xampp\htdocs\bonasia`
- `C:\xampp\htdocs\meetmysicily`

Their live remotes:

- `https://disinfestazioneitalia.com`
- `https://disinfestazionepro.it`
- `https://bonasiaproductions.com`
- `https://meetmysicily.com`

Use WP-CLI through:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync status --path=C:\xampp\htdocs\bonasia
```

## Non-Negotiable Rules

- Do not delete or overwrite `wp-content/ag-sync-bridge-data` on any site.
  It contains settings, shared secrets, logs, backups, snapshots, temp files,
  and lock files.
- Do not commit generated ZIPs, runtime logs, snapshots, backups, or temp
  directories.
- Do not use Windows `Compress-Archive` for release ZIPs. It can create package
  entries with Windows path separators that break WordPress updates on Linux.
- Use `git archive --prefix=ag-sync-bridge/` for release ZIPs.
- Do not add site-specific branding or one-site assumptions to canonical code.
- Keep local/live environment data separate. A live site must not store a
  Windows `C:/xampp/...` storage path.
- If a sync fails mid-import, assume the database may be partially imported.
  Check `siteurl`, `home`, `ag_sync_bridge_settings`, and the plugin log before
  retrying.

## Release Packaging

Correct package command from the repo root:

```powershell
git archive --format=zip --prefix=ag-sync-bridge/ -o C:/xampp/ag-sync-bridge.zip HEAD
Copy-Item C:\xampp\ag-sync-bridge.zip C:\xampp\ag-sync-bridge-0.1.17.zip -Force
```

Then publish a GitHub release with tag `vX.Y.Z` and upload the asset as:

```text
ag-sync-bridge.zip
```

The ZIP must contain:

```text
ag-sync-bridge/ag-sync-bridge.php
ag-sync-bridge/includes/...
ag-sync-bridge/assets/...
```

It must not contain entries with backslashes such as:

```text
ag-sync-bridge\ag-sync-bridge.php
```

Validate with PowerShell:

```powershell
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip=[System.IO.Compression.ZipFile]::OpenRead('C:\xampp\ag-sync-bridge.zip')
($zip.Entries | Where-Object { $_.FullName -eq 'ag-sync-bridge/ag-sync-bridge.php' }).Count
($zip.Entries | Where-Object { $_.FullName -match '\\' }).Count
$zip.Dispose()
```

Expected results are `1` and `0`.

## Update System

The updater is in `includes/class-github-updater.php`.

It reads the latest GitHub release from:

```text
https://api.github.com/repos/andrea52k/wordpress-plugin-ag-sync-bridge/releases/latest
```

Rules:

- The latest tag must be version-like, for example `v0.1.17`.
- The release asset must be named `ag-sync-bridge.zip`.
- Public repo updates do not need a token.
- Private repo updates can use `AG_SYNC_BRIDGE_GITHUB_TOKEN`, but the current
  project intent is public repo, no token.
- From version `0.1.17`, clicking WordPress `Bacheca > Aggiornamenti >
  Verifica di nuovo` bypasses the plugin GitHub release cache.
- Older versions can cache release metadata in
  `ag_sync_bridge_github_release`; if an update is invisible, install the
  latest ZIP manually once.

## Sync Model

There are two roles:

- `local`: local XAMPP site, points to the live remote URL.
- `remote`: live site, accepts authenticated REST requests.

Both sides must share the same `shared_secret`. Requests are signed with HMAC
SHA-256, timestamp, nonce, and anti-replay transient checks.

Runtime data lives in:

```text
wp-content/ag-sync-bridge-data
```

Subdirectories:

- `snapshots`
- `backups`
- `temp`
- `incoming`
- `logs`

## Pull Flow

Live to local pull:

1. Local acquires lock.
2. Local creates pre-pull backup.
3. Local asks live to create fresh snapshot.
4. Live exports DB and files into ZIP.
5. Local downloads snapshot, usually raw chunks.
6. Local verifies checksum.
7. Local imports DB.
8. Local replaces live URLs with local URLs.
9. Local imports files and datasets.
10. Local restores local environment settings.
11. Local clears caches and releases lock.

Important DB import behavior:

- SQL is prepared before import.
- Source table prefix can be remapped to target prefix.
- MariaDB sandbox comments are removed.
- `wp_options` transient and site transient rows are filtered because they are
  cache and can contain huge rows that break MySQL packet limits.
- MySQL CLI is preferred; PHP importer is fallback.

## Push Flow

Local to live push:

1. UI requires exact confirmation `INVIA LIVE`.
2. Live creates pre-push backup.
3. Local creates complete snapshot.
4. Local uploads snapshot to live.
5. Live imports asynchronously through WP-Cron.
6. Local polls live import state.
7. URLs are replaced local to live.
8. Logs and state are updated.

## Known Historical Issues

- Bad release ZIPs made on Windows caused live WordPress updates to deactivate
  the plugin with `Il file del plugin non esiste`. Fix: package with
  `git archive`.
- Bonasia had a huge `_transient_*` row in `wp_options`; MySQL failed with
  `max_allowed_packet` / `MySQL server has gone away`. Fix: filter transient
  option rows during SQL import/export.
- Some live sites created `public_html/C/.../xampp/...` because an old local
  `storage_dir` leaked into the live DB. Fix: cross-platform path normalization
  rejects Windows absolute paths on Linux and falls back to
  `wp-content/ag-sync-bridge-data`.
- Older updater versions cached GitHub release data for 6 hours. Version
  `0.1.17` reduces this to 30 minutes and bypasses it on manual update checks.

## More Documentation

- `README.md`: user-facing overview and installation.
- `docs/architecture.md`: class/module map and data flow.
- `docs/operations-runbook.md`: operational procedures and troubleshooting.
- `docs/source-analysis.md`: original comparison of the four plugin forks.
