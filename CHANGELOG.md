# Changelog

## 0.1.70

- Repair standalone empty `0x` value tokens while importing older PHP-export snapshots, using a quote-aware parser so valid hexadecimal values and text containing the same characters remain unchanged.

## 0.1.69

- Serialize empty binary and BLOB/TEXT fallback values as the valid empty hexadecimal literal `X''`; the previous bare `0x` token caused MySQL error 1054 during full recovery imports.

## 0.1.68

- Preserve the native MySQL error number and message before releasing table locks when a streamed PHP import query fails, so recovery diagnostics identify the exact database refusal instead of returning a generic error.

## 0.1.67

- Apply ZIP-wrapper preservation in the database import entry point (not the export entry point) and tighten the regression test to bind the check to the correct method body.

## 0.1.66

- Treat the single strict package-level streaming decision as the recovery authorization instead of recomputing the same origin contract after extraction.
- Add fail-closed diagnostic context when a recovery SQL stream is rejected before import.

## 0.1.65

- Preserve the `zip://` stream wrapper prefix when opening a verified recovery database entry; ordinary filesystem path normalization would otherwise collapse the wrapper URL and reject the stream before import.

## 0.1.64

- Same-site recovery now streams `database.sql` directly from the verified ZIP package, so it does not need enough hosting quota to materialize the multi-gigabyte SQL entry beside the archive.
- The streaming path is restricted to explicit recovery imports whose package is a live-origin AG Sync PHP export with exact site URL, home URL, and table-prefix identity.

## 0.1.63

- Recovery imports from a PHP-exported full backup of the exact same site now stream the verified SQL dump directly instead of creating a second multi-gigabyte temporary copy.
- The direct path is fail-closed: it requires identical source/target prefixes and exact source/target site and home URLs; normal cross-site imports still use the existing rewrite/filter preparation.

## 0.1.62

- Raise the bounded per-entry extraction ceiling from 5 GiB to 8 GiB so a verified, ZIP64-backed `database.sql` from a large production site can pass package validation.
- Keep the existing 10 GiB compressed archive and 20 GiB total expanded-data ceilings, entry-count limit, streaming extraction checks and manifest integrity gates unchanged.

## 0.1.61

- Keep the exported database in a protected snapshot-side file until `ZipArchive::close()` completes, so concurrent runtime cleanup cannot remove it during long file archival.
- Fail closed before and during full-package creation when the database dump is missing or empty; a file-only archive can no longer be labelled as a full snapshot.

## 0.1.60

- Use checkpointed set-based URL replacement for both MPG dataset tables and the versioned V4MPG runtime table, including composite primary keys.
- Recompute `row_sha256` whenever `mpg_runtime_dataset_rows.row_data` is remapped so runtime integrity checks remain valid.

## 0.1.59

- Limit full database exports to the active WordPress table prefix so stale tables from another environment cannot overwrite remapped target tables during import.
- Fail closed when the PHP SQL fallback reaches end-of-file with an incomplete statement instead of silently accepting a truncated import.

## 0.1.58

- Release MySQL `LOCK TABLES` state after the PHP import fallback before environment restore and prefix remapping.
- Release table locks on PHP import query failures so recovery checks can safely inspect the target.

## 0.1.57

- Consente a un recovery import completo, prodotto dallo stesso live, di sostituire anche un import in `rollback_required`, conservando riferimenti e audit dell'operazione sostituita; gli import normali restano bloccati.
- Aggiunge un opt-in firmato `recovery_hotfix` per aggiornare il bridge durante un `rollback_required` di import, con warning e audit espliciti.
- Preserva BLOB e colonne binarie nei dump con `--hex-blob` e letterali esadecimali nel fallback PHP.
- Corregge il riconoscimento delle quote SQL contando la parita dei backslash consecutivi.

## 0.1.56

- Mantiene il fix del cleanup remoto usando l'accesso configurazione compatibile con tutti i runtime e i test.

## 0.1.55

