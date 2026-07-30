# Changelog

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
