# Guida Setup Conversioni Enhanced di Google Ads

> **Versione plugin:** 3.3.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


## Cosa sono le Conversioni Enhanced

Le **Conversioni Enhanced** (Enhanced Conversions) di Google Ads permettono di migliorare significativamente l'accuratezza del tracciamento delle conversioni utilizzando dati first-party hashati in modo sicuro.

### Vantaggi delle Conversioni Enhanced

- 📈 **Migliore ROAS**: Attribution più accurata delle conversioni
- 🔒 **Privacy-Safe**: Dati email hashati con SHA-256
- 🎯 **Attribution Cross-Device**: Collega conversioni tra dispositivi diversi
- 📊 **Riduzione Data Loss**: Recupera conversioni non tracciabili altrimenti
- 🚀 **Machine Learning Migliorato**: Google Ads può ottimizzare meglio le campagne

### Come Funzionano

1. **Raccolta Dati**: Il plugin raccoglie email cliente dalla prenotazione
2. **Hashing Sicuro**: Email viene hashata con SHA-256 lato server
3. **Invio a Google Ads**: Hash + GCLID inviati tramite Google Ads API
4. **Matching**: Google fa matching con account Google dell'utente
5. **Attribution**: Conversione attribuita alla campagna/parola chiave corretta

## Prerequisiti

### 1. Account Google Ads Configurato
- Account Google Ads attivo con campagne
- Conversioni già configurate in Google Ads
- Accesso come Amministratore o Editor dell'account

### 2. Credenziali API Google Ads
- **Google Ads API abilitata** nel tuo progetto Google Cloud
- **Service Account** con permessi Google Ads API
- **Developer Token** approvato da Google
- **Customer ID** del tuo account Google Ads

### 3. Plugin Hotel in Cloud Configurato
- Plugin HIC installato e funzionante
- API Hotel in Cloud configurata
- Prenotazioni che arrivano correttamente

## Setup Passo-Passo

### Passo 1: Configurazione Google Cloud Console

