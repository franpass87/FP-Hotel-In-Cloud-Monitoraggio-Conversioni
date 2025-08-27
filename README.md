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

Il sistema effettua polling automatico ogni 5 minuti sull'endpoint:
```
GET /reservations_updates/{propId}?since={timestamp}
```

- **Parametro `since`**: Unix timestamp dell'ultimo aggiornamento processato
- **Autenticazione**: Basic Auth con le stesse credenziali API
- **Frequenza**: Stessa del polling principale (5 minuti)
- **Deduplicazione**: Nessun evento duplicato GA4/Pixel per stessa reservation.id

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