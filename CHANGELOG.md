# Changelog

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
