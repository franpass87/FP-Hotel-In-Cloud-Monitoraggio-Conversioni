# FP-Hotel-In-Cloud-Monitoraggio-Conversioni

Plugin WordPress per il tracciamento delle conversioni da Hotel in Cloud verso GA4, Facebook Meta e Brevo.

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
- `limit`: Numero massimo di risultati (opzionale)

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

### Sistema Cron per Polling Quasi Real-time

Per ottenere prestazioni ottimali con polling ogni 1-2 minuti, è consigliabile configurare un cron di sistema:

#### 1. Disabilitare WP Cron
Aggiungi al file `wp-config.php`:
```php
define('DISABLE_WP_CRON', true);
```

#### 2. Configurare Cron di Sistema
Esegui `crontab -e` e aggiungi una delle seguenti righe:

**Per polling ogni minuto (massime prestazioni):**
```bash
* * * * * /usr/bin/wget -q -O - "https://yoursite.com/wp-cron.php" >/dev/null 2>&1
```

**Per polling ogni 2 minuti (bilanciato):**
```bash
*/2 * * * * /usr/bin/wget -q -O - "https://yoursite.com/wp-cron.php" >/dev/null 2>&1
```

#### 3. Configurazione Plugin
Nel pannello admin (**Impostazioni > HIC Monitoring**), configura:
- **Tipo Connessione**: API Polling
- **Intervallo Polling**: Every Minute o Every Two Minutes
- Verifica la diagnostica per confermare il funzionamento

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

### Test e Validazione

Il sistema include test completi per tutte le combinazioni:

- **Test Unitari**: Verifica della logica di normalizzazione
- **Test di Integrazione**: Validazione con tutti i canali (GA4, Meta, Brevo)
- **Test Dispatch**: Simulazione booking per ogni scenario

Accedi ai test dalla dashboard admin: **Impostazioni HIC > Diagnostics**