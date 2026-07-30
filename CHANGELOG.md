# Changelog

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
