# Source Analysis

Analisi delle quattro copie locali del plugin `wp-content/plugins/ag-sync-bridge`.

Data analisi: 2026-05-12

## Copie analizzate

| Sito locale | Versione header | File sorgente escluso ZIP | Byte sorgente escluso ZIP | Valutazione |
| --- | ---: | ---: | ---: | --- |
| `disinfestazione` | `0.1.6` | 22 | 224257 | Base generica precedente |
| `disinfestazione2` | `0.1.7` | 22 | 240635 | Base piu completa |
| `bonasia` | `0.1.11` | 22 | 202846 | Fork site-specific con alcune patch DB utili |
| `meetmysicily` | `0.1.6` | 22 | 225548 | Variante branded della base precedente |

`disinfestazione` e `meetmysicily` condividono quasi tutto il codice applicativo; cambiano soprattutto metadata plugin e README. `bonasia` ha versione header piu alta, ma molte classi sono piu semplici o piu vecchie rispetto a `disinfestazione2`.

## Differenze principali

`disinfestazione2` differisce dalla base `disinfestazione` in questi file:

- `ag-sync-bridge.php`
- `includes/class-database-service.php`
- `includes/class-file-system-service.php`
- `includes/class-http-client.php`
- `includes/class-import-service.php`
- `includes/class-plugin.php`
- `includes/class-rest-controller.php`
- `includes/class-scheduler.php`
- `README.md`

Le differenze aggiungono funzionalita generiche, non solo branding:

- import remoto asincrono con polling dello stato
- raw chunk download oltre al chunk JSON
- upload chunk con retry
- retry su errori HTTP transienti
- cleanup storage locale/remoto piu completo
- lock manager con token, touch, force release e shutdown handler
- WP-CLI per lock/unlock/cleanup/remote-cleanup
- replace URL nei dataset V4MPG, inclusi `.xlsx`
- export PHP database a batch con reconnect mysqli
- remap prefisso tabelle durante import

`bonasia` contiene alcune patch utili, ma perde diverse capacita presenti in `disinfestazione2`:

- non ha raw chunk download completo
- non ha async import remoto
- non ha polling remoto post-push
- non ha cleanup remoto completo
- non ha lock manager avanzato
- non ha WP-CLI cleanup/lock
- non ha replace URL dataset V4MPG
- non ha PHP exporter a batch con reconnect

## Decisione

La versione canonica parte da `disinfestazione2`, perche e la base funzionalmente piu completa e meno site-specific.

La versione finale della repo e `0.1.12`, perche incorpora anche patch generiche ricavate da `bonasia`.

## Patch integrate da Bonasia

- `includes/class-database-service.php`
  - replace URL anche per valori JSON escaped (`http:\/\/...`)
  - preparazione SQL sempre prima dell'import
  - rimozione commenti MariaDB sandbox anche quando il prefisso tabelle non cambia
  - `--skip-extended-insert` su `mysqldump`
  - `--max-allowed-packet=134217728` su `mysqldump` e `mysql`
  - possibilita opt-in di alzare limiti globali MySQL con `AG_SYNC_BRIDGE_ALLOW_GLOBAL_MYSQL_LIMITS`

- `includes/class-rest-controller.php`
  - pulizia output buffer prima degli stream binari
  - `status_header()` esplicito su download streaming e raw chunk
  - streaming manuale a blocchi da 1 MB per il download ZIP completo

- `includes/class-import-service.php`
  - pulizia cache builder dopo import riuscito
  - flush cache WordPress
  - pulizia CSS Elementor se presente
  - hook Elementor e LiteSpeed purge

- `includes/class-file-system-service.php`
  - cleanup runtime consapevole di `remote_import_operation`
  - evita cleanup di `incoming` e chunk mentre un import asincrono remoto e `queued` o `running`

- `includes/class-config.php`
  - exclude cache `wp-content/uploads/al_opt_content/*`
  - exclude cartelle temporanee legacy `wp-content/plugins/meetmysicily-sync-*`

## Patch non integrate

- Branding Bonasia/Meet My Sicily, perche site-specific.
- Versione header Bonasia `0.1.11` come base, perche il numero e piu alto ma il codice e meno completo.
- Sostituzione delle classi `class-http-client.php`, `class-rest-controller.php`, `class-lock-manager.php`, `class-file-system-service.php` con quelle Bonasia, perche rimuoverebbe funzionalita gia migliori in `disinfestazione2`.
- Disabilitazione totale del fallback PHP dopo errore `mysql` CLI. La repo mantiene il fallback per compatibilita e recupero, mentre riduce le cause note di errore CLI con sandbox cleanup e packet limit.
- `SET GLOBAL` MySQL sempre attivo. Rimane disponibile solo via costante opt-in, perche su hosting condivisi puo fallire o modificare variabili globali del server.

## Stato finale atteso

La repo contiene una singola versione plugin, generica e riutilizzabile sui quattro siti, senza ZIP generati e senza branding locale. Le installazioni WordPress dovranno usare questa stessa cartella `ag-sync-bridge` su locale e live.
