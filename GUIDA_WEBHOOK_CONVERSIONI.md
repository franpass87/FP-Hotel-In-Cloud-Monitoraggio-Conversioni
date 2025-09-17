# Guida Webhook per Tracciamento Conversioni Senza Redirect

## Il Problema: Nessun Redirect dal Sistema di Prenotazione

Quando un cliente completa una prenotazione su Hotel in Cloud (HIC), la **thank you page rimane nel dominio esterno di HIC** e **non viene effettuato alcun redirect verso il sito WordPress**. Questo crea un problema per il tracciamento delle conversioni perché:

- ❌ Non possiamo inserire pixel di tracciamento nella thank you page di HIC
- ❌ Non possiamo tracciare la conversione con JavaScript sul nostro sito
- ❌ I sistemi di analytics tradizionali non rilevano la conversione

## La Soluzione: Webhook di Hotel in Cloud

✅ **Il webhook di Hotel in Cloud risolve completamente questo problema!**

Il sistema funziona in questo modo:

```
1. Cliente completa prenotazione su HIC
   ↓
2. HIC invia IMMEDIATAMENTE webhook al tuo WordPress
   ↓
3. WordPress riceve dati prenotazione e traccia conversione
   ↓
4. Invio automatico a GA4, Meta, Brevo (tutti configurati)
```

### Vantaggi del Webhook vs Redirect

| Aspetto | Webhook | Redirect Tradizionale |
|---------|---------|----------------------|
| **Affidabilità** | ✅ 100% - Server-to-server | ❌ Dipende dal browser utente |
| **Tracciamento** | ✅ Immediato e automatico | ❌ Richiede JavaScript e cookies |
| **Sicurezza** | ✅ Token di autenticazione | ❌ Può essere bypassato |
| **Dati** | ✅ Payload completo con tutti i dettagli | ❌ Solo parametri URL |
| **Affidabilità** | ✅ Non dipende dal comportamento utente | ❌ Utente può chiudere browser |

## Come Configurare il Webhook

### 1. Configurazione in WordPress

1. **Accedi a:** WordPress Admin → Impostazioni → HIC Monitoring
2. **Imposta Modalità:** 
   - `Webhook` per solo webhook
   - `Hybrid` per webhook + API polling (CONSIGLIATO per massima affidabilità)
3. **Configura Token:** Inserisci un token sicuro (es. `hic2025ga4_TUOSITO`)
4. **Se modalità Hybrid:** Configura anche credenziali API (URL, email, password, property ID)
5. **Salva configurazione**

### 2. URL Webhook per Hotel in Cloud

Il tuo URL webhook sarà:
```
https://tuosito.com/wp-json/hic/v1/conversion?token=IL_TUO_TOKEN
```

**Esempio concreto:**
```
https://www.villadianella.it/wp-json/hic/v1/conversion?token=hic2025ga4
```

### 3. Configurazione in Hotel in Cloud

**Chiedi al supporto di Hotel in Cloud di configurare il webhook con:**

- **URL:** `https://tuosito.com/wp-json/hic/v1/conversion?token=IL_TUO_TOKEN`
- **Metodo:** `POST`
- **Content-Type:** `application/json`
- **Trigger:** Su ogni nuova prenotazione confermata

## Payload Webhook

Quando arriva una prenotazione, HIC invierà automaticamente questi dati:

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

### Campi Essenziali

- **`email`** *(obbligatorio)* - Email del cliente
- **`amount`** - Valore della prenotazione per GA4
- **`reservation_id`** - ID unico per evitare duplicati
- **`sid`** - Session ID per tracciamento cross-device (se presente)

## Cosa Succede Automaticamente

Quando il webhook riceve una prenotazione, il plugin **automaticamente**:

### 📊 Google Analytics 4
```json
{
  "client_id": "client_id_unico",
  "events": [{
    "name": "purchase",
    "params": {
      "transaction_id": "ABC123",
      "currency": "EUR", 
      "value": 199.99,
      "bucket": "organic",
      "vertical": "hotel"
    }
  }]
}
```

### 📱 Meta/Facebook
```json
{
  "data": [{
    "event_name": "Purchase",
    "custom_data": {
      "currency": "EUR",
      "value": 199.99,
      "bucket": "organic", 
      "vertical": "hotel"
    }
  }]
}
```