- Sul peer remoto ignora il `current_operation` locale importato nel database quando decide se può pulire `temp` e `incoming`; restano vincolanti le operazioni remote reali.

## 0.1.54

- Estende l'audit remoto con il dettaglio delle sottocartelle runtime AG Sync.

## 0.1.53

- Aggiunge un audit remoto in sola lettura delle dimensioni delle cartelle WordPress e `wp-content`, autenticato come le altre operazioni AG Sync.

## 0.1.52

- Espone in sola lettura il manifest del pacchetto al controller REST, evitando il fatal durante la validazione di un recovery import.

## 0.1.51

- Consente un import di recupero solo per sostituire un import live in quarantena con heartbeat scaduto.
- Il recupero accetta esclusivamente uno snapshot completo prodotto dallo stesso live; gli snapshot parziali e i pacchetti locali restano bloccati.
- Registra l'operazione sostituita nel nuovo runtime per un audit esplicito.

## 0.1.50

- Allows a signed, verified bridge update only when an import is already quarantined and its heartbeat is stale.

## 0.1.49

- Added durable checkpoints and heartbeat reporting throughout full-import URL replacement.
- Replaced the monolithic V4MPG URL update with cancellable primary-key micro-batches.

## 0.1.48

- Prevented runtime cleanup from deleting an active chunked upload during a full snapshot transfer.
- Added leased upload storage and regression coverage for cleanup while an upload is active.

## 0.1.47

- Il delta V4MPG lega ogni cella al `url_path` immutabile della versione base,
  risolve la riga tramite `version_id + project_id + url_path` e conserva
  `authoring_geo_id`, `city` e `province` come evidenza editoriale. La scrittura
  staging usa inoltre un CAS sull'hash esatto della riga precedente.
- Il backup live completo accetta lo scope `full` firmato nel manifest anche
  quando il wrapper di risposta non ripete il campo `snapshot_scope`.

## 0.1.46

- Corregge il backup live scaricato sul peer locale: i nuovi artefatti vengono
  creati nella cartella snapshot e il recovery può scaricare e cancellare in
  modo esatto anche un artefatto 0.1.45 già creato nella cartella backup,
  soltanto quando coincide con il risultato dell'operazione remota terminale.

## 0.1.45

- Aggiunge il protocollo candidato V4MPG table-scoped `wp agsync v4mpg`: piano, backup paginato conservato e verificato sul peer locale, deploy delta content-only, verifica e rollback stale-safe. Il live accetta soltanto target presenti nella allowlist esplicita, corpi HMAC nonce-bound e release checksum-bound; gli ID versione vengono sempre creati sul server.
- Aggiunge il backup full live download-only: il pacchetto viene validato e conservato fuori dalla web root sul peer locale, quindi lo ZIP temporaneo remoto e il relativo sidecar vengono eliminati anche sui percorsi di errore tramite una richiesta firmata vincolata a operation ID, basename, SHA, manifest ID e manifest SHA. Un cleanup non verificato lascia un journal locale associato all'host; ogni deploy successivo scandisce la backup root e resta bloccato finche il journal non viene riconciliato.
- Il deploy V4MPG richiede la barriera cache esclusiva `di_cache_epoch_barrier_begin/bump/end`; se manca o fallisce, ogni mutazione resta bloccata. La barriera viene acquisita solo dopo la verifica dello staging e copre pre-purge, switch atomico, COMMIT, post-purge e verifica. Journal pre-commit e control-plane condiviso rendono visibili le finestre di crash senza introdurre un secondo lock file orfanabile.
- Il CLI esegue il preflight completo degli argomenti, del backup semantico e del percorso ricevuta prima della richiesta mutante; persiste inoltre un journal pending durevole per recuperare un esito remoto se la scrittura finale fallisce. Le sessioni di backup paginato non sigillate possono essere abortite con una richiesta firmata.
- Aggiunge `v4mpg status/recover` per gli esiti ambigui prima/dopo COMMIT: il recovery richiede operation ID, SHA esatto del journal e scelta esplicita, ma accetta la scelta solo dopo aver ricalcolato puntatori e digest completi di ogni dataset. Stati misti restano in quarantena.
- Il doctor filesystem usa un probe runtime-like `.json` e riporta la fase esatta (`open`, `flock`, `write`, `flush`, `verify`, `cleanup`) con l'errore di sistema, senza mascherare quota o cleanup falliti.

