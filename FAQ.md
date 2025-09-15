# FAQ - Domande Frequenti

## 🔥 DOMANDA PRINCIPALE

### Il sistema funziona anche senza Google Ads Enhanced?

**SÌ, ASSOLUTAMENTE!** Il sistema FP HIC Monitor funziona perfettamente anche senza Google Ads Enhanced Conversions.

✅ **Cosa funziona sempre**:
- Google Analytics 4 (GA4) - Tracciamento conversioni completo
- Facebook/Meta CAPI - Advertising su social  
- Brevo - Email marketing e automazioni
- Google Tag Manager - Integrazione client-side
- Email notifiche admin - Avvisi prenotazioni

❌ **Cosa NON serve obbligatoriamente**:
- Google Ads Enhanced Conversions (solo se usi Google Ads)
- Configurazione Google Ads API
- Service Account Google Cloud

**📖 Guida completa**: [SISTEMA_SENZA_ENHANCED.md](SISTEMA_SENZA_ENHANCED.md)

---

## Funzionamento Base

### Q: Come funziona questo plugin quando arriva una prenotazione su HIC?

**R**: Quando arriva una prenotazione su Hotel in Cloud, il plugin:

1. **La intercetta automaticamente** usando il sistema di polling interno (ogni 30 secondi - quasi real-time)
2. **La processa** validando i dati e recuperando i tracking IDs (gclid, fbclid)
3. **La invia simultaneamente** a tutte le piattaforme integrate:
   - ✅ **GA4** → Evento `purchase` per analytics
   - ✅ **Brevo** → Contatto + evento per email marketing  
   - ✅ **Meta/Facebook** → Evento `Purchase` per Facebook Ads (se configurato)

### Q: Funziona tramite un sistema interno di scheduling?

**R**: **Sì, esatto!** Il plugin include un sistema di scheduling interno ottimizzato (`HIC_Booking_Poller`) che:

- ⏰ **Polling continuo ogni 30 secondi** per prenotazioni recenti e manuali (quasi real-time)
- 🚀 **Deep check attivo ogni 30 minuti** per verificare le ultime prenotazioni
- 🔒 **Basato su WP-Cron** con meccanismi di watchdog e fallback
- 🛡️ **Ha protezioni anti-overlap** (lock e watchdog)
- 📋 **Cattura TUTTE le prenotazioni** (online + manuali dello staff)
- 🎯 **È completamente automatico** una volta configurato

### Q: Quanto tempo ci vuole dall'arrivo della prenotazione all'invio?

**R**: 
- **Prenotazioni recenti**: 30-60 secondi (polling continuo ogni 30 secondi - quasi real-time)
- **Prenotazioni manuali**: 30-60 secondi (rilevate dal polling continuo)
- **Copertura totale**: Il polling ogni 30 secondi garantisce che nessuna prenotazione venga persa

Il sistema di **polling ottimizzato è sempre attivo** e fornisce copertura completa con latenza minima.

## Configurazione

### Q: Quale modalità scegliere tra Webhook e API Polling?

**R**: **API Polling è raccomandato** perché:

| Caratteristica | API Polling | Webhook |
|---|---|---|
| Affidabilità | ✅ Molto alta | ⚠️ Dipende da HIC |
| Prenotazioni manuali | ✅ Catturate | ❌ Spesso perse |
| Configurazione | ✅ Solo plugin | ⚠️ Plugin + HIC |
| Latenza | 30-60 secondi | Tempo reale |

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

### Q: Posso usare Google Tag Manager invece di GA4 diretto?

**R**: **Sì!** Il plugin ora supporta tre modalità di tracciamento:

1. **Solo GA4 Measurement Protocol** (Server-side) - Attuale modalità
2. **Solo Google Tag Manager** (Client-side) - Per gestione centralizzata tag
3. **Modalità Ibrida** (GTM + GA4 backup) - Massima copertura

📚 **Guida completa**: [GUIDA_GTM_INTEGRAZIONE.md](GUIDA_GTM_INTEGRAZIONE.md)

**Vantaggi GTM**:
- Gestione centralizzata di tutti i tag (GA4, Meta, LinkedIn, etc.)
- Maggiore flessibilità per trigger personalizzati
- Controllo granulare senza modifiche al codice

**Vantaggi GA4 Diretto**:
- Tracciamento server-side più affidabile
- Non dipende da JavaScript/cookie del browser
- Resistente ad AdBlocker

### Q: Come evito la doppia misurazione con GTM?

**R**: Il plugin previene automaticamente la doppia misurazione attraverso:

1. **Modalità esclusive**: Solo una modalità attiva per volta
2. **Transaction ID univoci**: Ogni conversione ha ID unico
3. **Parametri differenziati**: Eventi marcati con source diversa

Per modalità **Ibrida**, configura in GA4:
- Dimensione personalizzata `event_source`
- Server-side: `"measurement_protocol"`
- Client-side: `"gtm_datalayer"`

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

### Q: Il sistema si riavvia automaticamente se non accedo all'admin per giorni?

**R**: **Sì! Il sistema ha meccanismi di auto-recovery migliorati**:

1. ✅ **Recovery automatico su qualsiasi visita**: Ogni caricamento pagina (frontend o backend) verifica lo stato del polling
2. ✅ **Rilevamento dormancy intelligente**: Se il polling è inattivo per >1 ora, viene riavviato automaticamente  
3. ✅ **Fallback multi-livello**: 
   - Controlli proattivi ogni 5 minuti
   - Fallback su caricamento pagina se polling fermo >30 minuti
   - Recovery completo se sistema dormiente >1 ora
