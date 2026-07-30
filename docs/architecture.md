# Architecture

This document explains how AG Sync Bridge is structured and how the major
classes cooperate.

## Bootstrap

`ag-sync-bridge.php` defines plugin metadata, constants, loads helpers/classes,
and starts `AGSyncBridge\Plugin`.

The plugin version must be kept in sync in:

- plugin header `Version`
- `AG_SYNC_BRIDGE_VERSION`
- `README.md`

## Main Classes

| File | Responsibility |
| --- | --- |
| `includes/class-plugin.php` | Service container, hook registration, activation/deactivation |
| `includes/class-config.php` | Settings, role detection, storage paths, state options |
| `includes/class-admin-page.php` | WordPress admin UI under `Strumenti > AG Sync Bridge` |
| `includes/class-rest-controller.php` | Authenticated REST API used between local and live |
| `includes/class-sync-service.php` | High-level pull, push, restore orchestration |
| `includes/class-local-maintenance-service.php` | Local pre-push check and update of plugins, themes and translation packs |
| `includes/class-export-service.php` | Snapshot/backup creation |
| `includes/class-import-service.php` | Snapshot validation/import, file import, cache clearing |
| `includes/class-database-service.php` | DB export/import, URL replace, prefix remap |
| `includes/class-file-system-service.php` | Runtime dirs, package listing, file copy/cleanup |
| `includes/class-archive-service.php` | ZIP creation/extraction |
| `includes/class-http-client.php` | Remote calls, chunk upload/download, polling |
| `includes/class-lock-manager.php` | Runtime lock acquisition/release |
| `includes/class-scheduler.php` | Weekly snapshot/pull cron, async snapshot cron, async import cron |
| `includes/class-github-updater.php` | GitHub Releases based plugin updater |
| `includes/class-cli.php` | `wp agsync ...` commands |
| `includes/class-logger.php` | File log and recent log option |

## WordPress Options

Settings:

```text
ag_sync_bridge_settings
```

State:

```text
ag_sync_bridge_state
```

Recent logs:

```text
ag_sync_bridge_recent_logs
```

GitHub release cache:

```text
_site_transient_ag_sync_bridge_github_release
```

The plugin preserves its settings/state during imports so a live database does
not overwrite the local remote URL, shared secret, role, `siteurl`, or `home`.

## Roles

`local`:

- usually XAMPP
- initiates pull/push
- stores remote URL
- can run auto-pull if enabled

`remote`:

- live hosting
- accepts authenticated REST actions
- creates snapshots for local and optional backups when explicitly enabled
- imports pushed snapshots asynchronously

Role detection is based on `home_url()` host unless overridden by settings or
constants.

## Runtime Storage

Default storage:

```text
wp-content/ag-sync-bridge-data
```

Subdirectories:

- `snapshots`: local or live snapshots
- `backups`: safety backups
- `temp`: per-operation working dirs and lock file
- `incoming`: chunked uploads
- `logs`: daily log files

The storage path is normalized by `Config::normalize_storage_dir()`.
Cross-platform absolute paths are rejected. For example, `C:/xampp/...` on
Linux falls back to the default live storage path.

## Authentication

The REST layer uses shared-secret HMAC signing:

- shared secret from settings or `AG_SYNC_BRIDGE_SHARED_SECRET`
- timestamp
- nonce
- body hash / request payload
- anti-replay transient

Both local and live must have the same secret. If a live request returns
`ag_sync_bridge_bad_signature`, check that the secret was not overwritten by a
failed or partial import.

## Snapshot Contents

Snapshots can include:

- database SQL
- `wp-content/uploads`
- `wp-content/mpg-uploads`
- `wp-content/plugins`
- `wp-content/themes`
- `wp-content/mu-plugins`
- selected root files such as `wp-config.php`, `robots.txt`, XML files
- optional `.htaccess`

Default excludes include:

- plugin runtime storage
- plugin folder itself
- cache folders
- old backup folders
- logs and temp files
- known site-generated cache directories

The plugin excludes its own folder from sync to avoid removing itself during an
operation.

From `0.1.25`, snapshots created by the official exporter include manifest
metadata:

- `snapshot_scope: full`
- `root_sync_files`
- `sitemap_integrity`
- `full_snapshot_requirements`

Pushes that reuse an existing local snapshot are blocked unless the manifest is
marked `full`. If the sitemap index or V4MPG project rows reference root XML
files, those files must also be present in the package.

From `0.1.26`, the exporter can also create intentional partial snapshots for
selective file/folder push. These packages use `snapshot_scope: partial`, omit
`database.sql`, and include `partial_entries`. The explicit preflight reports
the selected paths, scope and estimated transfer size. A selected directory
replaces that target subtree, so partial pushes require a remote pre-push
backup and are blocked without one.

## Remote operation cancellation

Remote async snapshots and imports have an immutable operation ID. A signed
request can cancel exactly one job. Queued jobs are descheduled and marked
`cancelled`; running jobs become `cancel_requested` and stop at a durable
checkpoint. The database importer is never interrupted mid-command: once a
database or file mutation has completed, cancellation is recorded as
`rollback_required` and the pre-import backup must be restored before the
target is considered healthy.

