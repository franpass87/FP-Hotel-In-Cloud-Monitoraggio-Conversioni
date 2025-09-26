# Phase 10 — Documentation & Release

## Summary
La fase finale ha completato il pacchetto di rilascio della versione **3.4.0**, allineando codice, documentazione e artefatti distribuiti.

## Attività principali
- Aggiornati header di versione, costanti e test automatici alla 3.4.0 per garantire coerenza tra codice e toolchain.
- Esteso il changelog con le novità di sicurezza, performance, compatibilità e osservabilità introdotte nelle fasi 2–9.
- Aggiornato README, guide e workflow di build con riferimenti alla release corrente e alle nuove funzionalità.
- Migliorato `build-plugin.sh` per generare un archivio versionato in `/dist` completo di checksum SHA-256.
- Creato il pacchetto `dist/fp-hotel-in-cloud-monitoraggio-conversioni-v3.4.0.zip` insieme alla firma `*.sha256` per la distribuzione.

## Verifiche
- Eseguiti controlli `composer install` e `composer test` (in locale) nelle fasi precedenti; in questa fase è stato validato lo script di build aggiornato.
- Il pacchetto ZIP è stato generato a partire dalla working tree aggiornata e contiene solo i file necessari all'installazione su WordPress.

## Artefatti
- `dist/fp-hotel-in-cloud-monitoraggio-conversioni-v3.4.0.zip`
- `dist/fp-hotel-in-cloud-monitoraggio-conversioni-v3.4.0.zip.sha256`
