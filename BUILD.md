# Build System per WordPress Plugin

## Panoramica

Il plugin include un sistema di build automatizzato che crea un pacchetto ZIP pronto per l'installazione su WordPress, escludendo tutti i file di sviluppo e mantenendo solo quelli necessari per il funzionamento in produzione.

## Come Usare

### Metodo 1: Composer (Raccomandato)

```bash
composer build
```

### Metodo 2: Script PHP Diretto

```bash
php build-wordpress-zip.php [directory-output]
```

### Metodo 3: Script Shell

```bash
./build.sh [directory-output]
```

## Output

Il sistema di build genera:

- **File ZIP**: `dist/FP-Hotel-In-Cloud-Monitoraggio-Conversioni-{version}.zip`
- **Struttura pulita**: Solo file necessari per produzione
- **Dimensione ottimizzata**: ~130KB vs ~5MB della directory completa di sviluppo

## File Inclusi

✅ **Inclusi nel pacchetto WordPress:**
- `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php` (file principale)
- `README.md` (documentazione)
- `includes/` (codice PHP del plugin)
- `assets/` (CSS e JavaScript)
- `vendor/` (dipendenze Composer di produzione)

❌ **Esclusi dal pacchetto:**
- `.git/` (repository Git)
- `tests/` (test di sviluppo)
- `docs/` (documentazione sviluppatori)
- `phpstan-stubs/` (definizioni per analisi statica)
- Tutti i file `*.md` eccetto `README.md`
- File di configurazione QA (`phpcs.xml`, `phpstan.neon`, ecc.)
- Script di build e quality assurance
- File temporanei e cache

## Processo di Build

1. **Verifica dipendenze**: Installa le dipendenze Composer se mancanti
2. **Crea directory temporanea**: Per la preparazione dei file
3. **Copia selettiva**: Solo i file specificati nei pattern di inclusione
4. **Esclusione**: Applica i filtri di esclusione per file di sviluppo
5. **Compressione ZIP**: Crea l'archivio finale
6. **Pulizia**: Rimuove i file temporanei

## Personalizzazione

Per modificare i file inclusi/esclusi, editare il file `build-wordpress-zip.php`:

```php
// File da includere
$includePatterns = [
    'FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php',
    'README.md',
    'includes/',
    'assets/',
    'vendor/',
];

// File da escludere
$excludePatterns = [
    '.git/',
    'tests/',
    'docs/',
    // ... altri pattern
];
```

## Validazione del Pacchetto

Per verificare l'integrità del ZIP generato:

```bash
# Elenca i contenuti
unzip -l dist/FP-Hotel-In-Cloud-Monitoraggio-Conversioni-*.zip

# Testa l'integrità
unzip -t dist/FP-Hotel-In-Cloud-Monitoraggio-Conversioni-*.zip
```

## Integrazione CI/CD

Il comando può essere facilmente integrato in pipeline CI/CD:

```yaml
# Esempio GitHub Actions
- name: Build WordPress Plugin
  run: composer build

- name: Upload Release Asset
  uses: actions/upload-release-asset@v1
  with:
    asset_path: ./dist/FP-Hotel-In-Cloud-Monitoraggio-Conversioni-${{ github.ref_name }}.zip
```

## Troubleshooting

### Errore: "Composer dependencies not found"
```bash
composer install --no-dev
```

### Errore di permessi su script shell
```bash
chmod +x build.sh
```

### ZIP troppo grande (> 1MB)
Verificare che non siano inclusi file di sviluppo non necessari. La dimensione normale dovrebbe essere ~130KB.