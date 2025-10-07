# Architettura FP HIC Monitor

> **Versione**: 3.4.1 · **Ultimo aggiornamento**: 2025-10-07

Sincronizza prenotazioni Hotel in Cloud con GA4, Meta e Brevo via webhook e polling sicuro per un tracciamento server-to-server affidabile.

## Panoramica

FP HIC Monitor è un plugin WordPress enterprise-grade che risolve il problema del tracciamento conversioni quando il booking engine è su un dominio esterno. Utilizza un'architettura modulare basata su PSR-4, feature flags, circuit breaker e pattern repository per garantire affidabilità e scalabilità.

## Struttura dei moduli
- **Bootstrap** (`includes/bootstrap/module-loader.php`, `includes/bootstrap/lifecycle.php`, `includes/bootstrap/upgrade-manager.php`): carica i moduli core (`CORE_MODULES`), opzionali e admin; gestisce attivazione multisito, capability (`hic_ensure_admin_capabilities`) e migrazioni con gli hook `hic_plugin_fresh_install` e `hic_plugin_upgraded`.
- **Acquisizione dati** (`includes/api/webhook.php`, `includes/api/polling.php`, `includes/booking-poller.php`, `includes/intelligent-polling-manager.php`): espone REST API con rate limiting (`includes/api/rate-limit-controller.php`), gestisce WP-Cron (`hic_schedule_polling_events`) e la coda di prenotazioni (`hic_booking_events`).
- **Integrazioni marketing** (`includes/integrations/ga4.php`, `includes/integrations/facebook.php`, `includes/integrations/brevo.php`, `src/Services/*`): costruiscono i payload per GA4, Meta/Facebook CAPI e Brevo utilizzando i filtri `hic_ga4_payload`, `hic_fb_payload`, `hic_brevo_event` e gli helper di caching.
- **Processing & resilienza** (`includes/booking-processor.php`, `includes/circuit-breaker.php`, `includes/rate-limiter.php`, `includes/cache-manager.php`): centralizzano deduplicazione, retry e circuito di protezione per i servizi esterni; espongono gli hook `hic_booking_payload`, `hic_booking_processed`, `hic_retry_booking_sync`.
- **Admin & osservabilità** (`includes/admin/admin-settings.php`, `includes/admin/diagnostics.php`, `includes/admin/log-viewer.php`, `includes/performance-analytics-dashboard.php`, `includes/realtime-dashboard.php`): UI impostazioni, health monitor, log viewer, dashboard realtime; sfruttano `includes/log-manager.php` e `includes/runtime-dev-logger.php`.
- **Utility e sicurezza** (`includes/http-security.php`, `includes/input-validator.php`, `includes/helpers-tracking.php`, `includes/helpers/options.php`, `includes/helpers-scheduling.php`): sanitizzazione (`hic_sanitize_identifier`), gestione SID e UTM, feature flag (`hic_feature_flags`) e job scheduler.

## Flussi principali
### Webhook
1. L'endpoint `POST /wp-json/hic/v1/conversion` valida token, firma HMAC e dimensione payload (`includes/api/webhook.php`).
2. I dati vengono normalizzati, arricchiti con SID/UTM e accodati in `hic_booking_events`.
3. Il booking processor invoca gli hook `hic_should_track_reservation` e `hic_booking_processed` per consentire personalizzazioni.
4. Gli eventi vengono inviati alle integrazioni e registrati nelle tabelle di log/metrica (`hic_booking_metrics`, `hic_realtime_sync`).

### Polling intelligente
1. WP-Cron esegue `hic_schedule_polling_events` e `hic_process_polling_batch` (definiti in `includes/booking-poller.php`).
2. `includes/intelligent-polling-manager.php` regola frequenza e backoff basandosi su traffico, errori e stato delle code.
3. Le prenotazioni recuperate seguono lo stesso percorso di validazione e dispatch del webhook.

### Redirector `/go/booking`
- Configurato tramite `includes/admin/admin-settings.php`, salva UTM e identificatori nella tabella `hic_booking_intents` (gestita da `src/Repository/BookingIntents.php`) e imposta il cookie `hic_sid` per collegare sessione e prenotazione.

## Persistenza dati
| Tabella | Fonte | Scopo principale |
| --- | --- | --- |
| `hic_gclids` | `includes/database.php` | Mappa SID ↔ UTM e identificatori campagne (`gclid`, `fbclid`, `msclkid`, `ttclid`, `gbraid`, `wbraid`). |
| `hic_realtime_sync` | `includes/database.php` | Stato delle sincronizzazioni Brevo con tentativi, errori e payload serializzati. |
| `hic_booking_events` | `includes/database.php` | Coda di eventi di prenotazione in attesa di elaborazione, con deduplicazione per `booking_id` + `version_hash`. |
| `hic_failed_requests` | `includes/database.php` | Registro richieste HTTP esterne fallite per retry manuali o monitoraggio. |
| `hic_booking_metrics` | `includes/database.php` | Metriche aggregate per dashboard realtime e analytics interne. |
| `hic_booking_intents` | `src/Repository/BookingIntents.php` | Intenti generati dal redirector `/go/booking` con UTM e identificatori marketing serializzati. |

## Hook rilevanti
- **Filtri**: `hic_should_track_reservation`, `hic_ga4_payload`, `hic_fb_payload`, `hic_brevo_event`, `hic_feature_flags`, `hic_rate_limit_map`, `hic_sid_cookie_args`, `hic_tracking_lookup_cache_ttl`.
- **Action**: `hic_booking_processed`, `hic_enterprise_management_suite_loaded`, `hic_plugin_fresh_install`, `hic_plugin_upgraded`, `hic_automated_reporting_initialized`, `hic_realtime_dashboard_initialized`, `hic_circuit_breaker_opened/closed/half_open`.

## Scheduling e cron
- Eventi WP-Cron registrati da `includes/helpers-scheduling.php` e `includes/booking-poller.php` assicurano esecuzioni periodiche (polling, watchdog, reportistica).
- `Lifecycle::registerNetworkProvisioningHook()` assicura provisioning su nuovi siti multisito tramite `wpmu_new_blog`.
- Il watchdog di cron salva heartbeat in transients (`hic_cron_checked_at`) e rilancia job bloccati.

## Sicurezza e conformità
- `includes/http-security.php` impone limiti di dimensione, header richiesti, verifica IP opzionale e logging.
- Rate limiting centralizzato in `includes/rate-limiter.php` e `includes/api/rate-limit-controller.php`.
- I dati PII (email, telefono) sono hashati con SHA-256 prima dell'invio (vedi `includes/integrations/facebook.php`, `src/Support/UserDataConsent.php`).
- Le capability dedicate (`hic_manage`, `hic_view_logs`, `hic_manage_enterprise`) vengono sincronizzate ad ogni richiesta amministrativa.

## Estensioni consigliate
- Utilizzare i filtri `hic_s2s_ga4_payload`, `hic_s2s_meta_payload` e `hic_brevo_send_event` per integrare dati personalizzati.
- Implementare action `hic_circuit_breaker_opened` per notificare incidenti e `hic_booking_processed` per integrare CRM/ERP esterni.
- Eseguire periodicamente `composer run sync:author` e `composer run sync:docs` per mantenere metadati e documentazione allineati.
