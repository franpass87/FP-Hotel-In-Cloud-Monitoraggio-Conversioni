# FAQ Tecnica FP HIC Monitor

> **Versione**: 3.6.0 · **Per FAQ utente**: vedi [../FAQ.md](../FAQ.md)

## Che cosa fa FP HIC Monitor?
Riceve nuove prenotazioni Hotel in Cloud via webhook e le sincronizza su Brevo (contatto + evento). Inoltre emette eventi `fp_tracking_event` compatibili con il layer FP Tracking.

## È obbligatorio usare polling o cron?
No. In questa versione non c'è polling: il flusso e webhook-driven.

## Come proteggo l'endpoint webhook?
Configura il token nella pagina impostazioni. La chiamata deve arrivare su:
`/wp-json/hic/v1/conversion?token=<TOKEN>`.
Se il token non coincide, la richiesta viene rifiutata.

## Posso inviare email di prenotazione/arrivo/partenza da qui?
Il plugin non invia email direttamente. Invia dati/eventi a Brevo, dove configuri le automazioni email.

## Come verifico se HIC invia i campi corretti?
Usa il pannello admin **Ultimo payload HIC ricevuto**:
- mostra payload raw ricevuto
- mostra payload normalizzato usato per Brevo
- maschera i campi sensibili (email, telefono, nome/cognome)

## C'è un test live della connessione Brevo?
Sì. Il bottone **Test connessione Brevo** esegue test reale API e salva uno storico degli ultimi 20 test.

## Quali eventi tracking vengono emessi?
Vengono emessi:
- `booking_confirmed`
- `purchase` (se valore > 0)
- `hic_booking_created` (legacy)
- `hic_brevo_booking_synced` (legacy)

## Come mantengo allineata la documentazione?
Aggiorna sempre insieme:
- `README.md`
- `readme.txt`
- `CHANGELOG.md`
- `docs/overview.md`, `docs/architecture.md`, `docs/code-map.md`, `docs/faq.md`
