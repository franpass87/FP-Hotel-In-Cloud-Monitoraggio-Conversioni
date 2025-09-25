# FP HIC Monitor

> **Versione plugin:** 3.2.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


Plugin WordPress per il monitoraggio avanzato delle conversioni da Hotel in Cloud verso GA4, Facebook Meta e Brevo con sistema di sicurezza enterprise-grade.

## Autore e Supporto

- **Autore:** [Francesco Passeri](https://francescopasseri.com)
- **Email:** [info@francescopasseri.com](mailto:info@francescopasseri.com)
- **Documentazione completa:** consulta le guide in questa repository e il [changelog ufficiale](CHANGELOG.md) per la cronologia delle funzionalità.

## Cronologia Versioni

Le tappe principali dello sviluppo sono riepilogate nel [CHANGELOG.md](CHANGELOG.md). Ogni voce collega le funzionalità alle rispettive implementazioni nel codice per agevolare audit, debug e onboarding.

## Come Funziona in Sintesi

**Quando arriva una prenotazione su Hotel in Cloud**, il plugin:
1. 🔍 **La intercetta automaticamente** (tramite polling API ogni 1-5 minuti)
2. 📊 **La invia a GA4** (evento purchase per analytics)
3. 📧 **La invia a Brevo** (contatto + evento per email marketing)
4. 📱 **La invia a Meta** (evento Purchase per Facebook Ads)
5. ♻️ **Traccia eventuali rimborsi** (evento refund con valore negativo, attivabile dalle impostazioni)

Il tutto avviene **automaticamente** tramite un **sistema interno di scheduling** basato su WP-Cron con meccanismi di watchdog e fallback.

### Modalità di Funzionamento

- **API Polling**: WordPress controlla HIC ogni 1-5 minuti per nuove prenotazioni
- **Webhook**: HIC invia immediatamente le prenotazioni a WordPress (richiede configurazione su HIC). Il payload è limitato a 1 MB (valore predefinito modificabile tramite la costante `HIC_WEBHOOK_MAX_PAYLOAD_SIZE`).
- **Hybrid (Consigliato)**: Combina webhook in tempo reale con API polling di backup per massima affidabilità

#### Esempio payload Webhook

Il webhook `POST /wp-json/hic/v1/conversion?token=IL_TUO_TOKEN` accetta un corpo JSON con i seguenti campi:

```json
{
  "email": "mario.rossi@example.com",
  "reservation_id": "ABC123",
  "guest_first_name": "Mario",
  "guest_last_name": "Rossi",
  "amount": 199.99,
  "currency": "EUR",
  "checkin": "2025-06-01",
  "checkout": "2025-06-07",
  "room": "Camera Deluxe",
  "guests": 2,
  "language": "it",
  "sid": "tracking123"
}
```

Schema campi principali:

- `email` *(stringa, obbligatorio)* – indirizzo email del cliente
- `reservation_id` *(stringa)* – identificativo della prenotazione
- `guest_first_name` *(stringa)* – nome dell'ospite
- `guest_last_name` *(stringa)* – cognome dell'ospite
- `amount` *(numero)* – totale della prenotazione
- `currency` *(stringa)* – valuta dell'importo (es. `EUR`)
- `checkin` *(data Y-m-d)* – data di arrivo
- `checkout` *(data Y-m-d)* – data di partenza
- `room` *(stringa)* – nome della sistemazione
- `guests` *(intero)* – numero di ospiti
- `language` *(stringa)* – lingua dell'utente
- `sid` *(stringa)* – identificatore utente opzionale per il tracciamento

#### Sicurezza del Webhook

- Configura un **Webhook Token** e un **Webhook Secret** dalle impostazioni del plugin. Il token protegge l'URL, mentre il secret viene utilizzato per firmare il payload.
- Ogni chiamata deve includere l'header `X-HIC-Signature` con la firma HMAC-SHA256 del corpo raw (`sha256=<firma_esadecimale>` oppure la versione Base64 della firma).
- Le richieste senza firma o con firma non valida vengono rifiutate con HTTP 401 e registrate nei log di sicurezza del plugin.

#### 🎯 Webhook: La Soluzione per il Tracciamento Senza Redirect

**Problema comune:** Il sistema di prenotazione di Hotel in Cloud non permette redirect al sito dopo la prenotazione, quindi la thank you page rimane nel dominio esterno di HIC.

**✅ Soluzione:** Il **webhook risolve completamente questo problema** perché:
- Traccia le conversioni automaticamente **senza bisogno di redirect**
- Funziona **server-to-server** indipendentemente da dove si trova l'utente  
- Invia **immediatamente** i dati a GA4, Meta e Brevo
- **Non dipende** dal comportamento dell'utente o dal browser

📖 **Guida Completa**: [Setup Webhook per Conversioni Senza Redirect](GUIDA_WEBHOOK_CONVERSIONI.md)

### Caricamento dello script frontend

Il file JavaScript che aggiunge il parametro SID ai link di prenotazione viene caricato solo quando la modalità di tracciamento è impostata su `gtm_only`. Per disabilitarlo è sufficiente selezionare una modalità differente nelle impostazioni del plugin; per riattivarlo ripristinare `gtm_only`.

### Identificatore utente `hic_sid`

Il plugin utilizza un cookie denominato `hic_sid` per collegare le prenotazioni agli utenti. Se presente, questo valore viene inviato come `client_id` e `transaction_id` a GA4 e come `transaction_id` nel dataLayer di GTM, consentendo un tracciamento coerente dell'utente tra le piattaforme.

### Parametri UTM

Quando un visitatore arriva sul sito con parametri UTM nella URL, il plugin salva `utm_source`, `utm_medium`, `utm_campaign`, `utm_content` e `utm_term` nella tabella `hic_gclids`, collegandoli al cookie `hic_sid`. Questi valori vengono poi inclusi automaticamente negli eventi inviati a GA4, Facebook/Meta, Google Tag Manager e Brevo per permettere un'analisi completa delle campagne di marketing.

## Filtri

### `hic_should_track_reservation`

Permette di bloccare l'invio degli eventi per una specifica prenotazione prima che raggiunga le integrazioni esterne.

```php
add_filter('hic_should_track_reservation', function ($should_track, $reservation) {
    if (isset($reservation['email']) && strpos($reservation['email'], 'test@') !== false) {
        return false; // salta la prenotazione
    }
    return $should_track;
}, 10, 2);
```

Nell'esempio sopra vengono escluse dal tracciamento le prenotazioni che contengono `test@` nell'indirizzo email.

📖 **Documentazione Completa**:
- [Come Funziona il Plugin](PLUGIN_FUNZIONAMENTO.md) - Spiegazione dettagliata
- [Architettura Tecnica](ARCHITETTURA_TECNICA.md) - Diagrammi e flussi
- [Guida Configurazione](GUIDA_CONFIGURAZIONE.md) - Setup passo-passo
- [Integrazione Google Tag Manager](GUIDA_GTM_INTEGRAZIONE.md) - GTM vs GA4, prevenzione doppia misurazione
- [Setup Conversioni Enhanced](GUIDA_CONVERSION_ENHANCED.md) - Configurazione Google Ads Enhanced Conversions
- [FAQ - Domande Frequenti](FAQ.md) - Risposte alle domande comuni

## Installazione

1. Dopo aver scaricato il plugin, entrare nella sua directory ed eseguire `composer install` per installare le dipendenze e generare il loader delle classi.
2. Caricare il plugin normalmente in WordPress.
3. In installazioni WordPress Multisite, l'attivazione a livello di rete inizializza le tabelle del database per ogni sito.

### Permessi

Durante l'attivazione il plugin assegna automaticamente le capability `hic_manage` e `hic_view_logs` agli amministratori.
Per concedere queste capability ad altri ruoli è possibile utilizzare un plugin di gestione ruoli oppure aggiungere una semplice funzione personalizzata:

```php
add_action('init', function () {
    if ($role = get_role('editor')) {
        $role->add_cap('hic_manage');
        $role->add_cap('hic_view_logs');
    }
});
```

Il ruolo scelto otterrà così i permessi per configurare il plugin e visualizzare le pagine di amministrazione e i log diagnostici.

## Configurazione API

### API Hotel in Cloud

Il plugin supporta due metodi di autenticazione per le API Hotel in Cloud:

#### Basic Authentication (Raccomandato)
- **API Base URL**: `https://api.hotelincloud.com/api/partner`
- **API Email**: Email del tuo account Hotel in Cloud
- **API Password**: Password del tuo account Hotel in Cloud  
- **ID Struttura (propId)**: ID numerico della tua struttura

#### Configurazione tramite costanti PHP (Opzionale)

Per maggiore sicurezza, puoi definire le credenziali come costanti PHP nel file `wp-config.php`:

```php
define('HIC_API_EMAIL','email@example.com');
define('HIC_API_PASSWORD','***');
define('HIC_PROPERTY_ID', 355787);
```

Le costanti PHP hanno priorità sui valori inseriti nell'interfaccia admin.

### Esecuzione manuale

Per testare l'integrazione API, puoi eseguire manualmente una chiamata:

```php
do_action('hic_fetch_reservations', 355787, 'checkin', '2025-08-01', '2025-08-31', 50);
```

Parametri:
- `propId`: ID della struttura
- `date_type`: Tipo di data (`checkin`, `checkout`) - default: `checkin`
- `from_date`: Data inizio (formato Y-m-d)
- `to_date`: Data fine (formato Y-m-d)  
- `limit`: Numero massimo di risultati (opzionale, 1-200)

### Diagnostici e Test

Il plugin include una pagina di diagnostici completa accessibile da **WordPress Admin > Impostazioni > HIC Monitoring** (scheda "Diagnostics").

#### Funzione Test Dispatch
La funzione "Test Dispatch Funzioni" permette di testare tutte le integrazioni con dati di esempio:

- **GA4**: Invio evento purchase di test
- **Facebook Meta**: Invio evento Purchase di test  
- **Brevo**: Creazione contatto di test
- **Email Admin**: Invio email di notifica all'amministratore
- **Email Francesco**: Invio email a francesco.passeri@gmail.com (se abilitato)

**Nota**: Le email di test vengono inviate agli indirizzi configurati nelle impostazioni del plugin, permettendo di verificare che il sistema di notifiche email funzioni correttamente.

### Endpoint Health Check

Per monitorare lo stato del plugin dall'esterno è disponibile un endpoint pubblico che richiede un token:

- `GET /wp-json/hic/v1/health?token=IL_TUO_TOKEN`
- `GET /wp-admin/admin-ajax.php?action=hic_health_check&token=IL_TUO_TOKEN`

Il token deve corrispondere al valore salvato nell'opzione `hic_health_token`.

#### Livelli diagnostici

È possibile specificare il livello di diagnostica tramite il parametro opzionale `level`:

- `basic` (`HIC_DIAGNOSTIC_BASIC`, predefinito)
- `detailed` (`HIC_DIAGNOSTIC_DETAILED`)
- `full` (`HIC_DIAGNOSTIC_FULL`)

Se viene passato un valore non valido, il plugin esegue automaticamente il livello base.

### Site Health Tests

Nel menu **Strumenti → Salute del sito** il plugin aggiunge due verifiche dedicate:

- **Configurazione GA4** (test diretto): controlla che Measurement ID e API Secret siano impostati. Se mancano uno o entrambi i parametri, lo stato risulta *critico*.
- **Ping Webhook** (test asincrono): invia una richiesta all'endpoint `/wp-json/hic/v1/health` utilizzando il token configurato. Se la risposta non è valida, lo stato viene segnato come *critico*.

Queste verifiche aiutano a individuare rapidamente problemi di configurazione o connettività.

## Esportazione o cancellazione dei dati

Il plugin supporta gli strumenti di privacy nativi di WordPress. Gli utenti possono richiedere l'esportazione o la cancellazione dei dati di tracciamento associati al proprio indirizzo email tramite:

1. **Strumenti → Esporta dati personali**
2. **Strumenti → Cancella dati personali**

L'amministratore del sito approverà la richiesta e il sistema includerà i dati presenti nella tabella `hic_gclids`.

## Cron & CLI

### Hook Cron principali

- `hic_continuous_poll_event` – polling continuo ogni minuto
- `hic_deep_check_event` – verifica approfondita ogni 10 minuti
- `hic_cleanup_event` – pulizia giornaliera degli identificatori
- `hic_booking_events_cleanup` – pulizia giornaliera degli eventi di prenotazione processati
- `hic_fallback_poll_event` – attivato in caso di ritardi o errori
- `hic_health_monitor_event` – controllo periodico dello stato del plugin

### Comandi WP-CLI

```bash
# Esegui un polling manuale
wp hic poll

# Forza il polling ignorando eventuali lock
wp hic poll --force

# Mostra le statistiche del poller
wp hic stats

# Resetta lo stato del poller (richiede conferma)
wp hic reset --confirm

# Visualizza la coda degli eventi
wp hic queue --limit=10 --status=pending

# Esegui le routine di pulizia
wp hic cleanup --logs --gclids --booking-events

# Valida la configurazione del plugin
wp hic validate-config

# Reinvia una prenotazione specifica
wp hic resend 12345

# Reinvia una prenotazione con un SID salvato
wp hic resend 12345 --sid=abc123
```

### Reinvio manuale di una prenotazione

Il comando `wp hic resend` consente di reinviare una singola prenotazione attraverso la normale pipeline di integrazione.
È sufficiente specificare l'ID della prenotazione e, se necessario, il SID salvato nei cookie:

```bash
wp hic resend 12345
wp hic resend 12345 --sid=abc123
```

### Gestione manuale degli eventi

Gli hook possono essere eseguiti o rimossi tramite WP-CLI:

```bash
# Elenco degli eventi programmati
wp cron event list --fields=hook,next_run

# Esecuzione manuale di un evento
wp cron event run hic_continuous_poll_event

# Disattiva un evento
wp cron event delete hic_continuous_poll_event

# Riattiva un evento immediatamente
wp cron event schedule now hic_continuous_poll_event
```

## Hook per sviluppatori

### Prenotazioni

- **Filtro `hic_booking_data`**: consente di modificare i dati grezzi ricevuti dal webhook/API *prima* della normalizzazione. Il secondo parametro fornisce il contesto di tracciamento (SID, gclid, fbclid, ecc.).
- **Filtro `hic_booking_payload`**: nuovo hook dedicato al payload normalizzato inviato alle integrazioni. Riceve lo stesso contesto di tracciamento del filtro precedente come secondo parametro e l'array normalizzato come terzo parametro per eventuali confronti con i dati originari.

I callback esistenti che operavano sul payload normalizzato devono spostarsi su `hic_booking_payload`, mentre `hic_booking_data` continua a occuparsi esclusivamente dei dati grezzi in ingresso.

## Notifiche Email

Il plugin invia automaticamente email di notifica all'amministratore per ogni nuova prenotazione ricevuta.

### Configurazione Email Admin

1. **Vai in:** WordPress Admin → Impostazioni → HIC Monitoring
2. **Sezione:** Impostazioni Generali
3. **Campo:** Email Amministratore
4. **Test:** Usa il pulsante "Test Email" per verificare la configurazione

### Risoluzione Problemi Email

Se le email non arrivano:

1. **Testa la configurazione** con il pulsante Test Email nelle impostazioni
2. **Controlla spam/junk** nella casella di destinazione
3. **Verifica i log** nella sezione Diagnostics per errori dettagliati
4. **Configurazione SMTP** potrebbe essere necessaria (plugin come WP Mail SMTP)
5. **Contatta l'hosting** se i test falliscono (problemi server mail)

Il sistema include diagnostica avanzata per identificare automaticamente problemi comuni di configurazione email.

### Personalizzazione Notifiche Email

Il plugin espone filtri WordPress per modificare oggetto e contenuto delle notifiche inviate all'amministratore:

```php
add_filter( 'hic_admin_email_subject', function ( $subject, $data ) {
    return '[Prenotazione] ' . $subject;
}, 10, 2 );

add_filter( 'hic_admin_email_body', function ( $body, $data ) {
    $body .= "\nFonte: " . ( $data['source'] ?? 'sconosciuta' );
    return $body;
}, 10, 2 );
```

I parametri `$subject` e `$body` rappresentano rispettivamente oggetto e corpo generati dal plugin, mentre `$data` contiene i dettagli della prenotazione.

### Personalizzazione payload GA4

Il filtro `hic_ga4_payload` consente di modificare il payload inviato a Google Analytics 4 prima della codifica JSON. Il filtro riceve il payload generato dal plugin, i dati originali della prenotazione e gli identificatori di tracciamento `gclid` e `fbclid`.

Esempio di aggiunta di un parametro personalizzato:

```php
add_filter( 'hic_ga4_payload', function ( $payload, $data, $gclid, $fbclid ) {
    $payload['events'][0]['params']['coupon'] = 'SUMMER_PROMO';
    return $payload;
}, 10, 4 );
```

Il valore restituito dal filtro verrà inviato a GA4 come parte dell'evento.

### Personalizzazione payload Facebook

Il filtro `hic_fb_payload` consente di modificare il payload inviato a Facebook Meta prima della codifica JSON. Il filtro riceve il payload generato dal plugin, i dati originali della prenotazione e gli identificatori di tracciamento `gclid` e `fbclid`.

Esempio di aggiunta di un parametro personalizzato:

```php
add_filter( 'hic_fb_payload', function ( $payload, $data, $gclid, $fbclid ) {
    $payload['data'][0]['custom_data']['coupon'] = 'SUMMER_PROMO';
    return $payload;
}, 10, 4 );
```

Il valore restituito dal filtro verrà inviato a Meta come parte dell'evento.

### Personalizzazione Log

È possibile modificare i giorni di conservazione dei log tramite il filtro WordPress `hic_log_retention_days`:

```php
add_filter( 'hic_log_retention_days', function ( $days ) {
    return 7; // Conserva i log per 7 giorni invece dei 30 predefiniti
} );
```

L'hook riceve il numero di giorni di retention configurato dal plugin (default 30) e deve restituire il nuovo valore da applicare.

### Livelli Log

Il plugin supporta i seguenti livelli di log selezionabili nella pagina impostazioni:

- `error` – registra solo gli errori critici
- `warning` – include anche gli avvisi
- `info` – aggiunge messaggi informativi (predefinito)
- `debug` – log dettagliati per sviluppo e analisi

Il livello scelto determina quali messaggi vengono scritti nel file di log.

### Personalizzazione cookie SID

Il filtro `hic_sid_cookie_args` permette di modificare i parametri del cookie `hic_sid` (scadenza, path, ecc.). Riceve l'array di argomenti di default e il valore del SID.

```php
add_filter( 'hic_sid_cookie_args', function ( $args, $sid ) {
    $args['secure'] = false; // esempio: invio cookie anche su HTTP
    return $args;
}, 10, 2 );
```

Il filtro deve restituire l'array di argomenti che verrà passato a `setcookie()`.

## Note su Privacy e Rate Limits

- Il plugin rispetta i rate limits delle API Hotel in Cloud
- I dati sensibili vengono loggati in forma ridotta per proteggere la privacy
- Le credenziali API non vengono mai loggate

## Email Enrichment e Gestione Alias

Il plugin include un sistema avanzato di gestione delle email alias per OTA (Online Travel Agencies) come Booking.com, Airbnb, Expedia, etc.

### Funzionalità Email Enrichment

#### Riconoscimento Email Alias
- **Email Alias Supportate**: Booking.com, Airbnb, Expedia e altri OTA
- **Domini Riconosciuti**: `guest.booking.com`, `guest.airbnb.com`, `expedia.com`, etc.
- **Gestione Automatica**: Le email alias vengono riconosciute automaticamente e gestite separatamente

#### Flusso di Enrichment
1. **Prima Importazione con Alias**: 
   - Email alias viene salvata come temporanea
   - Contatto creato in Brevo senza opt-in marketing (se configurato)
   - Assegnazione a lista "alias" dedicata (se configurata)

2. **Aggiornamento con Email Reale**:
   - Sistema polling `/reservations_updates/{propId}` rileva email reale
   - Aggiornamento automatico contatto Brevo con email reale
   - Assegnazione alle liste corrette in base alla lingua
   - Opzionale: invio double opt-in per email reale

#### Configurazione Email Enrichment

Nel pannello admin, sezione "Brevo Settings":

- **Aggiorna contatti da updates**: Abilita il sistema di enrichment (default: ON)
- **Lista alias Brevo**: ID lista per contatti con email alias (lascia vuoto per non iscriverli)
- **Double opt-in quando arriva email reale**: Invia conferma opt-in per email reali (default: OFF)

#### Polling Updates

Il sistema effettua polling automatico con intervalli configurabili (quasi real-time) sull'endpoint:
```
GET /reservations_updates/{propId}?since={timestamp}
```

- **Parametro `since`**: Unix timestamp dell'ultimo aggiornamento processato
- **Autenticazione**: Basic Auth con le stesse credenziali API
- **Frequenza**: Configurabile (1-2 minuti per quasi real-time, 5 minuti per compatibilità)
- **Finestra mobile**: 15 minuti indietro + 5 minuti avanti per evitare perdite
- **Deduplicazione**: Nessun evento duplicato GA4/Pixel per stessa reservation.id
- **Lock anti-overlap**: Previene esecuzioni sovrapposte con transient lock

## Parametro Vertical per Segmentazione

Il plugin include automaticamente il parametro `vertical: 'hotel'` in tutti gli eventi `purchase` inviati a:

- **Google Analytics 4**: Parametro personalizzato nell'evento purchase
- **Meta CAPI**: Parametro nel custom_data dell'evento Purchase  
- **Brevo**: Proprietà dell'evento purchase

### Utilizzo del parametro vertical

Il parametro `vertical` consente di:

1. **Distinguere conversioni hotel da ristorante** in Google Analytics 4
2. **Creare eventi derivati** in GA4 (es. `purchase_hotel` con condizione: event_name = purchase AND vertical = hotel)
3. **Separare campagne pubblicitarie** in Google Ads importando eventi derivati come conversioni distinte
4. **Segmentare audience** in Meta e Brevo per campagne mirate

### Esempio payload eventi

**GA4 Measurement Protocol:**
```json
{
  "client_id": "...",
  "events": [{
    "name": "purchase",
    "params": {
      "transaction_id": "12345",
      "currency": "EUR",
      "value": 150.00,
      "bucket": "organic",
      "vertical": "hotel"
    }
  }]
}
```

**Meta CAPI:**
```json
{
  "data": [{
    "event_name": "Purchase",
    "custom_data": {
      "currency": "EUR", 
      "value": 150.00,
      "bucket": "organic",
      "vertical": "hotel"
    }
  }]
}
```

**Brevo Event:**
```json
{
  "event": "purchase",
  "properties": {
    "amount": 150.00,
    "currency": "EUR", 
    "bucket": "organic",
    "vertical": "hotel"
  }
}
```

### Filtro `hic_ga4_payload`

Il plugin espone il filtro WordPress `hic_ga4_payload` che permette di modificare il payload inviato a Google Analytics 4 prima che venga codificato in JSON.

**Esempio di utilizzo**

```php
add_filter('hic_ga4_payload', function ($payload, $data, $gclid, $fbclid) {
    // Aggiunge un parametro personalizzato all'evento GA4
    $payload['events'][0]['params']['coupon'] = 'OFFERTA123';
    return $payload;
}, 10, 4);
```

Questo consente di adattare il payload GA4 a esigenze specifiche, come l'aggiunta di campi personalizzati o la modifica dei parametri inviati.

## Conversioni Enhanced Google Ads

Il plugin include supporto completo per **Google Ads Enhanced Conversions**, che migliorano significativamente l'accuratezza del tracciamento delle conversioni utilizzando dati first-party hashati in modo sicuro.

### Caratteristiche Conversioni Enhanced

- 🔒 **Privacy-Safe**: Email e dati personali hashati con SHA-256
- 📈 **Migliore ROAS**: Attribution più accurata delle conversioni
- 🎯 **Cross-Device Tracking**: Collega conversioni tra dispositivi diversi
- 🚀 **Machine Learning**: Google Ads può ottimizzare meglio le campagne
- ⚡ **Upload Automatico**: Batch processing con retry automatico

### Setup Rapido

1. **Configura Google Ads API** con Developer Token e Service Account
2. **Abilita Enhanced Conversions** nell'azione di conversione Google Ads
3. **Attiva nel Plugin**: WordPress Admin → HIC Monitoring → Enhanced Conversions
4. **Test**: Usa la funzione di test integrata per verificare il funzionamento

📖 **Guida Completa**: [Setup Conversioni Enhanced](GUIDA_CONVERSION_ENHANCED.md) - Configurazione dettagliata passo-passo

## Bucket Attribution Normalization

Il plugin implementa un sistema di normalizzazione uniforme per il parametro `bucket` che identifica la fonte di attribuzione della conversione. Questa normalizzazione è applicata coerentemente in tutte le integrazioni (GA4, Meta CAPI, Brevo).

### Regole di Normalizzazione

La funzione `fp_normalize_bucket($gclid, $fbclid)` applica la seguente logica di priorità:

**Priority order: gclid > fbclid > organic**

1. **`gads`**: Se è presente un Google Click ID (`gclid`)
2. **`fbads`**: Se è presente un Facebook Click ID (`fbclid`) ma non un `gclid`  
3. **`organic`**: Se non sono presenti né `gclid` né `fbclid`

### Valori Bucket Possibili

- **`gads`**: Traffico da Google Ads (Google Click ID presente)
- **`fbads`**: Traffico da Meta/Facebook Ads (Facebook Click ID presente)
- **`organic`**: Traffico diretto o organico (nessun ID di tracciamento)

### Utilizzo del Bucket

Il parametro `bucket` viene inviato in tutti gli eventi di conversione per:

1. **Segmentazione in GA4**: Creare report per fonte di attribuzione
2. **Custom Conversions in Meta**: Separare conversioni organiche da quelle Meta
3. **Automazioni Brevo**: Trigger diversi in base alla fonte
4. **Analisi Performance**: Confrontare ROI tra canali di acquisizione

### Filtro `hic_log_message`

Il plugin espone il filtro WordPress `hic_log_message` per permettere la
personalizzazione dei messaggi di log. Il filtro riceve il messaggio originale
e il livello di log e deve restituire la stringa che verrà salvata nel file.

Per impostazione predefinita viene applicato l'helper `hic_mask_sensitive_data`
che offusca email, numeri di telefono e token sensibili.

Esempio di utilizzo:

```php
add_filter('hic_log_message', function($message, $level) {
    return strtoupper($message);
}, 10, 2);
```

### Test e Validazione

Il sistema include test completi per tutte le combinazioni:

- **Test Unitari**: Verifica della logica di normalizzazione
- **Test di Integrazione**: Validazione con tutti i canali (GA4, Meta, Brevo)
- **Test Dispatch**: Simulazione booking per ogni scenario

Accedi ai test dalla dashboard admin: **Impostazioni HIC > Diagnostics**

Per eseguire la suite di test da linea di comando:
```bash
composer test
```

Per validare l'integrazione Google Tag Manager:
```bash
php tests/validate-gtm.php
```

Per analizzare lo stile del codice:
```bash
composer lint
```

