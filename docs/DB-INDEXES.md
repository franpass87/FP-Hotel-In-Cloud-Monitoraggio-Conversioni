# Database Index Review

Aggiornamento fase 10 del playbook di hardening/refactor. Questa nota documenta le interrogazioni frequenti sui principali
schemi del plugin e gli indici applicati/aggiornati per supportarle senza introdurre regressioni funzionali.

## `wp_hic_gclids`

**Query osservate**

- Letture cronologiche e pulizia storica: `WHERE created_at >= ?` / `DELETE ... WHERE created_at < ?` utilizzate da dashboard,
  report automatici e retention scheduler.
- Recupero rapido degli ultimi valori per un SID: `WHERE sid = ? ORDER BY created_at DESC LIMIT 1` eseguito dai flussi di
  tracking e privacy.
- Ricerca puntuale per singoli identificatori (`gclid`, `fbclid`, ecc.) già coperta dagli indici preesistenti a larghezza
  limitata.

**Indici applicati**

- `KEY created_at_idx (created_at)` per consentire l'uso di range scan sulle query temporali e velocizzare le cancellazioni
  batch del processo di retention.
- `KEY sid_created_at_idx (sid(100), created_at)` per ottimizzare gli ordinamenti per SID e recuperare l'ultimo record senza
  filesort.
- Gli indici parziali su gclid/fbclid/msclkid/ttclid/gbraid/wbraid/sid/utm_* restano invariati e coprono le ricerche
  puntuali.

## `wp_hic_realtime_sync`

**Query osservate**

- Deduplicazione e controllo stato: `WHERE reservation_id = ?` e `ORDER BY first_seen`.
- Retry delle notifiche: `WHERE sync_status = 'failed' AND attempt_count < ? AND last_attempt < ?`.

**Indici applicati**

- `UNIQUE KEY unique_reservation (reservation_id)` creato a schema.
- `KEY status_idx (sync_status)` e `KEY first_seen_idx (first_seen)` da schema.
- `CREATE INDEX idx_status_attempt (sync_status, last_attempt)` e `CREATE INDEX idx_reservation_status (reservation_id,
  sync_status)` gestiti dal Database Optimizer per coprire i filtri compositi.

## `wp_hic_booking_metrics`

**Query osservate**

- Rapporti giornalieri e dashboard: `WHERE is_refund = 0 AND created_at BETWEEN ...`, `GROUP BY channel`/`utm_source`.

**Indici applicati**

- `KEY created_at_idx (created_at)` e `KEY channel_idx (channel)` già presenti a schema.
- `KEY utm_source_idx (utm_source(191))` per aggregazioni sulle sorgenti di marketing.

## Monitoraggio

- Il Database Optimizer verifica all'inizializzazione che gli indici riportati siano presenti ed esegue `CREATE INDEX` solo
  quando mancanti, mantenendo compatibilità con installazioni esistenti senza downtime.
- Nuove installazioni ricevono gli indici direttamente tramite `dbDelta()` grazie agli aggiornamenti dello schema in
  `includes/database.php`.
