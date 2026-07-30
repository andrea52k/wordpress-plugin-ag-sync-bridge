# AG Sync Bridge

Versione canonica del plugin WordPress `ag-sync-bridge`.

Il plugin sincronizza un sito WordPress locale e un sito live tramite snapshot completi, con pull manuale o auto-pull settimanale sul locale, push manuale protetto verso il live, cron settimanale sul live, backup locali automatici e log leggibili.

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

Versione plugin: `0.1.43`

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
- root text sicuri come `robots.txt`, `llms.txt`, `llms-full.txt`, `ads.txt`, `app-ads.txt` e `humans.txt`
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
- Creazione snapshot live asincrona per evitare timeout HTTP durante i pull
- Download snapshot streaming, chunked JSON e raw chunked
- Upload snapshot diretto o a chunk form-encoded, compatibile con hosting che svuotano il raw body REST
- Import remoto asincrono con polling dello stato remoto
- Stop autenticato di un job remoto: annulla subito i job in coda; durante un import segnala `rollback_required` se il target e gia stato modificato
- Stop cooperativo durante export database e creazione ZIP, con rimozione del pacchetto parziale
- Errori dopo una mutazione classificati `rollback_required`; nuovi job bloccati fino a verifica esplicita del recupero
- Heartbeat file-backed per snapshot/import asincroni con fase, avanzamento, sequenza e classificazione `active`/`stale_or_orphaned`
- Riconciliazione autenticata a due fasi dei job stale, senza dichiarare il sync riuscito
- Aggiornamento AG Sync sul live da WP-CLI locale tramite richiesta firmata, release GitHub ufficiale, versione e SHA-256 obbligatori
- Preflight `doctor` locale/remoto per spazio, permessi e test scrittura prima dei push
- Gate locale obbligatorio prima di ogni push: verifica e aggiorna plugin, temi e traduzioni; se un aggiornamento fallisce, non vengono creati snapshot e non parte alcuna chiamata al live
- Manifest snapshot con scope `full`/`partial`, blocco dei push che riusano snapshot non completi e push selettivo file-only
- Controllo root sitemap/XML per evitare DB o sitemap index che puntano a file non presenti
- Estrazione ZIP entry-by-entry con diagnostica del file che fallisce
- Pulizia automatica di snapshot, backup, incoming, temp e upload chunk dopo operazioni e fallimenti
- Backup automatico locale prima del pull
- Backup live pre-push disattivati di default, riattivabili solo da impostazione/costante
- Restore locale da backup
- Cleanup storage locale e remoto
- Lock runtime con sblocco forzato via WP-CLI
- Replace URL serializzato-safe nel database
- Replace URL anche per varianti JSON escaped (`http:\/\/...`)
- Replace URL nei dataset V4MPG (`.xlsx` e file testuali)
- Import SQL con remap prefisso tabelle e rimozione commenti MariaDB sandbox non compatibili
- Export PHP a batch con retry su perdita connessione MySQL
- Import/export CLI con limiti piu robusti per snapshot grandi
- Hook `ag_sync_bridge_after_import` eseguito sul target dopo import riuscito, utile per rebuild/cache post-sync

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
2. Il live accetta la richiesta e genera uno snapshot aggiornato in asincrono.
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
2. Nei push completi il locale rileva e aggiorna plugin, temi e traduzioni disponibili. Se uno step fallisce, il push si ferma prima di contattare il live. Un update di AG Sync Bridge stesso va eseguito dal normale updater WordPress e poi il push va ripetuto. Nei push parziali espliciti gli updater di plugin, temi e traduzioni vengono saltati e lo skip viene registrato, perche sono estranei ai path file-only dichiarati.
3. Il locale esegue un preflight remoto su storage, permessi e test scrittura.
4. Il backup live pre-push viene saltato di default per non consumare quota hosting. Quando la policy lo richiede, entrambi i peer devono usare `0.1.42` o superiore e il push prosegue solo con stato `completed` e prova verificata dell'archivio. Un deploy parziale salva soltanto gli stessi path che verranno sovrascritti; un deploy completo conserva il backup completo.
5. Il locale crea uno snapshot completo.
6. Lo snapshot viene validato localmente: ZIP estraibile, manifest `full`, database, componenti e sitemap root coerenti.
7. Lo snapshot viene caricato sul live.
8. Il live rifiuta snapshot non `full`, salvo override esplicito di recovery.
9. Il live importa database e file.
10. Il live esegue replace URL locale -> live.
11. Il live riscrive URL locale -> live nei dataset V4MPG supportati.
12. Il live esegue l'hook `ag_sync_bridge_after_import` per integrazioni post-import.
13. Il plugin scrive log e aggiorna lo stato.

