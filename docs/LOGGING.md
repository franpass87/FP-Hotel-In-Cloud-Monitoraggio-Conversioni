# Gestione log

Il plugin FP Hotel in Cloud utilizza un log testuale residente nella directory `wp-content/uploads/hic-logs` (percorso configurabile dalle impostazioni). La cartella viene creata e protetta automaticamente tramite `.htaccess` e `web.config` per bloccare accessi diretti.

## Rotazione e conservazione
- La dimensione massima del file è definita dalla costante `HIC_LOG_MAX_SIZE` (filtro `hic_log_rotation_days` per i giorni di retention).
- Quando il file corrente supera la soglia oppure scade la retention, viene ruotato con suffisso temporale (`hic-log.txt.YYYY-mm-dd_HH-ii-ss`).
- Se l'estensione `zlib` è disponibile, i file ruotati vengono compressi in `.gz` e mantenuti nella stessa directory.
- La pulizia dei log obsoleti avviene allo shutdown dell'esecuzione tramite `HIC_Log_Manager::cleanup_old_logs()`.

## Viewer amministrativo
- Menu **Monitoraggio → Log viewer** (capability `hic_view_logs`).
- La pagina esegue `hic_ensure_log_directory_security()` ad ogni caricamento per verificare l'esistenza della directory e dei file di protezione.
- Le voci disponibili includono il file attivo e i rotati non compressi; i file `.gz` sono elencati ma richiedono il download manuale.
- Ogni vista è paginata (default 100 righe) e mostra timestamp, livello, memoria e messaggio; non sono previste azioni di scrittura/cancellazione.
- I percorsi sono risolti e validati all'interno della directory dei log per evitare traversal o riferimenti simbolici.

Per ulteriori controlli è sempre possibile scaricare i file tramite SFTP/FTP o strumenti di hosting, mantenendo i permessi coerenti con l'utente del web server.
