# Guida Completa di Configurazione

> **Versione plugin:** 3.4.1 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)

## Indice

1. [Prerequisiti](#prerequisiti)
2. [Installazione Plugin](#installazione-plugin)
3. [Configurazione Base](#configurazione-base)
4. [Configurazione Integrazioni](#configurazione-integrazioni)
5. [Test e Validazione](#test-e-validazione)
6. [Troubleshooting](#troubleshooting)

---

## Prerequisiti

Prima di iniziare, assicurati di avere:

- ✅ **WordPress** 5.8 o superiore
- ✅ **PHP** 7.4 o superiore (testato fino a 8.2)
- ✅ **Account Hotel in Cloud** attivo con accesso API
- ✅ **Account GA4** (opzionale ma raccomandato)
- ✅ **Account Brevo** (opzionale per email marketing)
- ✅ **Account Meta/Facebook** con CAPI (opzionale per Facebook Ads)

---

## Installazione Plugin

### Metodo 1: Da File ZIP

1. Scarica l'ultima versione dalla [release page](https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/releases)
2. Vai su **WordPress Admin → Plugin → Aggiungi nuovo → Carica plugin**
3. Seleziona il file ZIP e clicca **Installa ora**
4. Clicca **Attiva plugin** dopo l'installazione

### Metodo 2: Da Repository Git

```bash
cd wp-content/plugins/
git clone https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.git fp-hic-monitor
```

Poi attiva il plugin da **WordPress Admin → Plugin**.

---


## Configurazione Base

### Passo 1: Accedere alle Impostazioni

1. Vai su **WordPress Admin → HIC Monitor → Impostazioni**
2. Vedrai diverse schede di configurazione:
   - **Impostazioni Generali**: Configurazione base
   - **HIC Webhook & S2S**: Webhook e credenziali API
   - **GA4 & Enhanced**: Integrazione Google Analytics 4
   - **Meta/Facebook**: Integrazione Facebook CAPI
   - **Brevo**: Integrazione email marketing

### Passo 2: Scegliere la Modalità di Tracciamento

Devi scegliere tra due modalità:

#### Opzione A: API Polling (Raccomandato)

**Vantaggi:**
- ✅ Più affidabile
- ✅ Cattura anche prenotazioni manuali
- ✅ Configurazione solo lato WordPress
- ✅ Recovery automatico in caso di errori

**Configurazione:**

1. Nella scheda **HIC Webhook & S2S**, imposta:
   ```
   Tipo Connessione: API Polling
   ```

2. Inserisci le **Credenziali Hotel in Cloud**:
   ```
   API Base URL: https://api.hotelincloud.com/api/partner
   Email API: tua-email@hotelincloud.com
   Password API: tua-password-hic
   ID Struttura (Property ID): 123456
   ```
   💡 *Puoi trovare il Property ID nel pannello HIC*

3. Configura l'**Intervallo di Polling**:
   ```
   Intervallo: Every Two Minutes (raccomandato)
   Sistema Polling Affidabile: ✅ Attivo
   ```

4. Clicca **Salva modifiche**

#### Opzione B: Webhook (Solo se supportato da HIC)

**Vantaggi:**
- ✅ Tracciamento in tempo reale
- ✅ Nessun polling continuo

**Svantaggi:**
- ⚠️ Non tutte le installazioni HIC supportano webhook
- ⚠️ Potrebbe non catturare prenotazioni manuali

**Configurazione:**

1. Nella scheda **HIC Webhook & S2S**, imposta:
   ```
   Tipo Connessione: Webhook
   ```

2. Genera un **Token di Sicurezza**:
   - Clicca sul pulsante "Genera Token Casuale"
   - Copia il token generato

3. Configura il **Webhook in Hotel in Cloud**:
   ```
   URL Webhook: https://tuo-sito.com/wp-json/hic/v1/conversion?token=IL_TUO_TOKEN
   Metodo: POST
   ```

4. Clicca **Salva modifiche**

📚 **Guida dettagliata webhook**: [GUIDA_WEBHOOK_CONVERSIONI.md](GUIDA_WEBHOOK_CONVERSIONI.md)

---

## Configurazione Integrazioni

### Google Analytics 4 (GA4)

GA4 è essenziale per tracciare gli eventi `purchase` nel tuo analytics.

**Requisiti:**
- Account GA4 attivo
- Property GA4 configurata per il tuo sito

**Passaggi:**

1. **Ottieni Measurement ID**:
   - Vai su **GA4 → Admin → Property → Data Streams**
   - Seleziona il tuo data stream web
   - Copia il **Measurement ID** (formato: `G-XXXXXXXXXX`)

2. **Genera API Secret**:
   - Nella stessa pagina, scorri fino a **Measurement Protocol API secrets**
   - Clicca **Create** e dai un nome (es. "HIC Monitor")
   - Copia il **Secret value** generato

3. **Configura nel Plugin**:
   - Vai su **HIC Monitor → Impostazioni → GA4 & Enhanced**
   - Inserisci:
     ```
     Measurement ID: G-XXXXXXXXXX
     API Secret: [il secret appena generato]
     ```
   - Clicca **Salva modifiche**

4. **Test**:
   - Clicca sul pulsante **Test GA4 Connection**
   - Verifica che appaia un messaggio di successo
   - Controlla in **GA4 → Reports → Realtime** per vedere l'evento di test

📚 **Per modalità GTM**: vedi [GUIDA_GTM_INTEGRAZIONE.md](GUIDA_GTM_INTEGRAZIONE.md)

---

### Brevo (Email Marketing)

Brevo ti permette di sincronizzare automaticamente i contatti e gli eventi di prenotazione.

**Requisiti:**
- Account Brevo attivo
- Liste contatti create (IT e/o EN)

**Passaggi:**

1. **Ottieni API Key**:
   - Vai su **Brevo → Account → SMTP & API → API Keys**
   - Clicca **Create a new API Key**
   - Copia la chiave generata (versione v3)

2. **Trova ID Liste**:
   - Vai su **Brevo → Contacts → Lists**
   - Clicca sulla lista italiana
   - L'ID è visibile nell'URL: `https://app.brevo.com/lists/list/id/XXX`
   - Ripeti per la lista inglese (se hai contatti internazionali)

3. **Configura nel Plugin**:
   - Vai su **HIC Monitor → Impostazioni → Brevo**
   - Abilita l'integrazione e inserisci:
     ```
     ✅ Abilita Brevo
     API Key: [la tua chiave API v3]
     Lista Contatti IT: [ID lista italiana]
     Lista Contatti EN: [ID lista inglese]
     ```

4. **Configura Opzioni Avanzate** (opzionali):
   ```
   ✅ Abilita Email Enrichment (consigliato)
   ✅ Sincronizzazione Real-time (per prenotazioni immediate)
   ✅ Automatic Opt-in (solo se hai il consenso)
   ```

5. **Test**:
   - Clicca **Test Brevo Connection**
   - Verifica che il test passi con successo

📚 **Dettagli attributi**: [BREVO_ATTRIBUTES.md](BREVO_ATTRIBUTES.md)

---

### Meta/Facebook (Facebook CAPI)

Integrazione opzionale per tracciare conversioni su Facebook Ads.

**Requisiti:**
- Facebook Business Manager
- Meta Pixel installato sul sito
- Access Token CAPI

**Passaggi:**

1. **Ottieni Pixel ID**:
   - Vai su **Facebook Business Manager → Eventi → Pixel**
   - Copia il **Pixel ID** (numero lungo)

2. **Genera Conversions API Token**:
   - Nella stessa pagina pixel, vai su **Settings → Conversions API**
   - Clicca **Generate Access Token**
   - Copia il token generato

3. **Configura nel Plugin**:
   - Vai su **HIC Monitor → Impostazioni → Meta/Facebook**
   - Inserisci:
     ```
     ✅ Abilita Meta CAPI
     Pixel ID: [il tuo Pixel ID]
     Access Token: [token generato]
     ```

4. **Test**:
   - Clicca **Test Meta Connection**
   - Verifica nel **Facebook Events Manager** la ricezione dell'evento test

⚠️ **Importante**: L'integrazione Meta richiede il consenso cookie dell'utente per essere GDPR-compliant.

---

## Test e Validazione

Dopo aver configurato tutto, è fondamentale testare che il sistema funzioni correttamente.

### Test 1: Connessione Hotel in Cloud

1. Vai su **HIC Monitor → Impostazioni → HIC Webhook & S2S**
2. Clicca **Test Connessione API**
3. Verifica che appaia:
   ```
   ✅ Connessione API riuscita
   ✅ Credenziali valide
   ✅ Property ID corretto
   ```

Se vedi errori:
- Controlla email e password
- Verifica che il Property ID sia corretto
- Assicurati che l'account HIC abbia accesso API

### Test 2: Integrazioni

Testa ogni integrazione configurata:

**GA4:**
1. Clicca **Test GA4 Connection**
2. Vai su **GA4 → Reports → Realtime**
3. Dovresti vedere un evento di test nei prossimi 60 secondi

**Brevo:**
1. Clicca **Test Brevo Connection**
2. Controlla il risultato del test nell'interfaccia
3. Verifica in Brevo che non ci siano errori API

**Meta:**
1. Clicca **Test Meta Connection**
2. Vai su **Facebook Events Manager → Test Events**
3. Verifica la ricezione dell'evento di test

### Test 3: Polling Attivo

Se hai configurato API Polling:

1. Vai su **HIC Monitor → Diagnostics**
2. Controlla la sezione **Sistema Polling**:
   ```
   Status: ✅ Attivo
   Ultimo polling: < 5 minuti fa
   Prenotazioni elaborate: X
   ```

3. Attendi 2-5 minuti e ricarica la pagina
4. Il timestamp "Ultimo polling" deve aggiornarsi

### Test 4: Prenotazione Reale

Il test definitivo è con una prenotazione di test:

1. **Crea una prenotazione di test** in Hotel in Cloud
   - Usa dati fittizi ma validi
   - Importo > 0
   - Email valida (tua email di test)

2. **Attendi 2-5 minuti** (se usi polling)
   - Con webhook il tracciamento è immediato

3. **Verifica in GA4**:
   - Vai su **Reports → Realtime → Events**
   - Cerca l'evento `purchase`
   - Verifica i parametri (transaction_id, value, currency)

4. **Verifica in Brevo** (se configurato):
   - Vai su **Contacts → All contacts**
   - Cerca l'email della prenotazione test
   - Verifica che il contatto sia stato creato/aggiornato

5. **Controlla i Log del Plugin**:
   - Vai su **HIC Monitor → Registro eventi**
   - Cerca entry relative alla prenotazione:
     ```
     ✅ Prenotazione ID XXX processata
     ✅ GA4 purchase event sent
     ✅ Brevo contact created
     ```

### Dashboard Diagnostica

La pagina **HIC Monitor → Diagnostics** offre una panoramica completa:

- ✅ **System Status**: Stato generale del plugin
- ✅ **API Connections**: Stato connessioni esterne
- ✅ **Recent Activity**: Ultime operazioni
- ✅ **Error Log**: Eventuali errori recenti

---

## Troubleshooting

### Problema: "Sistema Polling Non Attivo"

**Cause possibili:**
- Credenziali HIC errate
- WP-Cron non funzionante
- Plugin disabilitato

**Soluzioni:**
1. Verifica le credenziali con **Test Connessione API**
2. Controlla che WP-Cron sia attivo:
   ```php
   // Aggiungi in wp-config.php se necessario
   define('DISABLE_WP_CRON', false);
   ```
3. Riattiva il plugin se necessario

### Problema: "Eventi GA4 non visibili"

**Cause possibili:**
- Measurement ID o API Secret errati
- Prenotazione con importo 0
- Ritardo nella visualizzazione GA4

**Soluzioni:**
1. Ricontrolla Measurement ID (formato `G-XXXXXXXXXX`)
2. Rigenera API Secret se necessario
3. Usa la vista **Realtime** (non Reports con ritardo)
4. Verifica nei log del plugin:
   ```
   Cerca: "GA4 purchase event sent"
   ```

### Problema: "Contatti non creati in Brevo"

**Cause possibili:**
- API Key errata o scaduta
- ID Liste errati
- Email non valida

**Soluzioni:**
1. Verifica API Key (deve essere versione v3)
2. Controlla ID liste nell'URL Brevo
3. Verifica nei log eventuali errori API Brevo:
   ```
   Cerca: "Brevo" nei log
   ```

### Problema: "Prenotazioni duplicate"

**Causa:**
Entrambe le modalità (Webhook + Polling) attive contemporaneamente

**Soluzione:**
Scegli **una sola modalità**:
- Disabilita Webhook se usi Polling
- Disabilita Polling se usi Webhook

### Problema: "Polling troppo lento"

**Causa:**
Intervallo polling impostato su 5+ minuti

**Soluzione:**
1. Vai su **HIC Monitor → Impostazioni**
2. Imposta **Intervallo: Every Two Minutes**
3. Salva e attendi il prossimo ciclo

### Problema: "WordPress lento dopo installazione"

**Cause possibili:**
- Polling troppo frequente
- Troppe integrazioni attive
- Hosting limitato

**Soluzioni:**
1. Aumenta l'intervallo di polling a 5 minuti
2. Disabilita integrazioni non necessarie
3. Considera un upgrade hosting se il problema persiste

---

## Supporto Avanzato

Se i problemi persistono:

1. **Raccogli informazioni**:
   - Versione plugin (vedi header file principale)
   - Versione WordPress e PHP
   - Log ultimi 50 eventi (da Registro eventi)
   - Screenshot eventuali errori

2. **Consulta la documentazione**:
   - **[FAQ.md](FAQ.md)** - Domande frequenti
   - **[DOCUMENTAZIONE.md](DOCUMENTAZIONE.md)** - Indice completo

3. **Contatta il supporto**:
   - **Email**: [info@francescopasseri.com](mailto:info@francescopasseri.com)
   - **Issue Tracker**: [GitHub Issues](https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/issues)

---

## Checklist Finale

Prima di andare in produzione, verifica:

- [ ] ✅ Plugin installato e attivato
- [ ] ✅ Modalità tracciamento scelta (Polling o Webhook)
- [ ] ✅ Credenziali HIC configurate e testate
- [ ] ✅ Almeno un'integrazione configurata (GA4 consigliato)
- [ ] ✅ Test connessioni tutte passate
- [ ] ✅ Prenotazione di test tracciata con successo
- [ ] ✅ Log del plugin controllati (nessun errore)
- [ ] ✅ Dashboard Diagnostica mostra tutto verde
- [ ] ✅ Polling attivo (se configurato)
- [ ] ✅ Documentato setup per riferimento futuro

**Congratulazioni!** Il tuo sistema di tracciamento è ora operativo. 🎉

---

## Prossimi Passi

Dopo la configurazione base, considera di:

1. **Configurare Google Tag Manager** per gestione tag centralizzata:
   - Guida: [GUIDA_GTM_INTEGRAZIONE.md](GUIDA_GTM_INTEGRAZIONE.md)

2. **Abilitare Google Ads Enhanced Conversions** (se usi Google Ads):
   - Guida: [GUIDA_CONVERSION_ENHANCED.md](GUIDA_CONVERSION_ENHANCED.md)

3. **Personalizzare attributi Brevo** per segmentazione avanzata:
   - Riferimento: [BREVO_ATTRIBUTES.md](BREVO_ATTRIBUTES.md)

4. **Monitorare le performance** tramite dashboard:
   - Accedi a **HIC Monitor → Performance Dashboard**

5. **Configurare alerting** per errori critici (feature enterprise)

---

**Buon tracciamento con FP HIC Monitor!** 🚀
