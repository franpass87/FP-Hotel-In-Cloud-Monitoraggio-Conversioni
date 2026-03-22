# Panoramica FP HIC Monitor

> **Versione**: 3.6.0 · **Ultimo aggiornamento**: 2026-03-22

FP HIC Monitor e ora un plugin WordPress **minimale** con un solo obiettivo: ricevere nuove prenotazioni da Hotel in Cloud e sincronizzarle su Brevo.

## Obiettivo del plugin
- Ricevere payload prenotazione via webhook protetto da token.
- Normalizzare i campi principali (date soggiorno e anagrafica cliente).
- Inviare contatto ed evento a Brevo.
- Esporre eventi `fp_tracking_event` per integrazione con il layer di tracking FP.

## Flusso operativo
1. Hotel in Cloud invia `POST /wp-json/hic/v1/conversion?token=<TOKEN>`.
2. Il plugin valida il token e normalizza il payload.
3. Viene aggiornato/creato il contatto su Brevo (`/v3/contacts`).
4. Viene inviato l'evento su Brevo (`/v3/events` o modalita legacy).
5. Vengono emessi eventi tracking (`booking_confirmed`, `purchase`, legacy HIC).

## Strumenti admin disponibili
- Configurazione completa webhook e Brevo.
- Test connessione Brevo live.
- Storico ultimi 20 test connessione.
- Pannello "Ultimo payload HIC ricevuto" (raw + normalizzato, dati mascherati).

Per dettagli tecnici vedi [architecture.md](architecture.md), [code-map.md](code-map.md) e [faq.md](faq.md).