## 0.1.44

- Aggiunto `wp agsync pull --paths=...` con `pull_plan --paths`: snapshot
  live e backup locale sono file-only e limitati alla stessa allowlist del push
  parziale.
- Creazione snapshot e import partial firmano il body JSON con nonce e HMAC,
  senza downgrade legacy sulle route protette.
- Il pull parziale verifica in modo fail-closed versione peer, scope, path,
  manifest e SHA-256; database e replace URL globale restano esclusi.
- Il backup locale partial è verificato prima del download e conserva tombstone;
  il restore importa esplicitamente backup partial con gli stessi path.
- Il doctor mostra la validazione partial e indica che quella full non è
  applicabile, evitando falsi allarmi.

## 0.1.43

- I push parziali espliciti non eseguono piu gli aggiornamenti automatici
  locali di plugin, temi e traduzioni, perche questi componenti sono estranei
  ai path file-only dichiarati.
- La manutenzione parziale verifica la presenza dei path gia normalizzati,
  registra scope, path, categorie saltate e motivazione; una richiesta
  parziale priva di path fallisce prima di qualsiasi operazione remota.
- I push completi mantengono la policy esistente: controllo e aggiornamento
  fail-closed di plugin, temi e traduzioni prima del deploy.
- Aggiunti test di regressione che simulano il fallimento dell'aggiornamento
  Click to Chat e verificano che blocchi ancora un push completo ma non un
  push esplicito `.htaccess`.

## 0.1.42

- I push parziali creano sul live un backup pre-push limitato esattamente agli
  stessi path del piano di deploy; i push completi continuano a usare il backup
  completo.
- `scope` e `paths` del backup vengono trasmessi nel body JSON protetto da
  HMAC con SHA-256 del corpo. Il route backup rifiuta body non firmati,
  alterati o degradati alla firma legacy.
- Il live applica ai path del backup la stessa normalizzazione e allowlist dei
  partial snapshot, rifiutando traversal, core WordPress, `wp-config.php`,
  runtime AG Sync e path root non autorizzati.
- I backup parziali sono file-only e non contengono il database. I path assenti
  sul live vengono registrati come tombstone, così il restore può rimuovere un
  file o una directory creati dal deploy e ripristinare lo stato precedente.
- Il push accetta il backup solo con `status=completed`, archivio e SHA-256
  verificati, `scope=partial` e lista path identica al piano. Mismatch, risposta
  legacy o backup saltato bloccano il deploy in modalità fail-closed.
- Aggiunti test per firma del body, tampering dei path, mismatch scope/path,
  allowlist remota, backup senza database e rollback dei path originariamente
  assenti.

## 0.1.41

- Il comando di abilitazione backup interroga ora il control plane file-backed
  autorevole invece del vecchio `current_operation` nelle opzioni WordPress.
- Stati importati o rimasti obsoleti nel database non generano più falsi
  blocchi; operazioni file-backed attive o irrisolte restano bloccanti.

## 0.1.40

- Aggiunto `wp agsync remote_enable_backups` per attivare la policy di backup
  sul peer live tramite richiesta HMAC firmata e conferma esatta.
- Il comando è accettato solo dal peer con ruolo `remote`, viene rifiutato
  durante operazioni attive e verifica il valore persistito prima del successo.
- Una costante server che disabilita esplicitamente i backup resta
  autorevole e impedisce il cambio remoto.

## 0.1.39

- Il push selettivo accetta ora il percorso root esplicito `.htaccess`.
- Il trasferimento resta file-only, richiede il backup remoto verificato già
  obbligatorio per ogni push parziale e continua a bloccare `wp-config.php`,
  core WordPress, runtime AG Sync e percorsi esterni alla root.
