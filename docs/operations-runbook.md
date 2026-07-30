# Operations Runbook

This runbook is for agents or developers operating AG Sync Bridge on the four
WordPress sites.

## Local Paths

ZIPs are usually exported to:

```text
C:\xampp\ag-sync-bridge.zip
C:\xampp\ag-sync-bridge-X.Y.Z.zip
```

Local sites use this shape:

```text
C:\xampp\htdocs\<site>
```

## WP-CLI Commands

Use XAMPP PHP:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync status --path=C:\xampp\htdocs\<site>
```

Status all locals:

```powershell
$sites=@('<site-a>','<site-b>','<site-c>','<site-d>')
foreach($site in $sites){
  C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync status --path="C:\xampp\htdocs\$site"
}
```

Plugin version:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar plugin get ag-sync-bridge --field=version --path=C:\xampp\htdocs\<site>
```

Doctor / preflight before large syncs:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync doctor --path=C:\xampp\htdocs\<site>
```

Deep doctor with snapshot and root sitemap checks:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync doctor --deep --path=C:\xampp\htdocs\<site>
```

Local-only doctor:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync doctor --skip-remote --path=C:\xampp\htdocs\<site>
```

Pull live to local:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync pull --path=C:\xampp\htdocs\<site>
```

Push using the latest existing local snapshot is allowed only when that package
has a manifest scope of `full`:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync push --use-existing-snapshot --path=C:\xampp\htdocs\<site>
```

From `0.1.28`, live pre-push backups are disabled by default. Enable the
`Backup live prima dei push` setting or `AG_SYNC_BRIDGE_REMOTE_BACKUPS_ENABLED`
only when the live hosting quota can safely hold those backups.

From `0.1.37`, never infer backup success from an HTTP 2xx response or from a
log line alone. A valid required backup has `status=completed` and proof with a
non-empty basename, `archive_exists=true`, positive `size_bytes` and a matching
SHA-256. `disabled`, `skipped`, `failed`, an empty response or missing proof
must stop a selective push. Both peers must run `0.1.37` or newer before using
required remote backups; legacy untyped responses are deliberately rejected.

Selective file/folder push, available only when both local and live run
AG Sync Bridge `0.1.26` or newer:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync push --paths=robots.txt --path=C:\xampp\htdocs\<site>
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync push --paths="robots.txt,llms.txt" --path=C:\xampp\htdocs\<site>
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync push --paths="robots.txt,wp-content/mu-plugins/example.php" --path=C:\xampp\htdocs\<site>
```

Selective packages are file-only. They do not import the database, do not
replace unrelated directories, and can include safe root text files such as
`robots.txt`, `llms.txt`, `llms-full.txt`, `ads.txt`, `app-ads.txt` and
`humans.txt`. They cannot update `wp-config.php`, WordPress core, AG Sync
runtime data, cache paths, or the AG Sync Bridge plugin folder.

Safe root text files beyond `robots.txt` are supported from `0.1.27`.

Do not use manually slimmed snapshots for normal deploys. The only bypass is a
deliberate recovery operation:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync push --use-existing-snapshot --allow-partial-snapshot --path=C:\xampp\htdocs\<site>
```

Show lock:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync lock --path=C:\xampp\htdocs\<site>
```

Force unlock only after confirming no PHP/WP-CLI sync process is running:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync unlock --path=C:\xampp\htdocs\<site>
```

## Manual Live Update

Use the latest versioned ZIP, for example:

```text
C:\xampp\ag-sync-bridge-X.Y.Z.zip
```

In WordPress admin:

1. `Plugin > Aggiungi nuovo`
2. `Carica plugin`
3. upload ZIP
4. confirm replacement
5. activate if WordPress leaves it inactive

For `0.1.28`, update both local and live before retrying a fresh pull. The live
must expose async snapshot creation or the hosting provider can still cut the
snapshot request with an HTTP timeout.

Do not delete:

```text
wp-content/ag-sync-bridge-data
```

If WordPress refuses because the plugin folder exists, delete only:

```text
wp-content/plugins/ag-sync-bridge
```

Then upload the ZIP again.

## GitHub Update Checks

From version `0.1.17`, this works:

1. `Bacheca > Aggiornamenti`
2. click `Verifica di nuovo`
3. return to `Plugin`

Older versions can cache GitHub release metadata for up to 6 hours. If a live
site is stuck on an older version and no update appears, install the ZIP
manually once.

### Update the live bridge from the local peer (0.1.36+)

Install the target AG Sync version on local first, calculate the SHA-256 of the
published `ag-sync-bridge.zip` asset, then run:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync remote_update_bridge --version=X.Y.Z --sha256=<64-hex> --confirm="UPDATE AG SYNC" --path=C:\xampp\htdocs\<site>
```

