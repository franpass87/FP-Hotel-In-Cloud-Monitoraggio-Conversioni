=== FP HIC Monitor ===
Contributors: francescopasseri
Tags: analytics, ga4, meta conversions api, brevo, hotel booking
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 3.4.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://francescopasseri.com
Author: Francesco Passeri
Author URI: https://francescopasseri.com

== Description ==
Sincronizza prenotazioni Hotel in Cloud con GA4, Meta e Brevo via webhook e polling sicuro per un tracciamento server-to-server affidabile.

FP HIC Monitor offre un flusso di tracciamento completo per le strutture ricettive che usano Hotel in Cloud, combinando webhook autenticati, polling intelligente e validazioni dei payload per mantenere consistenti i dati di marketing.

= Funzionalità principali =
* Endpoint REST autenticato per ricevere webhook di prenotazioni e rimborsi da Hotel in Cloud, con firma HMAC e rate limiting.
* Polling programmato con backoff intelligente come fallback per le installazioni che non possono usare i webhook.
* Invio server-to-server verso GA4, Meta/Facebook CAPI e Brevo con supporto per parametri UTM, SID e deduplicazione eventi.
* Dashboard amministrative per log, diagnostica, salute dell'integrazione e provisioning multisito.
* Sicurezza enterprise con validazione input, circuit breaker, cache e gestione delle retention dati personalizzabile.

== Installation ==
1. Carica la cartella del plugin in `wp-content/plugins` oppure installa il pacchetto ZIP da **Plugin → Aggiungi nuovo**.
2. Attiva **FP HIC Monitor** dal menu **Plugin** di WordPress.
3. Vai su **HIC Monitor → Impostazioni** per configurare token webhook, chiavi GA4/Meta/Brevo e le preferenze di polling.
4. Se desideri usare il redirector `/go/booking`, abilitalo dalla scheda "HIC Webhook & S2S" e aggiorna i link verso l'engine di prenotazione.

== Frequently Asked Questions ==
= Posso usare solo il webhook senza polling? =
Sì. Abilita il webhook nella pagina impostazioni e disattiva il polling intelligente. Il webhook è consigliato per tracciare conversioni in tempo reale quando Hotel in Cloud non permette redirect.

= Serve configurare cron personalizzati? =
No. Il plugin sfrutta WP-Cron e include un watchdog che riavvia automaticamente gli eventi pianificati. Puoi comunque usare cron server esterni per maggiore affidabilità.

= Come gestite i dati personali (PII)? =
Email e telefono vengono hashati con SHA-256 prima di essere inviati alle integrazioni. Puoi disabilitare l'invio dei PII se non hai il consenso esplicito degli utenti.

= Posso personalizzare i payload inviati alle piattaforme marketing? =
Sì. Sono disponibili filtri WordPress (es. `hic_ga4_payload`, `hic_fb_payload`, `hic_brevo_event`) per modificare i dati prima dell'invio.

= È compatibile con installazioni multisito? =
Sì. Durante l'attivazione il plugin sincronizza le capability richieste, registra gli hook di provisioning e offre utility `hic_for_each_site()` per operazioni batch.

== Screenshots ==
Non sono disponibili screenshot in questa distribuzione.

== Changelog ==
Consulta [CHANGELOG.md](CHANGELOG.md) per la cronologia completa in formato Keep a Changelog.

== Upgrade Notice ==
= 3.4.1 =
Aggiornamento consigliato: include miglioramenti alla sicurezza del webhook, ottimizzazioni delle dashboard e nuove utility di logging.

== Support ==
* Homepage e documentazione: https://francescopasseri.com
* Issue tracker: https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/issues