1. **Vai su Google Cloud Console** → [console.cloud.google.com](https://console.cloud.google.com)

2. **Crea o Seleziona Progetto**:
   ```
   Nome Progetto: Hotel-Conversions-Enhanced
   ```

3. **Abilita Google Ads API**:
   - Vai su "API e Servizi" → "Libreria"
   - Cerca "Google Ads API"
   - Clicca "Abilita"

4. **Crea Service Account**:
   - Vai su "IAM e amministrazione" → "Account di servizio"
   - Clicca "Crea account di servizio"
   ```
   Nome: hotel-enhanced-conversions
   Descrizione: Service account per conversioni enhanced hotel
   ```

5. **Genera Chiave JSON**:
   - Entra nel service account creato
   - Vai su "Chiavi" → "Aggiungi chiave" → "JSON"
   - Scarica il file JSON (tienilo sicuro!)

### Passo 2: Configurazione Google Ads

1. **Accedi a Google Ads** → [ads.google.com](https://ads.google.com)

2. **Richiedi Developer Token**:
   - Vai su "Strumenti e Impostazioni" → "Configurazione" → "Centro API"
   - Compila il modulo per richiedere Developer Token
   - **Nota**: L'approvazione può richiedere alcuni giorni

3. **Configura Azione di Conversione**:
   ```
   Nome: Prenotazioni Hotel Enhanced
   Categoria: Acquisto
   Valore: Usa valori diversi per ogni conversione
   Conteggio: Una conversione
   Finestra di conversione: 30 giorni
   ```

4. **Abilita Enhanced Conversions**:
   - Nell'azione di conversione, vai su "Impostazioni"
   - Abilita "Conversioni enhanced"
   - Seleziona "Google Ads API"

### Passo 3: Configurazione Plugin WordPress

1. **Accedi a WordPress Admin** → **Impostazioni** → **HIC Monitoring**

2. **Vai alla sezione "Enhanced Conversions"**:
   ```
   ✅ Abilita Google Ads Enhanced Conversions
   ```

3. **Inserisci Credenziali Google Ads API**:
   ```
   Developer Token: [il tuo developer token]
   Customer ID: [ID account Google Ads senza trattini]
   Client ID: [da file JSON service account]
   Client Secret: [da file JSON service account]
   Refresh Token: [generato automaticamente]
   ```

4. **Configura Azione di Conversione**:
   ```
   Conversion Action ID: [ID azione conversione da Google Ads]
   Conversion Label: [label da Google Ads]
   ```

5. **Impostazioni Avanzate**:
   ```
   Upload Mode: Batch (raccomandato)
   Batch Size: 100
   Hash Algorithm: SHA-256
   Include Phone: ✅ (se disponibile)
   Include Name: ✅ (se disponibile)
   Include Address: ✅ (se disponibile)
   ```

### Passo 4: Caricamento Service Account

1. **Upload Credenziali JSON**:
   - Nella sezione Enhanced Conversions
   - Clicca "Upload Service Account JSON"
   - Seleziona il file JSON scaricato da Google Cloud

2. **Test Connessione**:
   - Clicca "Test Connessione Google Ads API"
   - Verifica che mostri "✅ Connessione riuscita"

### Passo 5: Configurazione OAuth (se richiesto)

Se il test di connessione fallisce, configura OAuth:

1. **Genera OAuth Consent**:
   - In Google Cloud Console → "API e Servizi" → "Schermata consenso OAuth"
   - Configura applicazione (Internal o External)

2. **Crea OAuth Client**:
   - "Credenziali" → "Crea credenziali" → "ID client OAuth 2.0"
   - Tipo: Applicazione web
   - URI di reindirizzamento: `https://tuodominio.com/wp-admin/admin.php?page=hic-enhanced-conversions`

3. **Autorizza in WordPress**:
   - Inserisci Client ID e Client Secret nel plugin
   - Clicca "Authorize with Google"
   - Completa il flusso OAuth

## Test e Validazione

### Test Funzionalità Enhanced Conversions

1. **Test Interno Plugin**:
   ```
   WordPress Admin → HIC Monitoring → Enhanced Conversions
   Clicca "Test Enhanced Conversion"
   ```

2. **Verifica Creazione Record**:
   - Database WordPress → tabella `wp_hic_enhanced_conversions`
   - Deve contenere record di test con status "pending"

3. **Test Upload Manuale**:
   ```
   Clicca "Upload Pending Conversions"
   Verifica che status diventi "uploaded"
   ```

### Test con Prenotazione Reale

1. **Crea Prenotazione di Test**:
   - Vai su Hotel in Cloud
   - Crea prenotazione con GCLID simulato
   - Usa email valida ma di test

2. **Verifica Processo Automatico**:
   ```
   WordPress Admin → HIC Diagnostics → Log Recenti
   Cerca: "Enhanced conversion processed"
   ```

3. **Controlla Google Ads**:
   - Vai su Google Ads → Misure → Conversioni
   - Guarda "Import di conversioni" nelle ultime 24 ore
   - Verifica eventi enhanced conversions

### Diagnostica Avanzata

1. **Verifica Queue**:
   ```bash
   wp eval "print_r(get_option('hic_enhanced_conversions_queue'));"
   ```

2. **Statistiche Database**:
   ```sql
   SELECT upload_status, COUNT(*) 
   FROM wp_hic_enhanced_conversions 
   GROUP BY upload_status;
   ```

3. **Log Google Ads API**:
   ```
   WordPress Admin → HIC Diagnostics → Enhanced Conversions Log
   ```

## Modalità di Upload

### Batch Upload (Raccomandato)

**Vantaggi**:
- Efficienza API (meno chiamate)
- Rate limiting automatico
- Retry automatico in caso di errori
- Migliori performance

**Configurazione**:
```
Upload Mode: Batch
Batch Size: 100
Schedule: Ogni ora (automatico)
```

**Processo**:
1. Conversioni vengono messe in queue
2. Ogni ora il sistema processa il batch
3. Upload a Google Ads in blocchi di 100
4. Retry automatico per errori temporanei

### Real-time Upload

**Vantaggi**:
- Conversioni immediate
- Feedback istantaneo
- Ideale per testing

**Configurazione**:
```
Upload Mode: Real-time
Max Retries: 3
Timeout: 30 secondi
```

**Processo**:
1. Conversione processata immediatamente
2. Upload singolo a Google Ads
3. Feedback immediato in caso di errori

## Risoluzione Problemi

### Problema: "API Connection Failed"

**Possibili Cause**:
- Developer Token non approvato
- Service Account senza permessi
- OAuth non configurato correttamente

**Soluzioni**:
1. **Verifica Developer Token**:
   ```
   Google Ads → Centro API → Status: Approvato
   ```

2. **Controlla Service Account**:
   ```
   Google Cloud → IAM → Service Account deve avere ruolo "Google Ads API User"
   ```

3. **Rigenera OAuth**:
   ```
   Plugin → Enhanced Conversions → "Re-authorize with Google"
   ```

### Problema: "Conversion Action Not Found"

**Causa**: ID azione conversione errato

**Soluzione**:
1. **Trova ID Corretto**:
   ```
   Google Ads → Misure → Conversioni → [Tua Azione] → URL
   ID è il numero dopo "ctId="
   ```

2. **Aggiorna nel Plugin**:
   ```
   Conversion Action ID: 123456789
   ```

### Problema: "Enhanced Conversions Not Enabled"

**Causa**: Enhanced Conversions non abilitato in Google Ads

**Soluzione**:
1. **Vai su Google Ads** → Misure → Conversioni
2. **Clicca la tua azione di conversione**
3. **Impostazioni** → **Conversioni enhanced** → **Attiva**
4. **Metodo**: Google Ads API

### Problema: "Hash Validation Failed"

**Causa**: Email non hashata correttamente

**Soluzione**:
1. **Verifica formato email**:
   ```php
   // Email deve essere lowercase e trimmed prima dell'hashing
   $email = strtolower(trim($email));
   $hash = hash('sha256', $email);
   ```

2. **Test hash manuale**:
   ```
   Plugin → Enhanced Conversions → "Test Hash Function"
   ```

### Problema: "Batch Upload Stuck"

**Causa**: Queue bloccata o errori API ricorrenti

**Soluzione**:
1. **Reset Queue**:
   ```bash
   wp option delete hic_enhanced_conversions_queue
   ```

2. **Restart Cron**:
   ```bash
   wp cron event run hic_enhanced_conversions_batch_upload
   ```

3. **Clear Failed Records**:
   ```sql
   UPDATE wp_hic_enhanced_conversions 
   SET upload_status = 'pending', upload_attempts = 0 
   WHERE upload_status = 'failed';
   ```

## Best Practices

### Sicurezza dei Dati

1. **File JSON Service Account**:
   - Non committare in repository
   - Conserva in location sicura
   - Usa variabili d'ambiente in produzione

2. **Hashing Email**:
   - Sempre lowercase prima dell'hash
   - Trim spazi bianchi
   - Usa solo SHA-256

3. **Rate Limiting**:
   - Usa batch upload per volume alto
   - Implementa exponential backoff
   - Monitor errori API

### Performance

1. **Batch Size Ottimale**:
   ```
   < 50 conversioni/giorno: Real-time
   50-500 conversioni/giorno: Batch 50
   > 500 conversioni/giorno: Batch 100
   ```

2. **Retry Strategy**:
   ```
   Max Retries: 3
   Backoff: 1s, 4s, 16s
   Permanent failure dopo 3 tentativi
   ```

3. **Monitoring**:
   ```
   Controlla upload_status ogni giorno
   Alert per failed > 5%
   Monitor Google Ads Import Status
   ```

### Compliance Privacy

1. **GDPR Compliance**:
   - Hash dati personali server-side
   - Non loggare email in plain text
   - Permetti opt-out utenti

2. **Data Retention**:
   ```
   Conversioni enhanced: 90 giorni
   Log API: 30 giorni
   Hash email: Solo durante upload
   ```

## Monitoraggio e Analytics

### KPI da Monitorare

1. **Success Rate**:
   ```sql
   SELECT 
     (uploaded / total) * 100 as success_rate
   FROM (
     SELECT 
       SUM(CASE WHEN upload_status = 'uploaded' THEN 1 ELSE 0 END) as uploaded,
       COUNT(*) as total
     FROM wp_hic_enhanced_conversions
     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
   ) stats;
   ```

2. **Attribution Lift**:
   - Confronta conversioni con/senza enhanced
   - Monitor ROAS improvement
   - Analizza cross-device attribution

3. **API Performance**:
   ```
   WordPress Admin → HIC Diagnostics → Enhanced Conversions Stats
   - Average upload time
   - Error rate per day
   - Batch processing efficiency
   ```

### Dashboard Google Ads

1. **Report Personalizzato**:
   ```
   Dimensioni: Campagna, Gruppo annunci
   Metriche: Conversioni, Conversioni enhanced, ROAS
   Filtro: Conversioni enhanced > 0
   ```

2. **Alert Automatici**:
   ```
   Enhanced conversion import rate < 90%
   API error rate > 5%
   Batch processing delays > 2 ore
   ```

## Supporto e Troubleshooting

### Log Levels per Debugging

```php
// In wp-config.php per debug completo
define('HIC_LOG_LEVEL', 'debug');
define('HIC_ENHANCED_CONVERSIONS_DEBUG', true);
```

### Contatti Supporto

- **Documentazione**: README.md e FAQ.md del plugin
- **GitHub Issues**: Repository plugin per bug reports
- **Google Ads API Support**: [developers.google.com/google-ads/api/support](https://developers.google.com/google-ads/api/support)

### Risorse Utili

- [Google Ads Enhanced Conversions Guide](https://support.google.com/google-ads/answer/9888656)
- [Google Ads API Documentation](https://developers.google.com/google-ads/api)
- [WordPress Plugin Development Best Practices](https://developer.wordpress.org/plugins/)

---

**⚠️ Importante**: Le conversioni enhanced richiedono un volume minimo di dati per essere efficaci. Google raccomanda almeno 50 conversioni al mese per vedere miglioramenti significativi nell'attribution.
