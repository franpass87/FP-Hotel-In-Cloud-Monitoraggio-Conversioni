# Changelog

> **Versione plugin:** 3.3.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)

Tutte le modifiche degne di nota del plugin FP HIC Monitor sono documentate qui, in ordine cronologico inverso.

## [3.3.0] - 2025-05-20
### Documentazione
- Aggiornati README, guide operative e quick start con intestazioni allineate alla nuova versione 3.3.0 e riferimenti di supporto aggiornati.
- Aggiornato il workflow di rilascio con esempi di tagging e pacchetti coerenti con la versione 3.3.0.

### Manutenzione
- Sincronizzate costanti di versione, header del plugin e test CLI per riflettere la release 3.3.0 e prevenire falsi positivi nei controlli di health check.

## [3.2.0] - 2025-02-14
### Documentazione
- Allineata l'intera documentazione con intestazione unificata, riferimenti all'autore e collegamenti ufficiali di supporto.

## [3.1.0] - 2024-10-01
### Funzionalità
- Introdotta la Enterprise Management Suite con riconciliazione dati, setup wizard e health check centralizzati per la governance delle installazioni multi-sito.【F:includes/enterprise-management-suite.php†L7-L176】
- Aggiunta la dashboard in tempo reale con heatmap prenotazioni, aggiornamenti heartbeat e widget amministrativi per i KPI di marketing.【F:includes/realtime-dashboard.php†L7-L160】
- Rilasciato il motore di reportistica automatica con schedulazioni giornaliere/settimanali/mensili, esportazioni e storicizzazione dei report.【F:includes/automated-reporting.php†L7-L200】

## [3.0.0] - 2024-06-18
### Affidabilità e Performance
- Implementato l'Intelligent Polling Manager con backoff esponenziale, analisi del traffico e pool di connessioni per ottimizzare il recupero prenotazioni.【F:includes/intelligent-polling-manager.php†L7-L200】
- Potenziato il sistema di cache interna per ridurre i tempi di risposta e limitare la pressione sulle API di terze parti.【F:includes/cache-manager.php†L3-L160】
- Aggiunto il circuito di resilienza con circuit breaker, code di retry e rate limiter per proteggere le chiamate verso i servizi esterni.【F:includes/circuit-breaker.php†L7-L200】【F:includes/rate-limiter.php†L10-L160】

## [2.5.0] - 2024-02-12
### Sicurezza e Qualità Dati
- Introdotto il livello di sicurezza HTTP con validazione avanzata, limiti di dimensione e gestione errori per tutte le chiamate remote.【F:includes/http-security.php†L3-L157】
- Estesa la validazione degli input e dei payload webhook per prevenire dati corrotti e richieste sospette.【F:includes/input-validator.php†L406-L498】
- Migliorato il sistema di log con rotazione automatica, livelli e retention configurabile per audit e debug.【F:includes/log-manager.php†L3-L160】

## [2.0.0] - 2023-09-05
### Piattaforma di Tracciamento
- Collegamento diretto con GA4 per fingerprinting delle prenotazioni e deduplicazione degli eventi.【F:includes/integrations/ga4.php†L4-L120】
- Integrazione con Meta/Facebook CAPI per eventi Purchase completi di bucket marketing e parametri UTM.【F:includes/integrations/facebook.php†L4-L160】
- Sincronizzazione con Brevo per contatti, eventi marketing e gestione attributi multilingua.【F:includes/integrations/brevo.php†L4-L160】
- Disponibilità sia di webhook autenticati che di polling API con deduplicazione e gestione dei SID per processare le conversioni senza ridirezionamenti.【F:includes/api/webhook.php†L228-L360】【F:includes/api/polling.php†L920-L1040】
