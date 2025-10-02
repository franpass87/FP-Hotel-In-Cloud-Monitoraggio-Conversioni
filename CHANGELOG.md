# Changelog
All notable changes to this project will be documented in this file.

## [Unreleased]

## [3.4.1] - 2025-10-02
### Security
- Centralizzate le verifiche di capability amministrative sugli endpoint di ottimizzazione e suite enterprise tramite l'helper `hic_require_cap()` per richiedere il permesso `hic_manage` in modo coerente.【F:includes/functions.php†L21-L57】【F:includes/database-optimizer.php†L28-L36】【F:includes/enterprise-management-suite.php†L5-L6】
- Introdotta la sanitizzazione rigorosa degli identificatori SQL con `hic_sanitize_identifier()` e applicazione nei processi di indicizzazione, archiviazione e manutenzione del database per evitare injection su nomi di tabelle, colonne e indici dinamici.【F:includes/functions.php†L59-L92】【F:includes/database-optimizer.php†L75-L520】【F:includes/booking-poller.php†L3-L36】
- Rafforzati gli accessi al database nei flussi privacy, tracking e health monitor adottando identificatori sanificati e query preparate in tutte le letture dirette delle tabelle applicative.【F:includes/privacy.php†L1-L120】【F:includes/helpers-tracking.php†L1-L452】【F:includes/health-monitor.php†L1-L360】【F:includes/booking-metrics.php†L1-L200】【F:includes/database.php†L1-L120】
- Rafforzato il download dei log con sanificazione del filename, blocco di symlink/percorso reale fuori directory, streaming a buffer e generazione automatica dei file `.htaccess` e `web.config` per impedire l'accesso diretto.【F:includes/admin/diagnostics.php†L1889-L1996】【F:includes/helpers-logging.php†L9-L118】【F:includes/bootstrap/lifecycle.php†L210-L282】
- Aggiunta la pagina amministrativa "Registro eventi" (permesso `hic_view_logs`) con visualizzazione paginata in sola lettura, verifica automatica della protezione directory e rispetto della rotazione/compressione dei file generati.【F:includes/admin/log-viewer.php†L1-L360】【F:includes/log-manager.php†L320-L408】

