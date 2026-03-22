# FP HIC Monitor

Plugin WordPress minimale che riceve nuove prenotazioni da Hotel in Cloud e sincronizza contatto + evento su Brevo.

## Plugin information

| Campo | Valore |
| --- | --- |
| Nome | FP HIC Monitor |
| Versione | 3.6.0 |
| Autore | [Francesco Passeri](https://francescopasseri.com) ([info@francescopasseri.com](mailto:info@francescopasseri.com)) |
| Autore URI | https://francescopasseri.com |
| Plugin URI | https://francescopasseri.com |
| Requires at least | WordPress 6.0 |
| Tested up to | WordPress 6.6 |
| Requires PHP | 8.0 |
| Licenza | GPLv2 or later |
| Text Domain | `hotel-in-cloud` (Domain Path: `/languages`) |

## Cosa fa
- Espone un webhook `POST /wp-json/hic/v1/conversion?token=<TOKEN>`.
- Normalizza i campi prenotazione (arrivo, partenza, data prenotazione, anagrafica cliente).
- Crea/aggiorna il contatto su Brevo (`/v3/contacts`).
- Invia evento su Brevo (modalita `v3` consigliata o `legacy` compatibile).
- Emette anche `fp_tracking_event` per integrazione con FP Marketing Tracking Layer.

## Configurazione
1. Attiva il plugin.
2. Vai su `Impostazioni -> FP HIC -> Brevo`.
3. Imposta:
   - Token webhook
   - Brevo API Key
   - Brevo List ID (opzionale)
   - Event mode (`v3` consigliato)
   - Event endpoint (default `https://api.brevo.com/v3/events`)
   - Event API Key (opzionale)
4. Salva.

## Validazione live
Nella pagina admin trovi:
- **Test connessione Brevo**: verifica reale account, contatto ed evento.
- **Storico ultimi test connessione**: ultime 20 esecuzioni.
- **Svuota storico test**.
- **Ultimo payload HIC ricevuto (mascherato)**: mostra payload raw + payload normalizzato, con masking di email/telefono/nome/cognome per controllo campi.

## Eventi tracking emessi
| Evento | Quando parte | Note payload |
| --- | --- | --- |
| `booking_confirmed` | Dopo sync Brevo riuscita, stato confermato o mancante. | Include `reservation_id`, `transaction_id`, `value`, `currency`, `status`, date e `user_data`. |
| `purchase` | Dopo sync Brevo riuscita con stato confermato e `value > 0`. | Evento canonico revenue cross-plugin. |
| `hic_booking_created` | Sempre dopo sync Brevo riuscita. | Legacy per retrocompatibilita. |
| `hic_brevo_booking_synced` | Sempre dopo sync Brevo riuscita. | Legacy per retrocompatibilita. |

## Note operative
- Il plugin **non invia email direttamente**: invia dati/eventi a Brevo, dove configuri le automazioni (prenotazione, arrivo, partenza).
- Le email sono necessarie per creare/sincronizzare contatti ed eventi.
- I payload vengono deduplicati per evitare doppio invio.

## Changelog
Cronologia completa in [CHANGELOG.md](CHANGELOG.md).

## Autore

**Francesco Passeri**
- Sito: [francescopasseri.com](https://francescopasseri.com)
- Email: [info@francescopasseri.com](mailto:info@francescopasseri.com)
- GitHub: [github.com/franpass87](https://github.com/franpass87)
