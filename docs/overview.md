# Panoramica FP HIC Monitor

> **Versione**: 3.4.1 · **Ultimo aggiornamento**: 2025-10-07

Sincronizza prenotazioni Hotel in Cloud con GA4, Meta e Brevo via webhook e polling sicuro per un tracciamento server-to-server affidabile.

**Documentazione Completa**: [DOCUMENTAZIONE.md](../DOCUMENTAZIONE.md)

## Obiettivo del plugin
FP HIC Monitor colma il gap tra il gestionale Hotel in Cloud e WordPress quando non è possibile usare una thank you page sul dominio principale. Il plugin riceve prenotazioni e rimborsi tramite webhook autenticati o polling API, li normalizza e li distribuisce ai canali marketing server-to-server mantenendo un audit completo delle operazioni.

## Pillars funzionali
- **Acquisizione eventi**: endpoint REST `/wp-json/hic/v1/conversion` con token e firma HMAC più un polling intelligente programmato per agire da fallback.
- **Normalizzazione e deduplicazione**: mapping dei payload, calcolo del `sid`, gestione UTM, riconciliazione intenti e controllo collisioni tramite le tabelle `hic_booking_events` e `hic_booking_metrics`.
- **Distribuzione omnicanale**: invio automatico verso GA4, Meta/Facebook CAPI e Brevo, con filtri dedicati per personalizzare i payload (`hic_ga4_payload`, `hic_fb_payload`, `hic_brevo_event`).
- **Osservabilità e sicurezza**: log strutturati, dashboard realtime, health monitor, rate limiting, circuit breaker e validazione degli input a livello HTTP.
- **Provisioning continuo**: lifecycle multisito che sincronizza capability, job cron, redirector `/go/booking` e strumenti per la gestione enterprise.

## Componenti principali
- **Bootstrap** (`includes/bootstrap/*.php`): `ModuleLoader`, `Lifecycle` e `UpgradeManager` orchestrano il caricamento dei moduli, l'attivazione multisito e le migrazioni.
- **Integrazioni** (`includes/integrations/*.php`, `src/Services/*`): servizi specifici per GA4, Meta e Brevo con deduplicazione, hashing PII e gestione errori.
- **API & Scheduler** (`includes/api/*.php`, `includes/booking-poller.php`, `includes/intelligent-polling-manager.php`): implementano webhook, rate limiting, polling, code eventi e orchestrazione WP-Cron.
- **Admin Suite** (`includes/admin/*.php`, `includes/performance-analytics-dashboard.php`, `includes/realtime-dashboard.php`): pannelli impostazioni, log viewer, health check, strumenti di ottimizzazione e reportistica.
- **Utility & Sicurezza** (`includes/http-security.php`, `includes/input-validator.php`, `includes/circuit-breaker.php`, `includes/rate-limiter.php`, `includes/cache-manager.php`): garantiscono hardening, caching e resilienza delle chiamate esterne.

## Flusso in sintesi
1. **Acquisizione**: webhook o polling inseriscono i dati nelle tabelle applicative e nella coda `hic_booking_events`.
2. **Validazione**: gli helper verificano struttura, consenso, limiti dimensione e integrità degli identificatori marketing (`hic_sanitize_identifier`).
3. **Enrichment**: vengono recuperati SID, UTM e intenti memorizzati (`hic_gclids`, `hic_booking_intents`) per arricchire il contesto.
4. **Dispatch**: il booking processor invia eventi a GA4, Meta e Brevo sfruttando retry, circuit breaker e caching per ridurre gli errori.
5. **Monitoraggio**: log, dashboard realtime e health monitor offrono visibilità sulle conversioni elaborate e sullo stato degli endpoint.

Per maggiori dettagli tecnici consulta [architecture.md](architecture.md) e [faq.md](faq.md).
