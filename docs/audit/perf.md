# Performance Audit — Phase 5

## Bottlenecks Identified
- Ripetute query `SHOW TABLES` e `SHOW COLUMNS` durante il recupero degli identificativi di tracciamento e delle UTM generavano round-trip aggiuntivi ad ogni evento.
- I lookup per SID non sfruttavano cache persistenti, costringendo il plugin a interrogare il database anche quando i dati non cambiavano.

## Interventi Effettuati
- Aggiunto un livello di caching in memoria e tramite object cache WordPress per i lookup di tracking ID e parametri UTM, con TTL configurabile tramite filtro `hic_tracking_lookup_cache_ttl`.
- Memorizzato nella cache anche lo stato di esistenza della tabella `hic_gclids`, riducendo drasticamente il numero di `SHOW TABLES` per richiesta.
- Allineati i punti di scrittura dei dati (store tracking ID, acquisizione UTM e creazione tabella) con invalidazioni mirate della cache per garantire coerenza immediata.
- Introdotte costanti dedicate in `constants.php` per centralizzare TTL e gruppo cache.

## Verifica
- `php -l includes/helpers-tracking.php`
- `php -l includes/database.php`

I controlli statici confermano l’assenza di errori di sintassi e la nuova cache riduce il lavoro del database senza modificare le API pubbliche.