The command preflights the remote version, sends a signed request, and verifies
the version after installation. It refuses downgrade, reinstall, non-official
assets, checksum mismatches, unresolved async work, and filesystem methods that
need interactive credentials.

### Reconcile a stale remote operation

Never treat `stale_or_orphaned` as success. First verify the hosting worker is
absent and inspect logs, site identity, frontend, database and rollback state.
Copy the exact operation `updated_at`, then quarantine:

```powershell
wp agsync remote_reconcile --operation-id=<id> --kind=import --action=quarantine --expected-updated-at=<timestamp> --note="Worker assente verificato" --worker-absent-verified
```

Wait at least 30 seconds, read status again, and close using the new
`updated_at`. For an import, add the verification actually performed:

```powershell
wp agsync remote_reconcile --operation-id=<id> --kind=import --action=close --expected-updated-at=<new-timestamp> --note="Identita, dati e frontend verificati" --worker-absent-verified --target-integrity-verified
```

Use `--rollback-verified` instead when a rollback was completed and verified.
The `reconciled` record is an administrative closure, not a successful sync.

## Release Procedure

1. Update version in:
   - `ag-sync-bridge.php` header
   - `AG_SYNC_BRIDGE_VERSION`
   - `README.md`
2. Run PHP lint:

```powershell
Get-ChildItem -Recurse -Filter *.php | ForEach-Object { C:\xampp\php\php.exe -l $_.FullName }
git diff --check
```

3. Commit.
4. Build ZIP with `git archive`:

```powershell
git archive --format=zip --prefix=ag-sync-bridge/ -o C:/xampp/ag-sync-bridge.zip HEAD
Copy-Item C:\xampp\ag-sync-bridge.zip C:\xampp\ag-sync-bridge-X.Y.Z.zip -Force
```

5. Validate package:

```powershell
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip=[System.IO.Compression.ZipFile]::OpenRead('C:\xampp\ag-sync-bridge.zip')
($zip.Entries | Where-Object { $_.FullName -eq 'ag-sync-bridge/ag-sync-bridge.php' }).Count
($zip.Entries | Where-Object { $_.FullName -match '\\' }).Count
$zip.Dispose()
```

Expected:

```text
1
0
```

6. Push and release:

```powershell
git push origin main
gh release create vX.Y.Z C:\xampp\ag-sync-bridge.zip --repo andrea52k/wordpress-plugin-ag-sync-bridge --target main --title "AG Sync Bridge X.Y.Z" --notes "Release notes here."
```

The uploaded asset must be called exactly `ag-sync-bridge.zip`.

## Pull Monitoring

For long pulls, write WP-CLI output to site logs and monitor each process.

Example:

```powershell
$site='<site>'
$path="C:\xampp\htdocs\$site"
$logDir=Join-Path $path 'wp-content\ag-sync-bridge-data\logs'
$out=Join-Path $logDir "wpcli-pull-$site.out.log"
$err=Join-Path $logDir "wpcli-pull-$site.err.log"
Start-Process -FilePath 'C:\xampp\php\php.exe' -ArgumentList @('C:\xampp\wp-cli.phar','agsync','pull',"--path=$path") -RedirectStandardOutput $out -RedirectStandardError $err -PassThru -WindowStyle Hidden
```

Check running pull processes:

```powershell
Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
  Where-Object { $_.CommandLine -match 'wp-cli\.phar agsync pull' } |
  Select-Object ProcessId,CommandLine
```

Log file:

```text
wp-content/ag-sync-bridge-data/logs/ag-sync-bridge-YYYY-MM-DD.log
```

## Known Troubleshooting

### Doctor Fails On Live Storage

Symptoms:

```text
ag_sync_bridge_temp_dir_failed
ag_sync_bridge_chunk_write_failed
Unable to extract snapshot archive
```

Common causes:

- low disk space or hosting quota
- unwritable `wp-content/ag-sync-bridge-data`
- unwritable `temp`, `temp/upload-chunks`, `incoming`, `backups`, or `snapshots`
- stale partial upload directories left after a failed push

