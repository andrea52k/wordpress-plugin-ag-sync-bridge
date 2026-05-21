# AG Sync Bridge

Versione canonica del plugin WordPress `ag-sync-bridge`.

Il plugin sincronizza un sito WordPress locale e un sito live tramite snapshot completi, con pull manuale o auto-pull settimanale sul locale, push manuale protetto verso il live, cron settimanale sul live, backup automatici e log leggibili.

Questa repo nasce dal confronto di quattro installazioni WordPress preesistenti.
I percorsi locali e i domini reali non sono documentati nella repo pubblica.

La base scelta e la variante funzionalmente piu completa, integrata con patch generiche utili trovate negli altri fork. I dettagli tecnici sono in `docs/source-analysis.md`.

## Documentazione per agenti

Se devi modificare o gestire il plugin da un agente automatico, leggi prima:

- `AGENTS.md` per contesto operativo, regole e rischi noti.
- `docs/architecture.md` per classi, opzioni, flussi pull/push e updater.
- `docs/operations-runbook.md` per comandi WP-CLI, release, update live e troubleshooting.
- `docs/source-analysis.md` per la scelta iniziale della base canonica.

## Versione

Versione plugin: `0.1.21`

Slug tecnico WordPress: `ag-sync-bridge`

Lo slug resta invariato per permettere aggiornamenti sicuri delle installazioni esistenti.

## Cosa sincronizza

- Database completo
- `wp-content/uploads`
- `wp-content/mpg-uploads`
- `wp-content/plugins`
- `wp-content/themes`
- `wp-content/mu-plugins`
- `wp-config.php` con merge sicuro dei valori ambiente-specifici sul target
- `.htaccess` opzionale
- Replace URL nei dataset V4MPG supportati (`.xlsx` e file testuali) durante l'import

## Cosa esclude di default

- `wp-content/ag-sync-bridge-data/*`
- `wp-content/plugins/ag-sync-bridge/*`
- cache, backup vecchi, log e file temporanei configurati nei pattern di esclusione
- cache generate in `wp-content/uploads/al_opt_content/*`, `wp-content/mpg-cache/*` e `wp-content/uploads/cache/*`

Il plugin esclude la propria cartella dal full overwrite per non interrompere operazioni in corso. La stessa versione del plugin va installata sia in locale sia sul live.

## Funzioni principali

- Endpoint REST protetti con HMAC SHA-256, timestamp e anti-replay transient
- Aggiornamenti plugin da GitHub Releases
- Download snapshot streaming, chunked JSON e raw chunked
- Upload snapshot diretto o a chunk
- Import remoto asincrono con polling dello stato remoto
- Backup automatico prima di pull e push
- Restore locale da backup
- Cleanup storage locale e remoto
- Lock runtime con sblocco forzato via WP-CLI
- Replace URL serializzato-safe nel database
- Replace URL anche per varianti JSON escaped (`http:\/\/...`)
- Replace URL nei dataset V4MPG (`.xlsx` e file testuali)
- Import SQL con remap prefisso tabelle e rimozione commenti MariaDB sandbox non compatibili
- Export PHP a batch con retry su perdita connessione MySQL
- Import/export CLI con limiti piu robusti per snapshot grandi

## Architettura

- Bootstrap plugin: `ag-sync-bridge.php`
- UI admin: `includes/class-admin-page.php`
- REST/API: `includes/class-rest-controller.php`
- Sync orchestration: `includes/class-sync-service.php`
- Export snapshot: `includes/class-export-service.php`
- Import snapshot: `includes/class-import-service.php`
- ZIP archive: `includes/class-archive-service.php`
- Database export/import/search-replace: `includes/class-database-service.php`
- Logging: `includes/class-logger.php`
- Cron: `includes/class-scheduler.php`
- Config/helpers: `includes/class-config.php`, `includes/helpers.php`
- WP-CLI: `includes/class-cli.php`