### 📧 Brevo
```json
{
  "event": "purchase",
  "email": "mario.rossi@example.com",
  "properties": {
    "reservation_id": "ABC123",
    "amount": 199.99,
    "currency": "EUR",
    "bucket": "organic",
    "vertical": "hotel"
  }
}
```

## Test e Verifica

### 1. Test Immediato
Dopo la configurazione, testa l'endpoint:

```bash
curl -X POST "https://tuosito.com/wp-json/hic/v1/conversion?token=IL_TUO_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "reservation_id": "TEST123", 
    "amount": 100.00,
    "currency": "EUR"
  }'
```

**Risposta attesa:**
```json
{
  "status": "ok",
  "processed": true
}
```

### 2. Monitoraggio Log

Controlla i log in **WordPress Admin → HIC Monitoring → Diagnostics** per:
- ✅ Webhook ricevuti correttamente
- ✅ Invii a GA4/Meta/Brevo riusciti
- ❌ Eventuali errori di processing

### 3. Verifica in GA4

1. Vai su **Google Analytics → Eventi → Conversioni**
2. Cerca eventi `purchase` con parametro `vertical: hotel`
3. Verifica che `transaction_id` corrisponda a `reservation_id`

## Vantaggi del Sistema Webhook

### 🚀 Prestazioni
- **Tempo reale:** Conversioni trackate immediatamente 
- **Nessun impatto:** Zero impatto sul sito web (server-to-server)
- **Affidabile:** Non dipende da JavaScript o cookies utente

### 🔒 Sicurezza  
- **Token autenticazione:** Webhook protetto da token segreto
- **Validazione payload:** Controlli automatici su dati ricevuti
- **Rate limiting:** Protezione contro spam/abuso

### 📈 Tracciamento Completo
- **Multi-platform:** GA4 + Meta + Brevo simultaneamente
- **Attribution completa:** Tracking source/medium/campaign se disponibili
- **Deduplicazione:** Previene conversioni duplicate
- **Cross-device:** Collegamento tramite email cliente

## Risoluzione Problemi

### Webhook Non Ricevuto
1. **Controlla URL:** Verifica che l'URL sia accessibile pubblicamente
2. **Verifica Token:** Assicurati che il token sia corretto
3. **Controlla Firewall:** Il server deve accettare richieste POST da HIC
4. **Log di Errore:** Controlla i log del server web per errori 500/403

### Conversioni Non Trackate  
1. **Verifica Credenziali:** GA4 Measurement ID e API Secret corretti
2. **Test Manuale:** Usa la funzione "Test Dispatch" nel pannello admin
3. **Controlla Log:** Verifica errori specifici nei log del plugin
4. **Payload Validation:** Assicurati che email sia valida nel payload

### Conversioni Duplicate
Il plugin previene automaticamente duplicati tramite:
- Cache `reservation_id` per 24 ore 
- Lock transactionali durante processing
- Timestamp ultimo processing per debugging

## Confronto: Webhook vs API Polling vs Hybrid

| Caratteristica | Webhook | API Polling | Hybrid |
|---------------|---------|-------------|---------|
| **Velocità** | ⚡ Immediato | 🐌 1-5 minuti | ⚡ Immediato + backup |
| **Affidabilità** | 🔧 Dipende da HIC | ✅ Controllo completo | ✅ Doppia sicurezza |
| **Setup** | 🔧 Richiede configurazione HIC | ✅ Solo WordPress | 🔧 Entrambi |
| **Manutenzione** | 🔧 Dipende da HIC | ✅ Autogestito | ✅ Resiliente |
| **Copertura** | 🔧 Solo nuove prenotazioni | ✅ Anche modifiche manuali | ✅ Completa |
| **Ridondanza** | ❌ Nessuna | ❌ Nessuna | ✅ Webhook + API |

## Conclusione

✅ **Il webhook risolve PERFETTAMENTE il problema del mancato redirect!**

**Perché il webhook è la soluzione ideale:**

1. 🎯 **Tracciamento Garantito** - Ogni conversione viene tracciata automaticamente
2. ⚡ **Tempo Reale** - Nessun ritardo nel tracciamento 
3. 🔒 **Affidabile** - Non dipende dal comportamento dell'utente
4. 📊 **Completo** - Invio automatico a GA4, Meta e Brevo
5. 🛡️ **Sicuro** - Autenticazione con token e validazione payload

**Il fatto che l'utente rimanga sulla thank you page di HIC non è più un problema** perché il tracciamento avviene automaticamente server-to-server tramite webhook, indipendentemente da dove si trova l'utente.