Run:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync doctor --path=C:\xampp\htdocs\<site>
```

If the remote doctor route is missing, update AG Sync Bridge on the live site
before retrying push. If doctor reports free space below required or failed
write tests, fix server quota/permissions first. Do not enable live pre-push
backups as a workaround unless the server quota and storage requirement are
understood.

From `0.1.24`, completed and failed operations mark their operation state as
finished and trigger runtime cleanup automatically. The default retention for
snapshots/backups is `1`; existing sites still on the old default `3` are
migrated down to `1` once, unless `AG_SYNC_BRIDGE_RETENTION_COUNT` is defined.

From `0.1.25`, `--use-existing-snapshot` blocks packages that are not marked
`full`. If a sitemap index or `wp_mpg_projects.sitemap_url` references root XML
files, those XML files must exist in the snapshot. Use `agsync doctor --deep`
to check this before a push.

From `0.1.26`, use `agsync push --paths=<relative-paths>` for deliberate
file-only deploys such as `robots.txt`. From `0.1.27`, the same flow also
supports safe root text files such as `llms.txt`. Update the live plugin first;
older live versions reject or cannot import unsupported partial packages.

From `0.1.39`, an explicit `--paths=.htaccess` is supported by both exporter
and importer. Treat it as a high-risk root-file deploy: keep the mandatory
verified remote backup enabled, inspect the generated plan and immediately
verify frontend, authenticated bypass, `Authorization`, `no-cache` and 404
behavior after the import.

From `0.1.40`, if the authenticated live peer has backups disabled, enable
them without opening the WordPress settings page:

```bash
wp agsync remote_enable_backups --confirm="ENABLE REMOTE BACKUPS"
```

The signed command is remote-only, refuses to run during an active operation
and fails if a server constant explicitly keeps backups disabled.

From `0.1.28`, fresh pulls create the live snapshot asynchronously through
`ag_sync_bridge_async_create_snapshot`. If a pull still fails with provider
messages such as `Request Timeout` or `takes too long to process`, confirm the
live is also on `0.1.28` or newer.

From `0.1.32`, a signed recovery route runs an import that remains `queued` for
more than 20 seconds. It is used only for the matching uploaded operation and
prevents hosts with a disabled or unreliable WP-Cron spawn from waiting until
the full request timeout.

### Plugin Deactivated: File Does Not Exist

Symptom:

```text
Il plugin ag-sync-bridge/ag-sync-bridge.php e stato disattivato:
Il file del plugin non esiste.
```

Cause: release ZIP was built with Windows path separators.

Fix:

- rebuild with `git archive --prefix=ag-sync-bridge/`
- upload corrected ZIP manually
- activate plugin

### Live Has `public_html/C/...`

Symptom: live hosting contains a folder like:

```text
public_html/C/xampp/htdocs/site/wp-content/ag-sync-bridge-data/logs
```

Cause: old code used a local Windows `storage_dir` on Linux live.

Fix:

1. update live to `0.1.16` or newer
2. confirm correct storage path is:

```text
public_html/wp-content/ag-sync-bridge-data
```

3. delete only the bogus `public_html/C` or `public_html/C:` folder

Do not delete the correct `wp-content/ag-sync-bridge-data`.

### MySQL Packet Failure From Huge Transients

Symptom:

```text
ERROR 1153 Got a packet bigger than 'max_allowed_packet' bytes
MySQL server has gone away
```

Cause: very large transient rows in `wp_options`.

Fix is in `0.1.14+`:

- transient option rows are removed from SQL import
- MySQL import packet limit is larger
- runtime cache is refreshed before restoring local state

### Slow Pull After MySQL CLI Fallback

Symptom:

```text
mysql import failed. Falling back to PHP importer.
ERROR 1067 (42000): Invalid default value for 'scheduled_date_gmt'
```

Cause: the local MySQL session rejects legacy WordPress zero-date defaults while
importing tables such as `wp_actionscheduler_actions`, so the sync falls back to
the slower PHP importer. Sites with large MPG datasets can then spend a long time
in URL replacement.

Fix is in `0.1.29+`:

- the `mysql` import session removes WordPress-incompatible SQL modes
- `wp_mpg_dataset_rows.row_data` URL replacement uses direct SQL `REPLACE`
- other text columns are selected only when they contain a source URL

### Bad Signature

Symptom:

```text
ag_sync_bridge_bad_signature
Invalid AG Sync Bridge signature.
```

Check:

- local and live shared secret match
- `role` is correct (`local` locally, `remote` live)
- local remote URL is correct
- failed partial import did not overwrite `ag_sync_bridge_settings`

If needed, recover settings from a pre-pull backup SQL row for
`ag_sync_bridge_settings`.

### Local Site URL Became Live URL

Symptom:

```text
siteurl = https://...
home = https://...
```

Cause: import failed before local environment restore completed or old cache
state prevented update.

Fix:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar option update siteurl 'http://localhost/<site>' --path=C:\xampp\htdocs\<site>
C:\xampp\php\php.exe C:\xampp\wp-cli.phar option update home 'http://localhost/<site>' --path=C:\xampp\htdocs\<site>
C:\xampp\php\php.exe C:\xampp\wp-cli.phar cache flush --path=C:\xampp\htdocs\<site>
```

Then verify `agsync status`.