## Installazione locale

1. Copia la cartella `ag-sync-bridge` in `wp-content/plugins/`.
2. Attiva il plugin in WordPress.
3. Vai in `Strumenti > AG Sync Bridge`.
4. Imposta `role = local`.
5. Imposta URL del sito live.
6. Imposta lo stesso shared secret che userai sul live.
7. Salva le impostazioni.

## Installazione live

1. Copia la stessa cartella `ag-sync-bridge` nel sito live.
2. Attiva il plugin.
3. Vai in `Strumenti > AG Sync Bridge`.
4. Imposta `role = remote`.
5. Imposta lo stesso shared secret del locale.
6. Configura opzionalmente `.htaccess` e retention.
7. Salva le impostazioni.

## Configurazione minima

- Stesso plugin installato e attivo su entrambi i siti
- Stesso shared secret su locale e live
- URL live configurato sul sito locale
- HTTPS consigliato sul live
- Opzionalmente, cartelle backup esterne per restore manuale

## Pull live -> locale

Quando premi `Aggiorna il locale dal live`:

1. Il locale crea un backup automatico.
2. Il live genera uno snapshot aggiornato.
3. Il locale scarica lo snapshot.
4. Il locale importa il database.
5. Il locale esegue replace URL live -> locale in modo serializzato-safe.
6. Il locale importa file, plugin, temi, uploads e mu-plugins.
7. Il locale riscrive URL live -> locale nei dataset V4MPG supportati.
8. Il locale applica `wp-config.php` con merge dei valori target.
9. Il plugin scrive log e aggiorna lo stato.

Il live crea automaticamente lo snapshot settimanale, ma il locale non lo scarica/importa da solo finche non attivi l'auto-pull.

## Push locale -> live

Quando premi `Invia il locale al live`:

1. Devi digitare `INVIA LIVE`.
2. Il live crea un backup automatico.
3. Il locale crea uno snapshot completo.
4. Lo snapshot viene caricato sul live.
5. Il live importa database e file.
6. Il live esegue replace URL locale -> live.
7. Il live riscrive URL locale -> live nei dataset V4MPG supportati.
8. Il plugin scrive log e aggiorna lo stato.

L'import sul live viene accettato in modalita asincrona, pianificato come evento WordPress cron singolo e monitorato dal locale con polling dello stato remoto. Questo evita che una chiusura SSL/proxy durante un import lungo venga interpretata come fallimento immediato del deploy.

## Auto-pull locale

- Nel sito con `role = local` puoi attivare `Auto pull settimanale`.
- Quando e attivo, WordPress pianifica `ag_sync_bridge_weekly_pull`.
- Se il PC o XAMPP sono spenti, il pull non puo partire mentre il sito locale e offline.
- Quando il locale torna online, WP-Cron recupera l'esecuzione arretrata al primo accesso utile.

## Backup e restore

- Backup: `wp-content/ag-sync-bridge-data/backups`
- Snapshot: `wp-content/ag-sync-bridge-data/snapshots`
- Log: `wp-content/ag-sync-bridge-data/logs`
- Restore locale disponibile dalla UI admin
- Restore da ZIP/cartella esterna configurabile nelle impostazioni

## Cron

- Snapshot settimanale live: `ag_sync_bridge_weekly_snapshot`
- Auto-pull settimanale locale: `ag_sync_bridge_weekly_pull`
- Import remoto asincrono: `ag_sync_bridge_async_import_snapshot`

## WP-CLI

```bash
wp agsync status
wp agsync snapshot --type=manual
wp agsync pull
wp agsync push
wp agsync cleanup
wp agsync remote-cleanup
wp agsync lock
wp agsync unlock
```

## Costanti supportate

