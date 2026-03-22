=== FP HIC Monitor ===
Contributors: francescopasseri
Tags: brevo, hotel booking, webhook, marketing automation
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 3.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Plugin URI: https://francescopasseri.com
Author: Francesco Passeri
Author URI: https://francescopasseri.com

== Description ==
Plugin minimale per sincronizzare nuove prenotazioni Hotel in Cloud con Brevo.

Riceve payload webhook, normalizza i dati cliente/prenotazione e invia contatto + evento a Brevo.

= Funzionalità principali =
* Webhook autenticato `POST /wp-json/hic/v1/conversion?token=<TOKEN>`
* Sync contatto Brevo (`/v3/contacts`)
* Sync evento Brevo (`/v3/events` o endpoint legacy configurabile)
* Eventi `fp_tracking_event` compatibili con layer FP Tracking
* Test connessione Brevo live in admin con storico ultimi 20 test
* Pannello "Ultimo payload HIC ricevuto" (dati mascherati)

== Installation ==
1. Carica la cartella del plugin in `wp-content/plugins` oppure installa il pacchetto ZIP da **Plugin → Aggiungi nuovo**.
2. Attiva **FP HIC Monitor** dal menu **Plugin** di WordPress.
3. Vai su **Impostazioni → FP HIC → Brevo**.
4. Configura token webhook e credenziali Brevo.

== Frequently Asked Questions ==
= Il plugin invia email da solo? =
No. Invia dati e eventi a Brevo: le email (prenotazione/arrivo/partenza) si configurano nelle automazioni Brevo.

= Come gestite i dati personali (PII)? =
I dati nel pannello debug vengono mascherati (email, telefono, nome/cognome). I dati necessari al sync vengono inviati solo alle API Brevo.

= Come verifico se HIC sta inviando i campi giusti? =
Usa la sezione admin "Ultimo payload HIC ricevuto (mascherato)" per confrontare payload raw e payload normalizzato.

= C'è un test live della configurazione Brevo? =
Sì. Pulsante "Test connessione Brevo" con storico ultimi 20 test.

== Screenshots ==
Non sono disponibili screenshot in questa distribuzione.

== Changelog ==

= 3.6.0 =
* Added: plugin semplificato solo HIC -> Brevo
* Added: validazione live Brevo con storico ultimi 20 test
* Added: pannello ultimo payload HIC ricevuto (mascherato)
* Changed: allineati eventi `fp_tracking_event` (booking_confirmed/purchase + legacy)

Consulta [CHANGELOG.md](CHANGELOG.md) per la cronologia completa in formato Keep a Changelog.

== Upgrade Notice ==
= 3.6.0 =
Release consigliata: architettura semplificata, focus Brevo e strumenti di validazione live.

== Support ==
* Homepage e documentazione: https://francescopasseri.com
* Issue tracker: https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/issues
