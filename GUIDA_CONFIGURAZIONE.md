# Guida Rapida di Configurazione

## Come Configurare il Sistema di Polling Automatico

### Passo 1: Configurazione Base Plugin

1. **Accedi a WordPress Admin** → **Impostazioni** → **HIC Monitoring**

2. **Configura Tipo Connessione**:
   ```
   Tipo Connessione: API Polling (raccomandato)
   ```

3. **Inserisci Credenziali Hotel in Cloud**:
   ```
   API Base URL: https://api.hotelincloud.com/api/partner
   Email API: la-tua-email@hotelincloud.com
   Password API: la-tua-password-hic
   ID Struttura (Property ID): 123456
   ```

4. **Configura Intervallo Polling**:
   ```
   Intervallo: Every Two Minutes (raccomandato)
   Sistema Polling Affidabile: ✅ Attivo
   ```

### Passo 2: Configurazione Integrazioni

#### Google Analytics 4
```
Measurement ID: G-XXXXXXXXXX
API Secret: [da GA4 Measurement Protocol]
```

#### Brevo
```
✅ Abilita Brevo
API Key: [la tua chiave API Brevo]
Lista Contatti IT: [ID lista italiana]
Lista Contatti EN: [ID lista inglese]
```

#### Facebook Meta (opzionale)
```
Pixel ID: [il tuo Pixel ID]
Access Token: [token di accesso CAPI]
```

### Passo 3: Test e Verifica

1. **Test Connessione API**:
   - Clicca "Test Connessione API" nelle impostazioni
   - Verifica che restituisca "✅ Connessione riuscita"

2. **Test Funzioni di Invio**:
   - Vai alla scheda "Diagnostics"
   - Clicca "Test Dispatch Funzioni"
   - Verifica che tutti i test siano ✅

3. **Verifica Sistema Polling**:
   - Controlla "Sistema Polling Interno" in Diagnostics
   - Deve mostrare "✅ Attivo" con ultimo polling recente

### Passo 4: Ottimizzazione Performance (Opzionale)

Per **polling ogni minuto** (massime prestazioni):

1. **Disabilita WP-Cron** (in `wp-config.php`):
   ```php
   define('DISABLE_WP_CRON', true);
   ```

2. **Configura Cron di Sistema**:
   ```bash
   # Esegui ogni minuto
   * * * * * wget -q -O - "https://tuosito.com/wp-cron.php" >/dev/null 2>&1
   ```

3. **Aggiorna Intervallo Plugin**:
   ```
   Intervallo: Every Minute
   ```

## Come Verificare che Funzioni

### 1. Crea una Prenotazione di Test
- Vai su Hotel in Cloud
- Crea una prenotazione di test
- Attendi 1-5 minuti (dipende dall'intervallo)

### 2. Controlla i Log
- **WordPress Admin** → **Impostazioni** → **HIC Diagnostics**
- Sezione "Log Recenti"
- Cerca entries tipo:
  ```
  ✅ "poll_completed" 
  ✅ "Prenotazione processata"
  ✅ "GA4 purchase event sent"
  ✅ "Brevo contact sent"
  ```

### 3. Verifica sulle Piattaforme

#### In Google Analytics 4:
- **Rapporti** → **Tempo reale** → **Eventi**
- Cerca evento `purchase` negli ultimi 30 minuti

#### In Brevo:
- **Contatti** → cerca per email della prenotazione
- Verifica che il contatto sia stato creato/aggiornato
- **Automazioni** → controlla se si sono attivate

## Risoluzione Problemi Comuni

### Problema: "Sistema Polling Non Attivo"
**Soluzione**:
1. Verifica credenziali API HIC
2. Controlla che "Sistema Polling Affidabile" sia attivo
3. Verifica Property ID corretto

### Problema: "Connessione API Fallita"
**Soluzione**:
1. Test connessione manuale dalle impostazioni
2. Verifica email/password HIC corretti
3. Controlla che l'account HIC abbia accesso API

### Problema: "Nessun Evento in GA4"
**Soluzione**:
1. Verifica Measurement ID e API Secret corretti
2. Controlla in GA4 "Tempo reale" (non "Rapporti")
3. Verifica che la prenotazione abbia un importo > 0

### Problema: "Contatto Non Creato in Brevo"
**Soluzione**:
1. Verifica API Key Brevo corretta
2. Controlla che le liste contatti esistano
3. Verifica email prenotazione non sia già presente

### Problema: "Backfill Errore 400 / Sistema Non Scarica Niente"
**Soluzione**:
1. Verifica che il tipo connessione sia "API Polling" (non webhook)
2. Testa la connessione API dalle impostazioni HIC
3. Controlla che Property ID, Email e Password API siano corretti
4. Usa la funzione "Test Connessione API" nella sezione diagnostica
5. Verifica nei log che non ci siano errori 401 (credenziali) o 403 (permessi)
6. Se il backfill continua a dare errore 400, verifica il formato delle date (YYYY-MM-DD)
7. Controlla che l'intervallo di date non superi i 6 mesi

## Monitoraggio Continuo

### Log da Monitorare
Controlla periodicamente in **HIC Diagnostics**:
- ✅ "Sistema Polling Interno: Attivo"
- ✅ "Ultimo polling: < 10 minuti fa"
- ✅ Nessun errore nei log recenti

### Metriche Chiave
- **Prenotazioni intercettate**: Tutte le prenotazioni HIC
- **Eventi GA4**: Purchase events in tempo reale
- **Contatti Brevo**: Nuovi contatti/aggiornamenti
- **Attribution**: Bucket gads/fbads/organic corretto

Il sistema, una volta configurato, funziona **automaticamente** e invia ogni prenotazione HIC a GA4 e Brevo entro 1-5 minuti dall'arrivo.