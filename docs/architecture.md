# Architettura FP HIC Monitor

> **Versione**: 3.6.0 · **Ultimo aggiornamento**: 2026-03-22

## Panoramica
La release 3.6.0 adotta un'architettura semplificata: un solo entrypoint applicativo e un solo modulo operativo dedicato all'integrazione Brevo.

## Struttura attuale
- `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php`
  - definisce costanti plugin
  - carica `includes/simple-brevo-sync.php`
  - avvia `Bootstrap::init()`
- `includes/simple-brevo-sync.php`
  - pagina admin impostazioni
  - endpoint REST webhook
  - normalizzazione payload HIC
  - invio contatto/evento a Brevo
  - integrazione opzionale con `fp_tracking_event`
  - test live Brevo e storico test
  - logging ultimo payload ricevuto (mascherato)

## Flusso webhook
1. `register_rest_route('hic/v1', '/conversion', ...)`
2. `webhookPermission()` valida il token query string.
3. `handleWebhook()` legge il JSON, normalizza e verifica i campi minimi.
4. Deduplica richiesta con transient.
5. `sendBrevoContact()` verso `/v3/contacts`.
6. `sendBrevoEvent()` verso `/v3/events` (o endpoint legacy).
7. `dispatchFpTrackingEvent()` emette eventi canonici + legacy.

## Eventi emessi verso FP Tracking
- Canonici: `booking_confirmed`, `purchase`
- Legacy: `hic_booking_created`, `hic_brevo_booking_synced`

Payload principale:
- `reservation_id`, `transaction_id`, `value`, `currency`, `status`
- `booking_date`, `arrival_date`, `departure_date`
- `user_data`: `em`, `fn`, `ln`, `ph`
- blocco `customer` con dati anagrafici normalizzati

## Sicurezza e dati
- Input sanitizzato in fase di normalizzazione.
- Verifica capability + nonce per azioni admin AJAX.
- Output admin escaped.
- Pannello payload mostra solo dati mascherati per evitare esposizione PII.

## Nota su documentazione storica
Le guide legacy in `docs/audit/*` restano come archivio storico delle versioni precedenti alla semplificazione 3.5+.
