# FAQ FP HIC Monitor

## Che cosa fa FP HIC Monitor?
Sincronizza prenotazioni Hotel in Cloud con GA4, Meta e Brevo via webhook e polling sicuro per un tracciamento server-to-server affidabile. Normalizza i payload, gestisce SID/UTM e inoltra gli eventi marketing in modo resilienti.

## È obbligatorio usare sia webhook sia polling?
No. Il webhook autenticato garantisce il tracciamento in tempo reale, mentre il polling intelligente funge da fallback. Puoi attivarli singolarmente o in combinazione dalla pagina **HIC Monitor → Impostazioni**.

## Come proteggo l'endpoint webhook?
Configura token e secret nella scheda **HIC Webhook & S2S**. Ogni richiesta deve includere `X-HIC-Timestamp` e `X-HIC-Signature`. Il plugin applica rate limiting, replay protection e sanitizzazione dei payload (`includes/http-security.php`, `includes/api/rate-limit-controller.php`).

## Posso personalizzare i dati inviati a GA4, Meta o Brevo?
Sì. Sono disponibili filtri come `hic_ga4_payload`, `hic_fb_payload`, `hic_brevo_event` e `hic_s2s_meta_payload` per modificare i payload prima dell'invio. Puoi anche usare `hic_should_track_reservation` per bloccare prenotazioni specifiche.

## Come gestisco il consenso degli utenti?
La classe `src/Support/UserDataConsent.php` e gli helper nelle integrazioni applicano hashing SHA-256 ai PII e consentono di disabilitare l'invio se il consenso non è presente. Integra il tuo banner cookie per popolare i flag di consenso utilizzati dal plugin.

## È compatibile con installazioni multisito?
Sì. `Lifecycle::forEachSite()` e `Lifecycle::registerNetworkProvisioningHook()` assicurano che capability e tabelle vengano configurate su ogni sito. Il plugin replica automaticamente i job WP-Cron sui nuovi blog (`wpmu_new_blog`).

## Cosa fa il redirector `/go/booking`?
Genera URL che salvano SID, UTM e identificativi marketing nella tabella `hic_booking_intents` prima di reindirizzare all'engine HIC. In questo modo gli eventi ricevuti via webhook/polling possono riutilizzare gli stessi attributi di campagna per l'attribuzione.

## Come posso monitorare lo stato delle integrazioni?
Utilizza la pagina **Registro eventi** per consultare o scaricare i log, la dashboard realtime per analizzare i KPI e l'endpoint `GET /wp-json/hic/v1/health` (capability `hic_manage`) per verificare stato configurazione e ultime conversioni elaborate.

## Cosa succede se un'integrazione esterna non risponde?
Il circuito di resilienza (`includes/circuit-breaker.php`) apre un breaker dedicato, applica retry controllati e registra l'errore nella tabella `hic_failed_requests`. Puoi agganciarti all'action `hic_circuit_breaker_opened` per inviare alert.

## Come mantengo allineata la documentazione?
Esegui `composer run sync:author` e `composer run sync:docs` dopo ogni modifica ai metadati o alle guide. Gli script invocano `tools/sync-author-metadata.js` per aggiornare automaticamente header, readme e docs.