- Exporter e importer applicano la stessa allowlist, evitando pacchetti che
  il peer live non possa validare.
- Aggiunto un test dedicato per accettazione di `.htaccess` e rifiuto dei file
  root non autorizzati.

## 0.1.38

- Gli snapshot remoti controllano la cancellazione durante export database e
  creazione ZIP, eliminando gli archivi parziali prima della pubblicazione.
- Gli errori avvenuti dopo l'avvio di una mutazione database o filesystem
  diventano `rollback_required` invece di un generico errore terminale.
- `rollback_required` blocca nuove operazioni finché integrità del target o
  rollback non vengono verificati esplicitamente con `remote_reconcile
  --action=recover`.
- La cancellazione di un job ancora in coda salva nel control plane stato,
  fase, messaggio, timestamp finale e conferma di cleanup coerenti.
- Un fallimento di `wp_schedule_single_event()` finalizza anche il runtime
  file-backed e non lascia più un job fantasma in stato `queued`.
- Download e upload a chunk accettano checkpoint cooperativi; un upload
  cancellato invia l'abort e non completa il pacchetto remoto.
- `wp agsync cancel` scrive una richiesta persistente nel lock locale:
  snapshot, download, upload, pull, push e restore si fermano al checkpoint
  sicuro senza affidarsi alla terminazione forzata del processo.
- Se un import già mutato fallisce o viene annullato, il pacchetto e il suo
  checksum vengono conservati come artefatti di recupero fino alla verifica.
- Aggiunti test per recovery bloccante, errore dopo mutazione e cleanup degli
  ZIP cancellati, oltre al segnale di stop locale persistente.

## 0.1.37

- Il backup remoto pre-push restituisce ora uno stato esplicito:
  `completed`, `skipped`, `disabled` oppure `failed`.
- Un backup richiesto viene considerato completato soltanto dopo la verifica
  sul live di basename, esistenza dell'archivio, dimensione positiva e
  checksum SHA-256.
- Il push si interrompe in modalita fail-closed davanti a risposte vuote,
  legacy, saltate, disabilitate, fallite o prive di prova concreta.
- Il backup esplicitamente disabilitato resta compatibile con la policy
  esistente: e registrato come `disabled` nei push completi, mentre i push
  parziali continuano a richiedere un backup remoto verificato.
- Aggiunti test di regressione per backup disabilitato, saltato, fallito,
  risposta vuota, prova incompleta e successo reale.

## 0.1.36

- Aggiunto heartbeat persistente nel control plane file-backed delle operazioni
  remote asincrone, con fase, avanzamento, timestamp e sequenza.
- Aggiunti checkpoint durante export database, creazione archivio e fasi
  durevoli dell'import.
- Lo stato remoto distingue heartbeat attivo da operazione stale o
  potenzialmente orfana.
- Aggiunta riconciliazione firmata a due fasi (`quarantine` e `close`) con
  controllo su `updated_at`, periodo di grazia e verifiche esplicite. Una
  riconciliazione non viene mai registrata come sync riuscito.
- Aggiunto `wp agsync remote_update_bridge` per aggiornare il peer live da una
  release GitHub ufficiale verificata tramite versione e SHA-256.
- L'aggiornamento remoto blocca downgrade/reinstallazione, operazioni async
  irrisolte o con rollback richiesto, URL asset non ufficiali, ZIP non canonici
  e filesystem non direct.
- Dopo l'installazione invalida OPcache, richiede il riciclo dei worker
  LiteSpeed/LSAPI e verifica la nuova versione con retry dal peer locale.
- Aggiunti test automatici per heartbeat/stale/reconciliation e sicurezza del
  pacchetto di aggiornamento.

## 0.1.35

- Ripristino anticipato dell'identita e configurazione del target subito dopo
  l'import database, prima di replace URL e sincronizzazione file.
