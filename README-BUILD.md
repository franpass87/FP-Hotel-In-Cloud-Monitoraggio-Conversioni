# Processo di Build e Packaging

## Prerequisiti

- PHP 8.2 con estensione `zip`
- [Composer 2](https://getcomposer.org/)
- Strumenti da shell disponibili: `bash`, `rsync`, `zip`

## Comandi Principali

### Bump automatico (patch) e build

```bash
bash build.sh --bump=patch
```

### Impostare manualmente la versione e generare lo zip

```bash
bash build.sh --set-version=1.2.3
```

Al termine lo script mostra:

- Versione finale scritta nell'header del plugin
- Percorso completo dello zip generato in `build/`

## GitHub Action

La workflow `Build Plugin Zip` genera automaticamente lo zip quando viene creato un tag `v*`:

1. Esegui il bump locale (`bash build.sh --bump=patch`) e verifica.
2. Crea il tag con il numero di versione, ad esempio `git tag v1.2.3 && git push origin v1.2.3`.
3. L'azione su GitHub prepara lo zip e lo pubblica come artifact `plugin-zip`.