L'import sul live viene accettato in modalita asincrona, pianificato come evento WordPress cron singolo e monitorato dal locale con polling dello stato remoto. Questo evita che una chiusura SSL/proxy durante un import lungo venga interpretata come fallimento immediato del deploy.

### Push selettivo file/cartelle

Da `0.1.26`, il push puo inviare solo percorsi specifici dal locale al live.
Il pacchetto selettivo e file-only: non contiene database, non sostituisce
directory non richieste e non puo aggiornare AG Sync Bridge stesso.

Esempi WP-CLI:

```bash
wp agsync push --paths=robots.txt
wp agsync push --paths=.htaccess
wp agsync push --paths="robots.txt,llms.txt"
wp agsync push --paths="robots.txt,wp-content/mu-plugins/mio-file.php"
wp agsync snapshot --type=partial-test --paths=robots.txt
```

Nella UI admin, lascia vuoto il campo percorsi per un push completo oppure
inserisci un percorso per riga. Sono supportati percorsi sotto `wp-content/`,
file root espliciti come `.htaccess`, `robots.txt`, `llms.txt`, `ads.txt` e
XML. Ogni push parziale richiede un backup remoto verificato con
`scope=partial` e path identici al piano. Il backup è file-only; se un path non
esisteva sul live, il pacchetto registra la sua assenza per poterlo eliminare
durante un rollback. `wp-config.php`, core WordPress, cache, runtime AG Sync e
la cartella del plugin sono bloccati.

## Auto-pull locale

- Nel sito con `role = local` puoi attivare `Auto pull settimanale`.
- Quando e attivo, WordPress pianifica `ag_sync_bridge_weekly_pull`.
- Se il PC o XAMPP sono spenti, il pull non puo partire mentre il sito locale e offline.
- Quando il locale torna online, WP-Cron recupera l'esecuzione arretrata al primo accesso utile.

## Backup e restore

- Backup: `wp-content/ag-sync-bridge-data/backups`
- Snapshot: `wp-content/ag-sync-bridge-data/snapshots`
- Log: `wp-content/ag-sync-bridge-data/logs`
- Temp/incoming/chunk upload: puliti automaticamente dopo operazioni concluse o fallite
- Restore locale disponibile dalla UI admin
- Restore da ZIP/cartella esterna configurabile nelle impostazioni
- I backup live prima dei push sono disattivati di default; se servono, abilita `Backup live prima dei push` consapevolmente.
- Da `0.1.37`, il risultato distingue `completed`, `skipped`, `disabled` e `failed`. `completed` richiede basename, archivio esistente e non vuoto e SHA-256 verificato sul live; una risposta vuota o senza prova blocca qualsiasi push che richieda quel backup.

## Cron

- Snapshot settimanale live: `ag_sync_bridge_weekly_snapshot`
- Auto-pull settimanale locale: `ag_sync_bridge_weekly_pull`
- Snapshot live asincrona per pull manuale: `ag_sync_bridge_async_create_snapshot`
- Import remoto asincrono: `ag_sync_bridge_async_import_snapshot`

## WP-CLI