### Performance
- L'archiviazione manuale dei dati storici ora è gestita da un job AJAX riprendibile con stato persistito e normalizzato, rate limiting e barra di avanzamento nell'admin: ogni step processa batch controllati (batch dinamici esposti all'interfaccia) e consente di fermarsi/riprendere senza bloccare l'interfaccia.【F:includes/database-optimizer.php†L324-L612】【F:includes/admin/admin-settings.php†L609-L683】【F:assets/js/admin-settings.js†L1-L450】
- Il watchdog del cron verifica lo stato degli eventi al massimo una volta al minuto usando il transient `hic_cron_checked_at` con TTL fisso di 60 secondi, evitando rimbalzi anche durante i riavvii forzati e mantenendo attivo il polling continuo.【F:includes/booking-poller.php†L18-L132】
- Introdotto un sistema di feature flag lazy-load che evita l'istanza dei moduli enterprise, Google Ads Enhanced Conversions e dashboard realtime nelle richieste frontend quando disabilitati o non necessari, riducendo overhead e memoria caricata per ogni pagina.【F:includes/helpers/options.php†L229-L351】【F:includes/bootstrap/module-loader.php†L23-L124】【F:FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php†L139-L193】
- Rivista la strategia di indicizzazione delle tabelle principali aggiungendo gli indici `created_at_idx` e `sid_created_at_idx` per `hic_gclids`, riallineando il Database Optimizer alle interrogazioni reali e documentando il perimetro in `docs/DB-INDEXES.md`.【F:includes/database.php†L88-L108】【F:includes/database-optimizer.php†L94-L126】【F:docs/DB-INDEXES.md†L1-L47】

### Tooling
- Raffinato il workflow GitHub Actions `ci.yml` aggiungendo concurrency control, validazione `composer` preventiva e installazione con cache automatica per mantenere il QA matrix rapido e consistente su PHP 7.4/8.1/8.2, aggiornando il badge nel README.【F:.github/workflows/ci.yml†L1-L51】【F:README.md†L1-L4】

### Localization
- Internazionalizzate le schermate diagnostiche e gli stati delle integrazioni sostituendo stringhe statiche con le funzioni `__()`/`esc_html__()`, aggiornando la terminologia di stato (es. "Completo"/"Incompleto") e allineando i CTA alla localizzazione del plugin.【F:includes/admin/diagnostics.php†L1356-L1735】【F:includes/google-ads-enhanced.php†L1784-L1792】【F:includes/enterprise-management-suite.php†L540-L553】

## [3.4.0] - 2025-09-26
### Security
- Normalizzazione preventiva dei token webhook prima della rate limiting e del confronto costante per evitare bypass delle difese su payload malformati.【F:includes/api/webhook.php†L40-L76】

### Performance
- Cache object-cache per SID, parametri UTM e controlli di esistenza tabelle con invalidazione puntuale all'aggiornamento dei dati, riducendo chiamate ripetitive al database.【F:includes/helpers-tracking.php†L25-L210】

### Compatibility & Architecture
- Nuovi bootstrap `ModuleLoader` e `Lifecycle` che centralizzano l'inclusione dei moduli, l'attivazione multisito e la sincronizzazione delle capability in modo riutilizzabile.【F:includes/bootstrap/module-loader.php†L17-L154】【F:includes/bootstrap/lifecycle.php†L19-L293】
- Helper `hic_for_each_site()` e hook `wpmu_new_blog` per provisioning immediato di nuovi siti in rete, con ripristino sicuro del contesto originale.【F:FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php†L63-L109】
- `UpgradeManager` che registra le versioni installate, rilancia i processi di installazione e svuota cache/object-cache al termine delle migrazioni.【F:includes/bootstrap/upgrade-manager.php†L9-L198】

### Observability & Tooling
- Logger di runtime opzionale per intercettare errori/exception in ambienti di debug, sanificarli e instradarli verso il canale di log con avvisi per gli amministratori.【F:includes/runtime-dev-logger.php†L17-L260】
- Documentazione e audit arricchiti (security, performance, runtime, compatibilità, refactoring, upgrade) per supportare audit futuri e quality gate.【F:docs/audit/security.md†L1-L13】【F:docs/audit/perf.md†L1-L17】

## [3.3.0] - 2025-05-20
### Documentation
- Aggiornati README, guide operative e quick start con intestazioni allineate alla nuova versione 3.3.0 e riferimenti di supporto aggiornati.
- Aggiornato il workflow di rilascio con esempi di tagging e pacchetti coerenti con la versione 3.3.0.

### Maintenance
- Modularizzato il precedente `includes/functions.php` suddividendo le utility in moduli dedicati (`helpers/options.php`, `helpers/strings.php`, `helpers/api.php`, `helpers/booking.php`) e introducendo shim globali deprecati con logging di debug per facilitare la migrazione delle chiamate legacy.【F:includes/helpers/options.php†L1-L279】【F:includes/helpers/strings.php†L1-L294】【F:includes/helpers/api.php†L1-L207】【F:includes/helpers/booking.php†L1-L438】【F:includes/functions.php†L1-L191】
- Sincronizzate costanti di versione, header del plugin e test CLI per riflettere la release 3.3.0 e prevenire falsi positivi nei controlli di health check.

## [3.2.0] - 2025-02-14
### Documentation
- Allineata l'intera documentazione con intestazione unificata, riferimenti all'autore e collegamenti ufficiali di supporto.

## [3.1.0] - 2024-10-01
### Features
- Introdotta la Enterprise Management Suite con riconciliazione dati, setup wizard e health check centralizzati per la governance delle installazioni multi-sito.【F:includes/enterprise-management-suite.php†L7-L176】
- Aggiunta la dashboard in tempo reale con heatmap prenotazioni, aggiornamenti heartbeat e widget amministrativi per i KPI di marketing.【F:includes/realtime-dashboard.php†L7-L160】
- Rilasciato il motore di reportistica automatica con schedulazioni giornaliere/settimanali/mensili, esportazioni e storicizzazione dei report.【F:includes/automated-reporting.php†L7-L200】

## [3.0.0] - 2024-06-18
### Reliability & Performance
- Implementato l'Intelligent Polling Manager con backoff esponenziale, analisi del traffico e pool di connessioni per ottimizzare il recupero prenotazioni.【F:includes/intelligent-polling-manager.php†L7-L200】
- Potenziato il sistema di cache interna per ridurre i tempi di risposta e limitare la pressione sulle API di terze parti.【F:includes/cache-manager.php†L3-L160】
- Aggiunto il circuito di resilienza con circuit breaker, code di retry e rate limiter per proteggere le chiamate verso i servizi esterni.【F:includes/circuit-breaker.php†L7-L200】【F:includes/rate-limiter.php†L10-L160】

## [2.5.0] - 2024-02-12
### Security & Data Quality
- Introdotto il livello di sicurezza HTTP con validazione avanzata, limiti di dimensione e gestione errori per tutte le chiamate remote.【F:includes/http-security.php†L3-L157】
- Estesa la validazione degli input e dei payload webhook per prevenire dati corrotti e richieste sospette.【F:includes/input-validator.php†L406-L498】
- Migliorato il sistema di log con rotazione automatica, livelli e retention configurabile per audit e debug.【F:includes/log-manager.php†L3-L160】

## [2.0.0] - 2023-09-05
### Tracking Platform
- Collegamento diretto con GA4 per fingerprinting delle prenotazioni e deduplicazione degli eventi.【F:includes/integrations/ga4.php†L4-L120】
- Integrazione con Meta/Facebook CAPI per eventi Purchase completi di bucket marketing e parametri UTM.【F:includes/integrations/facebook.php†L4-L160】
- Sincronizzazione con Brevo per contatti, eventi marketing e gestione attributi multilingua.【F:includes/integrations/brevo.php†L4-L160】
- Disponibilità sia di webhook autenticati che di polling API con deduplicazione e gestione dei SID per processare le conversioni senza ridirezionamenti.【F:includes/api/webhook.php†L228-L360】【F:includes/api/polling.php†L920-L1040】
