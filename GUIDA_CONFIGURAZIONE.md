# Guida Rapida di Configurazione

> **Versione plugin:** 3.3.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


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
   - Deve mostrare "✅ Attivo" con dual-mode: polling continuo + deep check
   - Verifica che sia il polling continuo che il deep check abbiano timestamp recenti

### Passo 4: Sistema Ottimizzato (Automatico)

Il nuovo sistema è **già ottimizzato** e non richiede configurazioni aggiuntive:

- ✅ **Polling Continuo**: Ogni minuto controlla prenotazioni recenti e manuali
- ✅ **Deep Check**: Ogni 30 minuti controlla le ultime 5 prenotazioni per verificare che nulla venga perso  
- ✅ **Basato su WP-Cron** con watchdog e fallback per maggiore affidabilità
- ✅ **Cattura prenotazioni manuali**: Include automaticamente le prenotazioni inserite manualmente dallo staff

**Non sono più necessarie** le configurazioni cron esterne!

## Come Verificare che Funzioni

### 1. Crea una Prenotazione di Test
- Vai su Hotel in Cloud
- Crea una prenotazione di test (online o manuale)
- Attendi 1-2 minuti (polling continuo ogni minuto)

### 2. Controlla i Log
- **WordPress Admin** → **Impostazioni** → **HIC Diagnostics**
- Sezione "Log Recenti"
- Cerca entries tipo:
  ```
  ✅ "Continuous Polling: Completed" 
  ✅ "Deep Check: Completed"
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
4. Il sistema dual-mode dovrebbe mostrarsi attivo in Diagnostics

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

### Problema: "Email Notifiche Non Arrivano"
**Soluzione**:
1. **Test Email**: Usa il pulsante "Test Email" nelle impostazioni HIC per verificare la configurazione
2. **Controlla Spam**: Verifica cartella spam/junk dell'email di destinazione
3. **Verifica Email**: Assicurati che l'indirizzo email amministratore sia corretto
4. **Diagnostics**: Controlla i log nella sezione Diagnostics per errori email dettagliati
5. **Plugin SMTP**: Se il test fallisce, installa un plugin SMTP (WP Mail SMTP, Easy WP SMTP)
6. **Hosting**: Contatta il provider hosting se la funzione mail() PHP non è disponibile
7. **Server Mail**: Verifica che il server abbia configurazione email/SMTP funzionante

**Errori comuni**:
- Server senza funzione mail() abilitata
- Provider hosting che blocca invio email
- Mancanza configurazione SMTP  
- Email finiscono in blacklist spam

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
- ✅ "Polling Continuo: < 2 minuti fa"
- ✅ "Deep Check: < 35 minuti fa"
- ✅ Nessun errore nei log recenti

### Metriche Chiave
- **Prenotazioni intercettate**: Tutte le prenotazioni HIC (online + manuali)
- **Eventi GA4**: Purchase events in tempo reale
- **Contatti Brevo**: Nuovi contatti/aggiornamenti
- **Attribution**: Bucket gads/fbads/organic corretto

Il sistema dual-mode funziona **automaticamente** e rileva ogni prenotazione HIC (incluse quelle manuali) entro 1-2 minuti, con deep check ogni 30 minuti che verifica le ultime 5 prenotazioni per garantire che nulla venga perso.