From `0.1.38`, database export and ZIP creation also stop cooperatively and
remove partial packages. Any error after database or filesystem mutation is
classified as `rollback_required`, which blocks new remote work. The block can
be cleared only with an authenticated `recover` reconciliation containing a
verification note plus either verified target integrity or verified rollback;
this never declares the interrupted sync successful.

Local orchestration uses the same contract. `wp agsync cancel` records a
cancellation request in `temp/operation.lock`; snapshot, download, upload and
import phases read that durable marker and stop at a safe checkpoint. If the
target may already have changed, recovery artifacts remain available while the
operation stays blocked as `rollback_required`.

## Remote operation heartbeat and reconciliation

From `0.1.36`, `operations/remote-operation.json` is the authoritative
file-backed control plane for async snapshot/import liveness. Workers update
`heartbeat_at`, `updated_at`, `stage`, `progress` and `heartbeat_sequence` at
durable checkpoints and repeatedly while files are added to a snapshot.

Status inspection classifies non-terminal work as `active` or
`stale_or_orphaned`; it does not auto-complete or auto-fail stale work.
Reconciliation is an authenticated two-step compare-and-swap procedure:

1. `quarantine` accepts only a stale operation with the exact inspected
   `updated_at`, changes it to `reconcile_requested`, and makes cooperative
   cancellation checks stop a worker that resumes.
2. after the grace period, `close` requires the new exact `updated_at`, verified
   worker absence, a note, and—for imports—verified target integrity or rollback.

The terminal state is `reconciled`, progress remains below 100, and the record
contains `declared_success: false`.

## Signed remote bridge update

`remote_update_bridge` runs only from a configured local peer and calls the
HMAC-authenticated remote update route. The remote service resolves a fixed
official GitHub release, checks the exact asset name, HTTPS repository path,
SHA-256, ZIP paths and embedded plugin version, then performs a WordPress
overwrite install only with direct filesystem access and no unresolved async
operation.

## Database Import

`Database_Service::import_from_file()` always prepares SQL before import.

Preparation does the following:

- removes MariaDB sandbox comments
- remaps table prefix from source to target when needed
- filters transient rows from the target `options` table

The transient filter removes option names starting with:

```text
_transient_
_transient_timeout_
_site_transient_
_site_transient_timeout_
```

Those rows are cache and can be regenerated by WordPress. Filtering them avoids
huge SQL packets from cache plugins or page-generator data.

## URL Replacement

Database replacement is serializable-safe:

- serialized values are unserialized and reserialized
- plain strings use `strtr`
- JSON-escaped URL variants such as `https:\/\/example.com` are included

Dataset replacement happens during file import for supported V4MPG files:

- `.xlsx`
- text-like files

Old binary formats such as `.xls` are not guaranteed.

## Pull Sequence

`Sync_Service::pull_from_remote()`:

1. acquire lock
2. create local pre-pull backup
3. request live snapshot asynchronously and poll remote state
4. download snapshot
5. import snapshot
6. save `last_pull`
7. release lock

Remote snapshot creation is asynchronous through
`ag_sync_bridge_async_create_snapshot`. The REST request returns `202` quickly,
then the local client polls `remote_snapshot_operation` until the live ZIP is
ready. This avoids provider/proxy timeouts while the live exports a large site.

`Import_Service::import_snapshot()`:

1. extract package
2. capture local environment state
3. import DB
4. refresh runtime cache
5. sync active plugins from source after import
6. replace URLs
7. import files
8. clear builder/cache data
9. restore local environment state in `finally`
10. cleanup temp dir

## Push Sequence

`Sync_Service::push_to_remote()`:

1. acquire lock
2. run remote storage doctor/preflight
3. skip live pre-push backup unless `remote_backups_enabled` is explicitly on;
   when required, accept only a `completed` response with server-verified
   basename, archive existence, positive bytes and SHA-256
4. create local full snapshot or selected-path partial snapshot
5. validate the local package and expected manifest scope
6. upload snapshot
7. trigger remote async import
8. poll remote import state
9. save `last_push`
10. release lock

Remote import is asynchronous through `ag_sync_bridge_async_import_snapshot`.
This avoids treating SSL/proxy disconnects during long imports as immediate
push failures.

Live pre-push backups are disabled by default from `0.1.28` to avoid consuming
hosting quota. They can be enabled from the admin setting or the
`AG_SYNC_BRIDGE_REMOTE_BACKUPS_ENABLED` constant.

From `0.1.37`, the backup endpoint always distinguishes `completed`, `skipped`,
`disabled` and `failed`. The remote verifies the archive against its own
filesystem before returning `completed`, and the local peer independently
validates the returned proof. Empty, legacy or unverified responses fail
closed whenever the deployment policy requires a backup. Full pushes retain
the existing optional-backup policy; selective pushes remain blocked without
a verified remote backup.

The remote import endpoint also rejects non-`full` snapshots unless the caller
passes the explicit partial-snapshot override for a recovery operation or the
package is an intentional selective push created by `--paths`.

## Updater Sequence

`GitHub_Updater` hooks:

- `pre_set_site_transient_update_plugins`
- `plugins_api`
- `upgrader_pre_download`

For public GitHub releases it uses the asset `browser_download_url`.
For private assets it can use `AG_SYNC_BRIDGE_GITHUB_TOKEN`, but this project
currently uses a public repo to avoid tokens.

From version `0.1.17`, WordPress manual update checks with `force-check` bypass
the plugin's GitHub release cache.
