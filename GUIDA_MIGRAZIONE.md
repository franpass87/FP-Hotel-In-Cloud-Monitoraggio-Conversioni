# Guida Migrazione FP HIC Monitor v3.0

> **Versione plugin:** 3.3.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


## 🔄 Procedura di Aggiornamento v3.0

### Pre-Requisiti
- WordPress 5.8+
- PHP 7.4+
- Backup completo del sito

### Step 1: Backup e Preparazione
```bash
# Backup files
cp -r wp-content/plugins/FP-Hotel-In-Cloud-Monitoraggio-Conversioni backup/

# Backup database
mysqldump -u user -p database_name > backup_database.sql
```

### Step 2: Aggiornamento Plugin
1. Disattiva il plugin temporaneamente
2. Sostituisci i file del plugin con la nuova versione 3.0
3. Riattiva il plugin

### Step 3: Verifica Funzionamento
1. Vai su **WordPress Admin > Impostazioni > HIC Monitoring**
2. Scheda **Diagnostics** > clicca **Test Connessione API**
3. Verifica che il test passi con successo

## ⚠️ Note Importanti per v3.0

### Novità Versione 3.0
- **Nome aggiornato**: FP HIC Monitor (invece di "HIC GA4 + Brevo + Meta")
- **Sicurezza HTTP avanzata**: Sistema enterprise-grade per richieste API
- **Cache intelligente**: Performance migliorate per installazioni high-traffic
- **Validazione input**: Protezione anti-XSS e validazione RFC-compliant

### Compatibilità
✅ **100% backward compatible** - zero breaking changes
- Tutte le configurazioni esistenti rimangono valide
- API pubbliche mantengono le stesse signature
- Interfaccia admin identica per gli utenti finali

### Compatibilità
- ✅ **100% backward compatible** - nessuna configurazione richiesta
- ✅ **Zero downtime** - il plugin continua a funzionare durante l'update
- ✅ **Dati preservati** - tutte le configurazioni esistenti rimangono inalterate

### Nuove Features Automatiche
Dopo l'aggiornamento, le seguenti migliorie saranno **automaticamente attive**:

1. **Sicurezza HTTP Enhanced**
   - Tutte le chiamate API useranno il nuovo sistema sicuro
   - Logging migliorato per debugging

2. **Validazione Input Avanzata**  
   - Webhook e form admin avranno validazione più rigorosa
   - Messaggi errore più informativi

3. **Cache Intelligente**
   - API calls verranno cachate automaticamente
   - Performance migliorate per operazioni repeated

### Monitoraggio Post-Aggiornamento

#### Check Log Files
```bash
# Verifica log per errori
tail -f wp-content/uploads/hic-logs/hic-debug.log
```

#### Metriche da Monitorare
- Response time API calls (dovrebbe migliorare)
- Error rate webhook/polling (dovrebbe diminuire)  
- Memory usage (dovrebbe rimanere stabile)

## 🐛 Troubleshooting

### Problema: API Test Fallisce
**Soluzione**: Verifica che le credenziali siano corrette
```php
// Testa manualmente
$result = hic_test_api_connection();
var_dump($result);
```

### Problema: Cache Non Funziona
**Soluzione**: Verifica che WordPress transients funzionino
```php
// Test cache
set_transient('test_cache', 'test_value', 60);
$value = get_transient('test_cache');
echo $value; // Dovrebbe stampare 'test_value'
```

### Problema: Validazione Troppo Rigorosa
**Soluzione**: Temporaneamente bypassa validazione per debug
```php
// In wp-config.php per debug
define('HIC_SKIP_VALIDATION', true);
```

## 📊 Verifica Miglioramenti

### Test Performance
```bash
# Prima dell'aggiornamento
time curl -X POST "https://tuosito.com/wp-json/hic/v1/test"

# Dopo l'aggiornamento (dovrebbe essere più veloce per call successive)
time curl -X POST "https://tuosito.com/wp-json/hic/v1/test"
```

### Test Sicurezza
Prova richieste malformed per verificare che vengano bloccate:
```bash
# Questo dovrebbe fallire con messaggio sicurezza
curl -X POST "https://tuosito.com/wp-json/hic/v1/conversion" \
  -d "email=<script>alert('xss')</script>"
```

## ✅ Checklist Post-Migrazione

- [ ] Plugin attivato correttamente
- [ ] Test API connessione passa
- [ ] Webhook riceve dati normalmente  
- [ ] Log non mostrano errori critici
- [ ] Performance migliorate per operazioni repeated
- [ ] Cache funziona (verifica log "Cache HIT")
- [ ] Validazione input blocca dati malformed
- [ ] Nessun breaking change per utenti finali

## 🆘 Rollback Procedure

In caso di problemi critici:

### Step 1: Disattiva Plugin
```php
// In wp-config.php
define('HIC_PLUGIN_DISABLED', true);
```

### Step 2: Ripristina Backup
```bash
# Ripristina files
rm -rf wp-content/plugins/FP-Hotel-In-Cloud-Monitoraggio-Conversioni
cp -r backup/ wp-content/plugins/FP-Hotel-In-Cloud-Monitoraggio-Conversioni

# Ripristina database (se necessario)
mysql -u user -p database_name < backup_database.sql
```

### Step 3: Riattiva Versione Precedente
1. Rimuovi la linea `HIC_PLUGIN_DISABLED` da wp-config.php
2. Riattiva plugin dal dashboard WordPress

## 📞 Supporto

Per problemi durante la migrazione:

1. **Log Files**: Sempre allega i log files relevanti
2. **Configurazione**: Documenta la tua configurazione (API keys escluse)
3. **Environment**: Specifica versione WordPress, PHP, hosting provider
4. **Steps**: Descrivi esattamente i passi che hanno causato il problema

## 🎯 Best Practices Post-Migrazione

### Monitoring Continuo
- Controlla log files settimanalmente
- Monitora performance API calls
- Verifica cache hit rate

### Ottimizzazioni Opzionali
```php
// In wp-config.php - ottimizzazioni cache
define('HIC_CACHE_EXTENDED_TTL', true); // Cache più lungo per data statici

// Logging più verboso per debugging (temporaneo)
define('HIC_DEBUG_VERBOSE', true);
```

### Security Hardening Aggiuntivo
```php
// Disable certain features se non usati
define('HIC_DISABLE_WEBHOOK', true); // Se usi solo polling
define('HIC_STRICT_VALIDATION', true); // Validazione extra-rigorosa
```
