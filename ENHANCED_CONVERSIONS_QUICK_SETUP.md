# Setup Conversioni Enhanced - Quick Reference

> **Versione plugin:** 3.2.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


## 🚀 Setup in 10 Minuti

### Step 1: Google Cloud Console
```bash
1. Vai su console.cloud.google.com
2. Crea progetto "Hotel-Enhanced-Conversions"
3. Abilita "Google Ads API"
4. Crea Service Account → Download JSON
```

### Step 2: Google Ads
```bash
1. Centro API → Richiedi Developer Token
2. Conversioni → Abilita "Enhanced Conversions"
3. Metodo: "Google Ads API"
4. Copia Conversion Action ID
```

### Step 3: WordPress Plugin
```bash
1. HIC Monitoring → Enhanced Conversions
2. ✅ Enable Enhanced Conversions
3. Upload Service Account JSON
4. Inserisci Customer ID + Conversion Action ID
5. Test Connection
```

### Step 4: Validation
```bash
1. Test Enhanced Conversion
2. Crea prenotazione di test
3. Verifica Google Ads → Import conversioni
```

## ⚙️ Configurazione Ottimale

### Impostazioni Raccomandate
```
Upload Mode: Batch
Batch Size: 100
Schedule: Every hour
Hash Algorithm: SHA-256
Include Phone: ✅
Include Name: ✅
Max Retries: 3
```

### Credenziali Necessarie
```
✅ Developer Token (da Google Ads Centro API)
✅ Customer ID (Google Ads, no trattini)
✅ Service Account JSON (da Google Cloud)
✅ Conversion Action ID (da Google Ads)
```

## 🔧 Troubleshooting Rapido

### ❌ "API Connection Failed"
```bash
→ Developer Token non approvato
→ Service Account senza permessi
→ OAuth non configurato
```

### ❌ "Conversion Action Not Found"
```bash
→ ID azione conversione errato
→ Enhanced Conversions non abilitato in Google Ads
```

### ❌ "Upload Stuck"
```bash
→ Reset queue: wp option delete hic_enhanced_conversions_queue
→ Restart cron: wp cron event run hic_enhanced_conversions_batch_upload
```

## 📊 Monitoring Essenziale

### Dashboard Plugin
```
WordPress Admin → HIC Monitoring → Enhanced Conversions
- Success rate: >95%
- Last upload: <1 hour ago
- Queue size: <100 pending
```

### Google Ads Validation
```
Google Ads → Misure → Conversioni
- Import conversioni enhanced
- Attribution improvement
```

### KPI da Monitorare
```
✅ Upload success rate: >95%
✅ Processing latency: <2 hours
✅ Error rate: <5%
✅ ROAS improvement: +15-25%
```

## 🛡️ Best Practices

### Sicurezza
- Service Account JSON: non committare in repo
- Hashing: sempre lowercase + trim email
- Rate limiting: usa batch upload

### Performance  
- Batch size ottimale per volume:
  - <50/giorno: Real-time
  - 50-500/giorno: Batch 50
  - >500/giorno: Batch 100

### Compliance
- Data retention: 90 giorni
- GDPR compliance: hash server-side
- Privacy: no PII in logs

## 📖 Link Utili

- **Setup Completo**: [GUIDA_CONVERSION_ENHANCED.md](GUIDA_CONVERSION_ENHANCED.md)
- **FAQ**: [FAQ.md](FAQ.md#conversioni-enhanced-google-ads)
- **Google Ads API**: [developers.google.com/google-ads/api](https://developers.google.com/google-ads/api)
- **Enhanced Conversions Guide**: [support.google.com/google-ads/answer/9888656](https://support.google.com/google-ads/answer/9888656)
