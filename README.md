# FP HIC Monitor

Sincronizza prenotazioni Hotel in Cloud con GA4, Meta e Brevo via webhook e polling sicuro per un tracciamento server-to-server affidabile.

## Plugin information

| Campo | Valore |
| --- | --- |
| Nome | FP HIC Monitor |
| Versione | 3.4.1 |
| Autore | [Francesco Passeri](https://francescopasseri.com) ([info@francescopasseri.com](mailto:info@francescopasseri.com)) |
| Autore URI | https://francescopasseri.com |
| Plugin URI | https://francescopasseri.com |
| Requires at least | WordPress 5.8 |
| Tested up to | WordPress 6.6 |
| Requires PHP | 7.4 |
| Licenza | GPLv2 or later |
| Text Domain | `hotel-in-cloud` (Domain Path: `/languages`) |

## What it does
FP HIC Monitor collega il gestionale Hotel in Cloud con l'ecosistema marketing del tuo sito WordPress, orchestrando webhook autenticati, polling intelligente e invii server-to-server per sincronizzare eventi di prenotazione, rimborsi e intenti marketing verso GA4, Meta/Facebook CAPI e Brevo.

## About
Il plugin nasce per le strutture ricettive che non possono contare su una thank you page nel dominio principale. Combina un endpoint REST protetto, scheduler resilienti e strumenti di diagnostica per garantire che ogni prenotazione venga tracciata, deduplicata e inoltrata alle piattaforme pubblicitarie con i corretti attributi UTM e SID. Funziona in ambienti single e multisito, include provisioning automatico delle capability e mette a disposizione dashboard amministrative per monitorare log, salute dell'integrazione e performance.

## Features
- **Webhook HIC autenticato** con firma HMAC, rate limiting, replay protection e validazione payload (`includes/api/webhook.php`).
- **Polling intelligente** con backoff esponenziale, caching e watchdog per recuperare prenotazioni via API quando il webhook non è disponibile (`includes/intelligent-polling-manager.php`, `includes/booking-poller.php`).
- **Integrazioni server-to-server** verso GA4, Meta/Facebook CAPI e Brevo con normalizzazione degli identificatori marketing, gestione SID e deduplicazione eventi (`includes/integrations/ga4.php`, `includes/integrations/facebook.php`, `includes/integrations/brevo.php`).
- **Suite amministrativa** con impostazioni, log viewer, dashboard realtime, health monitor e strumenti di ottimizzazione database (`includes/admin/admin-settings.php`, `includes/admin/diagnostics.php`, `includes/realtime-dashboard.php`, `includes/performance-analytics-dashboard.php`).
- **Hardening sicurezza e qualità dati** tramite circuit breaker, cache, rate limiter e retention configurabile (`includes/circuit-breaker.php`, `includes/cache-manager.php`, `includes/database.php`).
- **Provisioning multisito** con `Lifecycle` e `ModuleLoader` per assicurare capability e hook su ogni sito (`includes/bootstrap/lifecycle.php`, `includes/bootstrap/module-loader.php`).

## Installation
1. Scarica il pacchetto del plugin o clona la repository in `wp-content/plugins/fp-hic-monitor`.
2. Installa le dipendenze PHP (opzionale per sviluppo) con `composer install`.
3. Attiva **FP HIC Monitor** da **Plugin → Plugin installati**.
4. Accedi a **HIC Monitor → Impostazioni** per configurare token webhook, credenziali GA4/Meta/Brevo e le preferenze del polling.
5. (Facoltativo) Esegui `composer run qa` per assicurarti che il codice rispetti gli standard WPCS quando lavori in sviluppo.

## Usage
### Configurare il webhook
1. In Hotel in Cloud abilita l'invio dei webhook verso `https://example.com/wp-json/hic/v1/conversion?token=<TOKEN>`.
2. Imposta lo stesso token e un secret nella scheda **HIC Webhook & S2S**. Il plugin valida `X-HIC-Signature` e `X-HIC-Timestamp` per ogni richiesta.
3. Usa i pulsanti "Invia finto webhook" o "Ping GA4/Meta" nella pagina impostazioni per verificare la configurazione.

### Attivare il polling intelligente
1. Pianifica la frequenza dalla sezione **Scheduler** nelle impostazioni.
2. Il manager esegue backoff automatico in caso di errori e aggiorna i log accessibili da **HIC Monitor → Registro eventi**.
3. Puoi disabilitare completamente il polling impostando la modalità di tracciamento su `webhook_only`.

### Redirector `/go/booking`
- Abilitando il redirector il plugin genera URL del tipo `/?fp_go_booking=1&target=<BASE64_URL_ENGINE>` che salvano SID, UTM e campagne nella tabella `hic_booking_intents` prima di reindirizzare l'utente all'engine HIC.
- Le prenotazioni collegate riutilizzano l'`intent_id` per attribuire correttamente le campagne negli eventi server-to-server.

### Log e diagnostica
- La pagina **Registro eventi** consente di scaricare log filtrati per canale (`webhook`, `ga4`, `meta`, `error`) con protezione da download non autorizzati.
- L'endpoint `GET /wp-json/hic/v1/health` restituisce stato configurazione, ultime conversioni e controlli di connettività verso GA4/Meta (richiede capability `hic_manage`).

## Hooks & Filters
| Nome | Tipo | Descrizione |
| --- | --- | --- |
| `hic_should_track_reservation` | Filter | Permette di bloccare il tracciamento di una prenotazione in base ai dati disponibili (`includes/booking-processor.php`). |
| `hic_ga4_payload` | Filter | Modifica il payload inviato a GA4 prima della chiamata server-to-server (`includes/integrations/ga4.php`). |
| `hic_fb_payload` | Filter | Personalizza gli eventi Meta/Facebook CAPI (`includes/integrations/facebook.php`). |
| `hic_brevo_event` | Filter | Consente di regolare i dati inviati a Brevo (`includes/integrations/brevo.php`). |
| `hic_feature_flags` | Filter | Aggiunge o modifica i flag di funzionalità caricati all'avvio (`includes/helpers/options.php`). |
| `hic_rate_limit_map` | Filter | Aggiorna le soglie di rate limiting per l'API REST (`includes/api/rate-limit-controller.php`). |
| `hic_booking_processed` | Action | Eseguito dopo il completamento del processo di sincronizzazione prenotazione (`includes/booking-processor.php`). |
| `hic_plugin_upgraded` | Action | Notifica gli upgrade del plugin con versione corrente e precedente (`includes/bootstrap/upgrade-manager.php`). |

Un elenco completo dei filtri e delle action è disponibile in `docs/architecture.md`.

## Support
- Homepage e documentazione: https://francescopasseri.com
- Issue tracker: https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/issues
- Email supporto: [info@francescopasseri.com](mailto:info@francescopasseri.com)

## Changelog
Le modifiche recenti sono documentate in [CHANGELOG.md](CHANGELOG.md) secondo il formato Keep a Changelog e Semantic Versioning.

## Development scripts
| Script | Comando |
| --- | --- |
| Sincronizza metadati autore | `composer run sync:author` |
| Sincronizza documentazione | `composer run sync:docs` |
| Genera changelog da git | `composer run changelog:from-git` |

## Assumptions
- Questo repository è stato validato fino a WordPress 6.6; aggiorna il campo *Tested up to* dopo ogni QA ufficiale.
- L'utilizzo di `conventional-changelog` nei comandi Composer richiede l'installazione globale del pacchetto o un `npx` equivalente nell'ambiente di sviluppo.

Per approfondire consulta anche la documentazione nella cartella `docs/`.
