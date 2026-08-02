# V4MPG table-scoped deployment

Version 0.1.45 introduces a fail-closed protocol for reviewed, content-only
V4MPG releases. It is separate from full and partial file sync.

## Required peer policy

The remote `wp-config.php` must explicitly list the only accepted targets:

```php
define( 'AG_SYNC_BRIDGE_V4MPG_ALLOWED_TARGETS', '101:dataset-a,202:dataset-b' );
```

The local peer must point backups at a directory outside the web and plugin
roots:

```php
define( 'AG_SYNC_BRIDGE_LOCAL_BACKUP_ROOT', 'D:/AHBackups' );
```

Site-specific values belong in each peer configuration and must never be
committed to this public repository.

The live cache implementation must expose
`di_cache_epoch_barrier_begin()`, `di_cache_epoch_barrier_bump()` and
`di_cache_epoch_barrier_end()`. Cache writers use
`di_cache_epoch_capture_request()` plus `di_cache_epoch_run_if_current()` so
the shared lock is held through the final HTML/meta publication. AG Sync takes
the exclusive barrier only after staging has been fully verified, purges and
bumps before the pointer switch, commits, purges and bumps again, and then
releases it. A missing or failed API blocks the mutation.

## Protocol

All endpoints are POST-only and require nonce HMAC authentication bound to the
exact JSON bytes. The deployment payload contains release receipt hashes,
final dataset/header/URL digests, and the declared cell deltas. It never sends
full row data or local version IDs. The remote clones the active rows with
`INSERT ... SELECT`, resolves each declared cell by the exact immutable
`version_id + project_id + url_path` tuple, retains the authoring `geo_id` plus
the immutable row's `city` and `province` as bidirectional audit evidence, verifies `before_hash`,
updates only the editorial field allowlist, and checks the ordered final digest
before switching both project pointers in one InnoDB transaction.

Before mutation, `backup` + `backup-page` export the three runtime tables in
100-row signed pages. `backup-seal` is available only after every exact page
was served and the local CLI reports its independently re-read row/page/file
proof. `backup-abort` removes an unsealed session and closes its shared runtime
operation after any local download or validation failure. No backup artifact
is retained on the live server.

The deploy journal records a durable `prepared-to-commit` receipt before
COMMIT and `committed-needs-postverify` immediately after it. An unresolved
state requires explicit recovery and is never cleared based only on age. The
shared AG Sync operation runtime is the sole operation lock, avoiding a second
file lock that could remain orphaned after a crash. Previous versions remain
present for signed, receipt-bound rollback.

## CLI

```text
wp agsync v4mpg plan --request=plan.json
wp agsync v4mpg backup --request=backup.json --output=D:/AHBackups/V4MPG/live/site.jsonl
wp agsync v4mpg deploy --request=deploy.json --backup-receipt=D:/AHBackups/V4MPG/live/site.jsonl.receipt.json --site-id=site-a --output-receipt=D:/AHBackups/V4MPG/live/deploy.json
wp agsync v4mpg verify --request=verify.json
wp agsync v4mpg rollback --request=rollback.json --output-receipt=D:/AHBackups/V4MPG/live/rollback.json
wp agsync v4mpg status --request=status.json
wp agsync v4mpg recover --request=recover.json --output-receipt=D:/AHBackups/V4MPG/live/recovery.json
wp agsync v4mpg full-backup-local --output=D:/AHBackups/WordPress/live-prepush-UTC/site.zip
```

`status` returns the exact journal and its canonical SHA-256. `recover` requires
that hash, the original operation ID, and one explicit action:
`accept-deployed` or `accept-rolled-back`. It independently recomputes every
active pointer and full ordered dataset digest, rejects mixed old/new states,
enters the same-operation cache barrier, purges twice, and only then closes the
shared runtime state. It never chooses an outcome from the requested action.

The full backup command creates a fresh remote full snapshot, downloads to
`.partial`, verifies SHA, size, manifest, full scope, source host and remote
role, atomically renames it, and then deletes only the exact remote ZIP and
sidecar. Cleanup is bound to operation ID, basename, archive SHA-256, manifest
ID and manifest SHA-256. It runs in `finally` on success and failure; a cleanup
failure leaves a host-bound local recovery journal. Every later deploy scans
the configured local backup root and remains blocked while a matching
`remote-pending` or `operation-pending` journal exists.
