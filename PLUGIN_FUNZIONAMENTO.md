# Come Funziona FP HIC Monitor

## Panoramica Generale

**FP HIC Monitor** è un plugin WordPress enterprise-grade che monitora le prenotazioni di **Hotel in Cloud (HIC)** e le invia automaticamente a **Google Analytics 4 (GA4)**, **Meta/Facebook** e **Brevo** per il tracciamento delle conversioni e l'automazione del marketing, con sistema di sicurezza avanzato e cache intelligente.

## Flusso Principale: Come Funziona

### 1. Arrivo di una Prenotazione su HIC

Quando arriva una nuova prenotazione su Hotel in Cloud, il plugin può intercettarla in **due modi**:

#### Modalità A: Webhook (Tempo Reale)
- Hotel in Cloud invia immediatamente un webhook a WordPress
- URL webhook: `https://tuosito.com/wp-json/hic/v1/conversion?token=tuotoken`
- **Vantaggio**: Immediato (tempo reale)
- **Svantaggio**: Dipende da HIC per inviare il webhook per TUTTE le prenotazioni

#### Modalità B: API Polling (Raccomandato)
- WordPress controlla autonomamente HIC ogni 1-5 minuti
- Sistema di polling interno **indipendente da WordPress cron**
- **Vantaggio**: Più affidabile, cattura anche prenotazioni manuali
- **Svantaggio**: Leggero ritardo (1-5 minuti)

### 2. Sistema Interno di Scheduling

Il plugin include un **sistema di scheduling interno** (`HIC_Booking_Poller`) che funziona così:

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│  HIC API        │    │  Plugin WordPress │    │  Integrazioni   │
│  (Hotel in      │◄───┤  Polling System   ├───►│  GA4/Meta/Brevo │
│   Cloud)        │    │  (ogni 1-5 min)  │    │                 │
└─────────────────┘    └──────────────────┘    └─────────────────┘
```

**Caratteristiche del Sistema Interno:**
- ⏰ **Frequenza configurabile**: 1, 2 o 5 minuti
- 🔒 **Lock anti-overlap**: Previene esecuzioni sovrapposte
- 🐕 **Watchdog**: Monitora e riavvia automaticamente se necessario
- 📊 **Logging strutturato**: Traccia tutte le operazioni
- 🚫 **Indipendente da WP-Cron**: Funziona anche se WP-Cron è disabilitato

### 3. Elaborazione della Prenotazione

Una volta intercettata la prenotazione, il plugin esegue questi passaggi:

```php
// Funzione principale: hic_process_booking_data($data)

1. Validazione dati (email, campi obbligatori)
2. Recupero tracking IDs (gclid, fbclid) se presente SID
3. Normalizzazione bucket attribution (gads/fbads/organic)
4. Invio parallelo a tutte le integrazioni:
   ├── GA4 (purchase event)
   ├── Meta/Facebook (Purchase event)
   ├── Brevo (contact + event)
   ├── Email admin
   └── Email Francesco (se abilitato)
```

### 4. Invio a GA4 e Brevo

#### Google Analytics 4 (GA4)
```json
{
  "client_id": "client_id_unico",
  "events": [{
    "name": "purchase",
    "params": {
      "transaction_id": "ID_PRENOTAZIONE",
      "currency": "EUR",
      "value": 150.00,
      "bucket": "organic",
      "vertical": "hotel"
    }
  }]
}
```

#### Brevo
1. **Creazione/Aggiornamento Contatto**:
   ```json
   {
     "email": "cliente@email.com",
     "attributes": {
       "NOME": "Mario",
       "COGNOME": "Rossi"
     },
     "listIds": [123]
   }
   ```

2. **Evento Purchase**:
   ```json
   {
     "event": "purchase",
     "email": "cliente@email.com",
     "properties": {
       "reservation_id": "12345",
       "amount": 150.00,
       "currency": "EUR",
       "bucket": "organic",
       "vertical": "hotel"
     }
   }
   ```

## Configurazione del Sistema

### Modalità API Polling (Raccomandato)

1. **Impostazioni Plugin**:
   - Tipo Connessione: `API Polling`
   - API URL: `https://api.hotelincloud.com/api/partner`
   - Email API: La tua email Hotel in Cloud
   - Password API: La tua password Hotel in Cloud
   - ID Struttura: Il tuo Property ID

2. **Intervallo Polling**:
   - `Every Minute` (1 min) - Massime prestazioni
   - `Every Two Minutes` (2 min) - Bilanciato
   - `Every Five Minutes` (5 min) - Compatibilità

### Integrazione GA4
- **Measurement ID**: Da Google Analytics 4
- **API Secret**: Da GA4 Measurement Protocol

### Integrazione Brevo
- **API Key**: Da account Brevo
- **Liste Contatti**: ID liste per italiano/inglese
- **Eventi Purchase**: Automatici per conversioni

## Funzionalità Avanzate

### 1. Bucket Attribution
Il plugin traccia automaticamente la fonte della prenotazione:
- `gads` - Da Google Ads (se presente gclid)
- `fbads` - Da Facebook Ads (se presente fbclid)  
- `organic` - Traffico diretto/organico

### 2. Email Enrichment
Sistema per gestire email alias da OTA (Booking.com, Airbnb, etc.):
- Prima prenotazione con email alias (es. `guest@booking.com`)
- Polling updates rileva email reale del cliente
- Aggiornamento automatico contatto Brevo con email vera

### 3. Diagnostici e Monitoraggio
Pannello admin completo per:
- Test connessione API
- Verifica integrazioni
- Download dati prenotazioni
- Log dettagliati operazioni

## Risposte alle Tue Domande

> **"Appena arriva una prenotazione su HIC lui deve inviare a GA4 e Brevo corretto?"**

✅ **Sì, esatto!** Il plugin invia automaticamente ogni prenotazione a:
- GA4 (evento purchase)
- Brevo (contatto + evento)
- Meta/Facebook (se configurato)

> **"Questo tramite un sistema interno di scheduling?"**

✅ **Corretto!** Il plugin ha un **sistema di scheduling interno** che:
- Controlla HIC ogni 1-5 minuti per nuove prenotazioni
- Non dipende da WordPress cron
- È più affidabile del webhook
- Cattura anche prenotazioni inserite manualmente in HIC

## Vantaggi del Sistema

1. **Affidabilità**: Sistema interno indipendente da WordPress
2. **Completezza**: Cattura tutte le prenotazioni (anche manuali)
3. **Multi-canale**: Invia contemporaneamente a GA4, Meta e Brevo
4. **Attribution**: Traccia fonte della conversione (Google Ads, Facebook, organico)
5. **Monitoraggio**: Diagnostici completi e logging strutturato

Il plugin è quindi una soluzione completa per tracciare automaticamente tutte le conversioni di Hotel in Cloud verso le principali piattaforme di analytics e marketing.