- `AG_SYNC_BRIDGE_ROLE`
- `AG_SYNC_BRIDGE_REMOTE_URL`
- `AG_SYNC_BRIDGE_SHARED_SECRET`
- `AG_SYNC_BRIDGE_STORAGE_DIR`
- `AG_SYNC_BRIDGE_AUTO_PULL_ENABLED`
- `AG_SYNC_BRIDGE_INCLUDE_HTACCESS`
- `AG_SYNC_BRIDGE_RETENTION_COUNT`
- `AG_SYNC_BRIDGE_REQUEST_TIMEOUT`
- `AG_SYNC_BRIDGE_EXCLUDE_PATTERNS`
- `AG_SYNC_BRIDGE_EXTERNAL_BACKUP_DIRS`
- `AG_SYNC_BRIDGE_MYSQL_BIN`
- `AG_SYNC_BRIDGE_MYSQLDUMP_BIN`
- `AG_SYNC_BRIDGE_ALLOW_GLOBAL_MYSQL_LIMITS`
- `AG_SYNC_BRIDGE_GITHUB_TOKEN`

## Aggiornamenti da GitHub

Il plugin integra un updater per WordPress basato su GitHub Releases.

Workflow release:

1. Aggiorna `Version` e `AG_SYNC_BRIDGE_VERSION` in `ag-sync-bridge.php`.
2. Crea lo ZIP con `git archive`, non con `Compress-Archive`:

```powershell
git archive --format=zip --prefix=ag-sync-bridge/ -o C:/xampp/ag-sync-bridge.zip HEAD
```

3. Verifica che lo ZIP abbia questa struttura:

```text
ag-sync-bridge/
  ag-sync-bridge.php
  includes/
  assets/
  uninstall.php
  README.md
```

4. Pubblica una GitHub Release con tag tipo `vX.Y.Z`.
5. Carica nella release l'asset chiamato esattamente `ag-sync-bridge.zip`.

WordPress controllera l'ultima release e proporra l'aggiornamento nella schermata Plugin.

La repo GitHub attuale e pubblica, quindi non serve token.

Se in futuro la repo torna privata, ogni sito dovra avere un token GitHub con permesso di sola lettura sul repository:

```php
define( 'AG_SYNC_BRIDGE_GITHUB_TOKEN', 'github_pat_xxx' );
```

Metti la costante in `wp-config.php`, sopra la riga `/* That's all, stop editing! */`.

Da `0.1.17`, cliccare `Bacheca > Aggiornamenti > Verifica di nuovo` forza anche il refresh della cache GitHub del plugin. Le versioni precedenti possono mantenere in cache la release GitHub per alcune ore; in quel caso installa manualmente lo ZIP piu recente una volta.

## Limiti tecnici

- Snapshot molto grandi possono richiedere tempi lunghi su hosting condiviso.
- Se `ZipArchive` manca, il plugin non puo creare/importare snapshot ZIP.
- Il fallback PHP per import/export DB e piu lento di `mysqldump/mysql`.
- `.htaccess` viene sovrascritto solo se abilitato.
- `wp-config.php` viene importato con merge dei valori ambiente-specifici del target.
- La cartella del plugin stesso non viene sovrascritta durante il sync.
- Nei dataset V4MPG vengono riscritti `.xlsx` e file testuali; vecchi formati binari come `.xls` vanno controllati manualmente.

## Checklist test finale

- Attivazione plugin riuscita
- Menu `Strumenti > AG Sync Bridge` visibile
- Cartelle runtime create in `wp-content/ag-sync-bridge-data`
- Cron settimanale registrato sul ruolo `remote`
- Stato connessione e schedule visibili con indicatori colore
- Test connessione riuscito
- Snapshot manuale creato correttamente
- Checksum SHA-256 verificato
- Validazione import package riuscita
- Pull locale da live eseguibile
- Push locale verso live protetto da conferma forte

## Disinstallazione

- La deactivation rimuove il cron.
- L'uninstall rimuove le option del plugin.
- I file snapshot/backup restano sul disco salvo purge manuale o costante custom.
