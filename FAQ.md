# FAQ - Domande Frequenti

## Funzionamento Base

### Q: Come funziona questo plugin quando arriva una prenotazione su HIC?

**R**: Quando arriva una prenotazione su Hotel in Cloud, il plugin:

1. **La intercetta automaticamente** usando il sistema di polling interno (ogni 1-5 minuti)
2. **La processa** validando i dati e recuperando i tracking IDs (gclid, fbclid)
3. **La invia simultaneamente** a tutte le piattaforme integrate:
   - ✅ **GA4** → Evento `purchase` per analytics
   - ✅ **Brevo** → Contatto + evento per email marketing  
   - ✅ **Meta/Facebook** → Evento `Purchase` per Facebook Ads (se configurato)

### Q: Funziona tramite un sistema interno di scheduling?

**R**: **Sì, esatto!** Il plugin include un sistema di scheduling interno dual-mode (`HIC_Booking_Poller`) che:

- ⏰ **Polling continuo ogni minuto** per prenotazioni recenti e manuali
- 🔍 **Deep check ogni 10 minuti** con lookback di 5 giorni per recuperare prenotazioni perse
- 🔒 **Non dipende da WordPress cron** (più affidabile)
- 🛡️ **Ha protezioni anti-overlap** (lock e watchdog)
- 📋 **Cattura TUTTE le prenotazioni** (online + manuali dello staff)
- 🚀 **È completamente automatico** una volta configurato

### Q: Quanto tempo ci vuole dall'arrivo della prenotazione all'invio?

**R**: 
- **Prenotazioni recenti**: 1-2 minuti (polling continuo ogni minuto)
- **Prenotazioni manuali**: 1-2 minuti (rilevate dal polling continuo)
- **Controllo di sicurezza**: Ogni 10 minuti il sistema fa un deep check degli ultimi 5 giorni

Il sistema **dual-mode è sempre attivo** e garantisce che nessuna prenotazione venga persa.

## Configurazione

### Q: Quale modalità scegliere tra Webhook e API Polling?

**R**: **API Polling è raccomandato** perché:

| Caratteristica | API Polling | Webhook |
|---|---|---|
| Affidabilità | ✅ Molto alta | ⚠️ Dipende da HIC |
| Prenotazioni manuali | ✅ Catturate | ❌ Spesso perse |
| Configurazione | ✅ Solo plugin | ⚠️ Plugin + HIC |
| Latenza | 1-5 minuti | Tempo reale |

### Q: Come faccio a sapere se funziona?

**R**: Controlla in **WordPress Admin → Impostazioni → HIC Diagnostics**:

1. **Sistema Polling**: Deve essere "✅ Attivo"
2. **Ultimo polling**: Deve essere recente (< 10 minuti)
3. **Log**: Cerca entries come:
   ```
   ✅ poll_completed
   ✅ Prenotazione processata  
   ✅ GA4 purchase event sent
   ✅ Brevo contact sent
   ```

### Q: Come posso testare se tutto funziona?

**R**: Due modi:

1. **Test Automatico**: 
   - Vai in **HIC Diagnostics**
   - Clicca **"Test Dispatch Funzioni"**
   - Verifica che tutti i test siano ✅

2. **Test Reale**:
   - Crea una prenotazione di test in HIC
   - Attendi 1-5 minuti
   - Controlla GA4 "Tempo reale" per evento `purchase`
   - Controlla Brevo per nuovo contatto

## Integrazione GA4

### Q: Quali eventi vengono inviati a GA4?

**R**: Viene inviato un evento **`purchase`** con questi parametri:

```json
{
  "name": "purchase",
  "params": {
    "transaction_id": "ID_PRENOTAZIONE_HIC",
    "currency": "EUR",
    "value": 150.00,
    "bucket": "organic",
    "vertical": "hotel"
  }
}
```

- **`bucket`**: Identifica la fonte (gads/fbads/organic)
- **`vertical`**: Sempre "hotel" per distinguere da altre attività

### Q: Come vedo i dati in GA4?

**R**: 
- **Tempo reale**: **Rapporti → Tempo reale → Eventi** (cerca `purchase`)
- **Conversioni**: **Rapporti → Acquisizione → Ecommerce**
- **Esplorazioni**: Crea report personalizzati usando parametri `bucket` e `vertical`

## Integrazione Brevo

### Q: Cosa viene creato in Brevo?

**R**: Per ogni prenotazione vengono create due cose:

1. **Contatto**:
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
     "properties": {
       "reservation_id": "12345",
       "amount": 150.00,
       "bucket": "organic"
     }
   }
   ```

3. **Evento Real-time** (per nuove prenotazioni):
   ```json
   {
     "event": "reservation_created",
     "email": "cliente@email.com", 
     "properties": {
       "reservation_id": "12345",
       "amount": 150.00,
       "bucket": "organic",
       "from_date": "2024-01-01",
       "to_date": "2024-01-03"
     }
   }
   ```

### Q: Come vengono gestite le email alias da OTA (Booking.com, etc.)?

**R**: Il plugin ha un sistema di **Email Enrichment**:

1. **Prima prenotazione** con email alias (es. `guest@booking.com`):
   - Contatto creato in lista "alias" 
   - NO opt-in automatico

2. **Aggiornamento successivo** quando HIC riceve email reale:
   - Email contatto aggiornata con quella vera
   - Spostamento in liste corrette (IT/EN)
   - Opt-in opzionale se configurato

## Risoluzione Problemi

### Q: "Sistema Polling Non Attivo" - come risolvo?

**R**: Controlla in ordine:

1. ✅ **Credenziali API HIC** corrette (email, password, Property ID)
2. ✅ **"Sistema Polling Affidabile"** attivo nelle impostazioni  
3. ✅ **Tipo Connessione** impostato su "API Polling"
4. ✅ **Test Connessione API** funzionante

### Q: Non vedo eventi in GA4, cosa controllo?

**R**: Verifica in ordine:

1. ✅ **Measurement ID** e **API Secret** corretti
2. ✅ **GA4 Tempo reale** (non "Rapporti" che ha ritardo)
3. ✅ **Prenotazione ha importo** > 0
4. ✅ **Log plugin** per errori GA4

### Q: Posso usare entrambe le modalità insieme?

**R**: **No, scegli una modalità**:
- Se usi **API Polling**: disabilita webhook
- Se usi **Webhook**: disabilita polling

Usare entrambe creerebbe eventi duplicati.

### Q: Il sistema funziona anche se WordPress è lento/offline?

**R**: 
- **Modalità Polling**: No, WordPress deve essere funzionante
- **Modalità Webhook**: No, richiede WordPress raggiungibile

Per massima affidabilità:
1. Usa hosting WordPress stabile
2. Configura **polling ogni 2-5 minuti** (non ogni minuto se hosting lento)
3. Monitora i log regolarmente

## Supporto Tecnico

### Q: Dove trovo i log per il supporto?

**R**: **WordPress Admin → Impostazioni → HIC Diagnostics → Log Recenti**

Condividi gli ultimi log che includono:
- Timestamp delle operazioni
- Eventuali errori
- Codici di risposta HTTP

### Q: Quali informazioni servono per il supporto?

**R**: 
1. **Versione plugin** (vedi header file principale)
2. **Modalità configurata** (API Polling / Webhook)
3. **Log recenti** (ultimi 50 entries)
4. **Configurazioni integrate** (GA4/Brevo/Meta sì/no)
5. **Descrizione problema** specifico