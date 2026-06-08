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

Local-only doctor:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync doctor --skip-remote --path=C:\xampp\htdocs\<site>
```

Pull live to local:

```powershell
C:\xampp\php\php.exe C:\xampp\wp-cli.phar agsync pull --path=C:\xampp\htdocs\<site>
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
write tests, fix server quota/permissions first. Do not use
`--skip-remote-backup` as a workaround unless a fresh live backup already
completed and the server storage issue is understood.

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
