# Contributing to FP HIC Monitor

Grazie per il tuo interesse nel contribuire a FP HIC Monitor! Questo documento fornisce linee guida per contribuire al progetto.

## 📋 Indice

- [Code of Conduct](#code-of-conduct)
- [Come Posso Contribuire?](#come-posso-contribuire)
- [Sviluppo Locale](#sviluppo-locale)
- [Linee Guida](#linee-guida)
- [Process di Pull Request](#process-di-pull-request)

## Code of Conduct

Questo progetto aderisce a un codice di condotta professionale e rispettoso. Contribuendo, ti impegni a mantenere un ambiente collaborativo e inclusivo.

## Come Posso Contribuire?

### 🐛 Segnalare Bug

Prima di creare un issue per un bug:

1. **Cerca** nei issue esistenti per evitare duplicati
2. **Verifica** di usare l'ultima versione del plugin
3. **Prepara** informazioni dettagliate:
   - Versione plugin, WordPress, PHP
   - Passi per riprodurre il bug
   - Comportamento atteso vs effettivo
   - Log rilevanti (da HIC Monitor → Registro eventi)
   - Screenshot se applicabile

**Crea issue**: https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni/issues/new

### 💡 Suggerire Funzionalità

Per proporre nuove feature:

1. **Verifica** che non sia già in roadmap
2. **Descrivi** chiaramente:
   - Il problema che risolve
   - Il caso d'uso
   - Comportamento proposto
   - Alternative considerate
3. **Etichetta** con `enhancement`

### 📝 Migliorare Documentazione

La documentazione è sempre migliorabile! Puoi contribuire:

- Correggendo errori o typo
- Aggiungendo esempi pratici
- Traducendo guide
- Migliorando chiarezza

**Vedi**: [DOCUMENTAZIONE.md](../DOCUMENTAZIONE.md) per struttura completa

### 💻 Contribuire Codice

Siamo felici di ricevere contributi di codice per:

- Bug fix
- Nuove funzionalità
- Miglioramenti performance
- Refactoring
- Test

## Sviluppo Locale

### Setup Ambiente

```bash
# 1. Clona il repository
git clone https://github.com/francescopasseri/FP-Hotel-In-Cloud-Monitoraggio-Conversioni.git
cd FP-Hotel-In-Cloud-Monitoraggio-Conversioni

# 2. Installa dipendenze
composer install

# 3. Setup WordPress locale (es. Local by Flywheel, Docker, etc.)
# Copia il plugin nella directory wp-content/plugins/

# 4. Attiva il plugin
# Da WordPress Admin → Plugin
```

### Eseguire Test

```bash
# Test unitari PHPUnit
composer test

# Linting PHP (WordPress Coding Standards)
composer lint

# Analisi statica PHPStan
composer analyse

# Controllo mess detector
composer mess

# Suite completa QA
composer qa
```

### Convenzioni Codice

#### PHP

- **Standard**: WordPress Coding Standards (WPCS)
- **PHPDoc**: Obbligatorio per funzioni pubbliche
- **Type Hints**: Usa sempre quando possibile (PHP 7.4+)
- **Namespace**: Segui struttura PSR-4 esistente

```php
<?php declare(strict_types=1);

namespace FpHic\MioModulo;

/**
 * Descrizione breve della funzione.
 *
 * @param string $param Descrizione parametro.
 * @return bool Descrizione ritorno.
 */
function mia_funzione(string $param): bool
{
    // Implementazione
}
```

#### JavaScript

- **ES6+**: Usa sintassi moderna quando supportata
- **Error Handling**: Usa try-catch per operazioni async
- **Naming**: camelCase per variabili, PascalCase per classi
- **Comments**: JSDoc per funzioni esportate

```javascript
/**
 * Descrizione della funzione.
 * @param {string} param - Parametro di input
 * @returns {boolean} - Risultato
 */
function myFunction(param) {
  try {
    // Implementazione
    return true;
  } catch (error) {
    console.warn('Error:', error);
    return false;
  }
}
```

#### Naming Conventions

| Tipo | Convenzione | Esempio |
|------|-------------|---------|
| Funzioni globali | `hic_snake_case()` | `hic_get_option()` |
| Classi | `PascalCase` | `BookingProcessor` |
| Metodi | `camelCase()` | `processBooking()` |
| Costanti | `UPPER_SNAKE_CASE` | `HIC_PLUGIN_VERSION` |
| Hook filters | `hic_snake_case` | `hic_ga4_payload` |
| Hook actions | `hic_snake_case` | `hic_booking_processed` |
| Database tables | `prefix_snake_case` | `hic_booking_events` |

### Struttura Branch

- `main` - Branch stabile (release)
- `develop` - Branch sviluppo (integrazioni)
- `feature/nome-feature` - Nuove funzionalità
- `fix/nome-bug` - Bug fix
- `docs/nome-doc` - Documentazione

## Linee Guida

### Commit Messages

Usa [Conventional Commits](https://www.conventionalcommits.org/):

```
tipo(scope): descrizione

[corpo opzionale]

[footer opzionale]
```

**Tipi**:
- `feat`: Nuova funzionalità
- `fix`: Bug fix
- `docs`: Solo documentazione
- `style`: Formattazione (non cambia logica)
- `refactor`: Refactoring senza nuove feature o fix
- `perf`: Miglioramento performance
- `test`: Aggiunta/correzione test
- `chore`: Manutenzione (build, deps, etc.)

**Esempi**:
```bash
feat(ga4): add support for user_id parameter
fix(webhook): prevent replay attacks with timestamp validation
docs(readme): update installation instructions
refactor(polling): extract retry logic to separate class
```

### Testing Requirements

- **Unit tests** per nuove funzioni
- **Integration tests** per API e integrazioni
- **Mantenere coverage** > 70% su codice critico
- **Testare** su PHP 7.4, 8.0, 8.1, 8.2
- **Verificare** compatibilità WordPress 5.8+

### Security

- ✅ **Sanitizza** sempre input utente
- ✅ **Valida** dati prima di usarli
- ✅ **Usa** prepared statements per DB
- ✅ **Verifica** capability per operazioni admin
- ✅ **Hash** PII prima di inviarli a servizi esterni
- ✅ **Non commitare** credenziali o segreti
- ✅ **Documenta** implicazioni sicurezza in PR

### Performance

- ⚡ **Ottimizza** query database (usa indici)
- ⚡ **Cache** risultati costosi
- ⚡ **Lazy load** risorse quando possibile
- ⚡ **Batch** operazioni multiple
- ⚡ **Profila** codice critico

### Documentazione

Ogni contributo significativo dovrebbe includere:

- **PHPDoc** per nuove funzioni/classi
- **README** update se cambia comportamento
- **CHANGELOG** entry per modifiche utente-visibili
- **Guide** per nuove funzionalità
- **Tests** documentati

## Process di Pull Request

### 1. Prepara il Contributo

```bash
# 1. Fork il repository
# 2. Crea branch da develop
git checkout develop
git pull origin develop
git checkout -b feature/mia-feature

# 3. Fai modifiche e commit
git add .
git commit -m "feat(scope): descrizione"

# 4. Push al tuo fork
git push origin feature/mia-feature
```

### 2. Crea Pull Request

1. Vai su GitHub e crea PR verso `develop`
2. **Titolo**: Usa conventional commit format
3. **Descrizione**: Includi:
   - Cosa cambia e perché
   - Issue collegato (se esiste)
   - Screenshot (se UI)
   - Note per testing
   - Breaking changes (se presenti)

### 3. Template PR

```markdown
## Descrizione
Breve descrizione delle modifiche

## Motivazione
Perché questo cambiamento è necessario?

## Tipo di Change
- [ ] Bug fix (non-breaking)
- [ ] Nuova feature (non-breaking)
- [ ] Breaking change
- [ ] Documentazione

## Testing
Come è stato testato?
- [ ] Unit tests
- [ ] Integration tests
- [ ] Test manuale

## Checklist
- [ ] Codice segue style guidelines
- [ ] Self-review completata
- [ ] Commenti aggiunti dove necessario
- [ ] Documentazione aggiornata
- [ ] Nessun nuovo warning
- [ ] Test aggiunti/aggiornati
- [ ] Tutti i test passano localmente
- [ ] CHANGELOG aggiornato
```

### 4. Code Review

- Rispondi a feedback in modo costruttivo
- Fai commit aggiuntivi per fix
- Non fare force push dopo review iniziata
- Mantieni conversazione on-topic

### 5. Merge

Il maintainer farà merge dopo:
- ✅ Almeno un approval
- ✅ CI/CD passa
- ✅ Conflitti risolti
- ✅ Conversazione conclusa

## Domande?

- **Email**: [info@francescopasseri.com](mailto:info@francescopasseri.com)
- **Issue**: Usa GitHub Discussions per domande generali
- **Documentazione**: [DOCUMENTAZIONE.md](../DOCUMENTAZIONE.md)

## Licenza

Contribuendo, accetti che i tuoi contributi saranno licenziati sotto GPLv2 or later, come il resto del progetto.

---

**Grazie per contribuire a FP HIC Monitor!** 🎉