4. ✅ **Non dipende dall'accesso admin**: Funziona con qualsiasi traffico sul sito

**In pratica**: Non è più necessario accedere all'admin per far ripartire il sistema. Qualsiasi visita al sito (anche solo una pagina frontend) riattiva automaticamente il polling se necessario.

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

## Conversioni Enhanced Google Ads

### Q: Cosa sono le Conversioni Enhanced e perché dovrei usarle?

**R**: Le **Conversioni Enhanced** sono una funzionalità avanzata di Google Ads che migliora l'accuratezza del tracciamento utilizzando dati first-party hashati in modo sicuro.

**Vantaggi principali**:
- 📈 **+15-25% ROAS improvement** grazie a attribution più accurata
- 🎯 **Cross-device tracking** - collega conversioni tra desktop/mobile
- 🔒 **Privacy-compliant** - dati email hashati con SHA-256
- 🚀 **Machine Learning migliore** - Google Ads ottimizza meglio le campagne
- 📊 **Riduce data loss** - recupera conversioni altrimenti non tracciabili

### Q: Come posso configurare le Conversioni Enhanced?

**R**: **Setup in 4 passi**:

1. **Google Ads Setup**:
   - Richiedi Developer Token (Centro API)
   - Abilita Enhanced Conversions nell'azione di conversione
   - Crea Service Account con Google Ads API

2. **Plugin Configuration**:
   ```
   WordPress Admin → HIC Monitoring → Enhanced Conversions
   ✅ Enable Google Ads Enhanced Conversions
   ```

3. **Credenziali API**:
   - Upload Service Account JSON
   - Inserisci Customer ID e Conversion Action ID
   - Test connessione API

4. **Validation**:
   - Test con prenotazione di prova
   - Verifica upload in Google Ads
   - Monitor dashboard stats

📖 **Guida Completa**: [GUIDA_CONVERSION_ENHANCED.md](GUIDA_CONVERSION_ENHANCED.md)

### Q: Le Enhanced Conversions funzionano automaticamente?

**R**: **Sì, completamente automatiche** una volta configurate:

- ⚡ **Processing automatico**: Ogni prenotazione con GCLID viene processata
- 🔄 **Batch upload**: Upload automatico ogni ora (configurabile)
- 🛡️ **Retry automatico**: In caso di errori temporanei API
- 📊 **Dashboard monitoring**: Stats in WordPress Admin

**Flusso automatico**:
1. Prenotazione arriva → Email hashata con SHA-256
2. Record creato in queue → Batch processing ogni ora
3. Upload a Google Ads API → Conversione enhanced attiva

### Q: Come faccio a sapere se le Enhanced Conversions funzionano?

**R**: **Monitoring multi-livello**:

**1. Dashboard Plugin**:
```
WordPress Admin → HIC Monitoring → Enhanced Conversions
✅ Conversions processed today: 15
✅ Upload success rate: 98%
✅ Last batch upload: 2 minutes ago
```

**2. Google Ads Console**:
```
Google Ads → Misure → Conversioni → [Tua Azione]
Guarda "Import di conversioni" enhanced
```

**3. Log Diagnostici**:
```
WordPress Admin → HIC Diagnostics
Cerca: "Enhanced conversion processed successfully"
```

### Q: Che differenza c'è tra modalità Batch e Real-time?

**R**: **Modalità disponibili**:

| Modalità | Batch (Raccomandato) | Real-time |
|---|---|---|
| **Upload** | Ogni ora, 100 conversioni | Immediate |
| **Efficienza** | ✅ Alta (meno API calls) | ⚠️ Media |
| **Rate Limiting** | ✅ Automatico | ⚠️ Manuale |
| **Retry** | ✅ Automatico | ⚠️ Manuale |
| **Quando usare** | Produzione | Testing |

**Raccomandazione**: Usa **Batch** in produzione per affidabilità e efficienza.

### Q: Cosa succede se Google Ads API è temporaneamente non disponibile?

**R**: **Sistema di resilienza integrato**:

- 🔄 **Retry automatico**: 3 tentativi con exponential backoff
- 📋 **Queue persistente**: Conversioni salvate nel database
- ⏰ **Scheduling robusto**: Riprocessa automaticamente failed uploads
- 📊 **Monitoring**: Alert automatici per error rate > 5%

**Non perdi mai una conversione** - il sistema riprova fino al successo.

### Q: Le Enhanced Conversions rispettano il GDPR?

**R**: **Sì, completamente GDPR-compliant**:

- 🔒 **Hashing SHA-256**: Email mai inviata in plain text
- 📝 **No PII storage**: Solo hash temporanei per upload
- ⏰ **Data retention**: 90 giorni per compliance
- 🛡️ **Server-side only**: Nessun tracking JavaScript aggiuntivo

**Google non riceve mai l'email in chiaro** - solo hash crittografici sicuri.

### Q: Devo modificare le mie campagne Google Ads esistenti?

**R**: **No, zero modifiche necessarie**:

- ✅ **Retrocompatibilità**: Funziona con tracking esistente
- ✅ **Zero impatto**: Non modifica campagne attive
- ✅ **Enhancement only**: Migliora l'accuratezza esistente
- ✅ **Gradual rollout**: Puoi abilitare progressivamente

Le Enhanced Conversions **si aggiungono** al tracking esistente migliorandolo.