```bash
wp agsync status
wp agsync doctor
wp agsync doctor --deep
wp agsync snapshot --type=manual
wp agsync snapshot --type=partial-test --paths=robots.txt
wp agsync pull
wp agsync push
wp agsync push --paths=robots.txt
wp agsync push --paths=.htaccess
wp agsync push_plan --paths=robots.txt
wp agsync remote_cancel --operation-id=<id> --kind=import
wp agsync cancel
wp agsync remote_reconcile --operation-id=<id> --kind=import --action=quarantine --expected-updated-at=<timestamp> --note="Worker verificato assente" --worker-absent-verified
wp agsync remote_reconcile --operation-id=<id> --kind=import --action=close --expected-updated-at=<timestamp-quarantena> --note="Identita, pagine e dati verificati" --worker-absent-verified --target-integrity-verified
wp agsync remote_reconcile --operation-id=<id> --kind=import --action=recover --expected-updated-at=<timestamp> --note="Backup ripristinato e verificato" --rollback-verified
wp agsync remote_enable_backups --confirm="ENABLE REMOTE BACKUPS"
wp agsync remote_update_bridge --version=0.1.43 --sha256=<sha256-ag-sync-bridge.zip> --confirm="UPDATE AG SYNC"
wp agsync cleanup
wp agsync remote_cleanup
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
- `AG_SYNC_BRIDGE_REMOTE_BACKUPS_ENABLED`
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

Da `0.1.36`, un sito configurato come `local` puo aggiornare il peer `remote`
con `wp agsync remote_update_bridge`. La route usa la firma HMAC AG Sync con
nonce e protezione replay e accetta solo una release `vX.Y.Z` del repository
ufficiale con asset esatto `ag-sync-bridge.zip`. Versione, SHA-256 e conferma
sono obbligatori. Downgrade, reinstallazione, cambi di versione concorrenti,
sync remoti irrisolti, ZIP non canonici e filesystem interattivi vengono
bloccati. Il checksum va calcolato sull'asset finale pubblicato.

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
- Da `0.1.35`, identita URL, configurazione AG Sync e plugin attivi del target
  vengono ripristinati subito dopo l'import del database, prima delle fasi
  lunghe. Un worker terminato dall'hosting non lascia piu il live con
  `siteurl`/`home` e impostazioni del sito locale.
- Da `0.1.28`, i pull freschi richiedono AG Sync Bridge `0.1.28` anche sul live per creare la snapshot in asincrono; con live piu vecchi il provider puo ancora tagliare la chiamata HTTP.
- Da `0.1.29`, l'import via `mysql` rimuove dalla sessione SQL mode i flag incompatibili con dump WordPress legacy (`NO_ZERO_DATE`, `NO_ZERO_IN_DATE`, strict modes) per evitare fallback PHP lenti. Il replace URL usa anche un percorso veloce SQL per `wp_mpg_dataset_rows.row_data`.
- Se `ZipArchive` manca, il plugin non puo creare/importare snapshot ZIP.
- `wp agsync push --use-existing-snapshot` riusa solo snapshot marcati `full`; vecchi pacchetti senza scope vengono bloccati.
- `wp agsync push --paths=...` crea un pacchetto parziale file-only. Il formato base esiste da `0.1.26`, ma il protocollo sicuro di backup scoped richiede `0.1.42` su entrambi i peer.
- Da `0.1.43`, un push parziale esplicito non esegue aggiornamenti automatici di plugin, temi o traduzioni estranei allo scope. I push completi mantengono la manutenzione automatica fail-closed.
- `wp agsync push_plan [--paths=...]` mostra senza modifiche classificazione, trasferimenti, metriche full/partial e stato del rollback. Un push parziale richiede un backup remoto pre-push: senza backup abilitato viene bloccato, perché una cartella selezionata sostituisce il relativo sottoalbero sul live.
- `wp agsync remote_cancel --operation-id=<id> --kind=snapshot|import` annulla solo l'operazione remota indicata. Un import che ha gia modificato database o file resta `rollback_required` e va ripristinato dal backup.
- `wp agsync cancel` richiede lo stop cooperativo dell'operazione locale attiva. Il segnale resta nel lock e viene letto durante snapshot, trasferimenti e import.
- Uno stato `stale_or_orphaned` non equivale a errore certo e non equivale mai a successo. `remote_reconcile` richiede quarantena, attesa, nuova lettura dello stato e verifiche esplicite; la chiusura produce `reconciled`, mantiene l'avanzamento sotto 100 e registra `declared_success: false`.
- `--allow-partial-snapshot` esiste solo per recovery deliberate e puo omettere file dal live.
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
- Snapshot con scope `full` e sitemap root coerenti
- Checksum SHA-256 verificato
- Validazione import package riuscita
- Pull locale da live eseguibile
- Push locale verso live protetto da conferma forte

## Disinstallazione

- La deactivation rimuove il cron.
- L'uninstall rimuove le option del plugin.
- I file snapshot/backup restano sul disco salvo purge manuale o costante custom.
