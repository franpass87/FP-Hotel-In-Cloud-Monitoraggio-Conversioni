# Quick Reference: Attributi Brevo Essenziali

> **Versione plugin:** 3.3.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


## Lista Attributi da Creare in Brevo

Copia e incolla questa lista nel tuo account Brevo (Contacts > Settings > Contact attributes):

### Attributi Principali (Obbligatori)
```
FIRSTNAME - Testo
LASTNAME - Testo
PHONE - Testo  
LANGUAGE - Testo
HIC_RES_ID - Testo
HIC_RES_CODE - Testo
HIC_FROM - Data
HIC_TO - Data
HIC_GUESTS - Numero
HIC_ROOM - Testo
HIC_PRICE - Numero (decimale)
```

### Attributi Legacy (Opzionali)
```
RESVID - Testo
GCLID - Testo
FBCLID - Testo
DATE - Data
AMOUNT - Numero (decimale)
CURRENCY - Testo
WHATSAPP - Testo
LINGUA - Testo
```

## Configurazione Plugin WordPress

Nel pannello admin WordPress (Impostazioni > HIC Monitoring), sezione "Brevo Settings":

1. **API Key**: La tua chiave API Brevo
2. **Lista Italiana**: ID lista per contatti italiani
3. **Lista Inglese**: ID lista per contatti inglesi
4. **Lista Default**: ID lista per altre lingue
5. **Lista Alias**: ID lista per email temporanee OTA (opzionale)
6. Il prefisso telefonico, se presente, ha priorità sul campo `language`:
   - numeri con prefisso `+39` o `0039` vengono forzati sulla lista italiana;
   - numeri con altri prefissi vengono assegnati alla lista inglese;
   - numeri senza prefisso, lunghi 9-10 cifre e che iniziano con `3` o `0` vengono trattati come italiani;
   - se il numero non è riconoscibile si utilizza il valore del campo `language` o, se assente, la lista di default.

## Evento "purchase"

Il plugin invia automaticamente eventi "purchase" con queste proprietà:
- reservation_id, amount, currency, date
- whatsapp, lingua, firstname, lastname, bucket

Questi eventi possono essere usati per automazioni in Brevo.

Ad ogni prenotazione il contatto viene aggiornato e l'evento viene sempre inviato. È possibile disattivare l'invio dell'evento utilizzando il filtro **`hic_brevo_send_event`**:

```php
add_filter('hic_brevo_send_event', function( $send, $data ) {
    // Ritorna false per evitare l'invio dell'evento per questa prenotazione
    return false;
}, 10, 2);
```

### Personalizzare l'evento

Prima dell'invio della richiesta HTTP il plugin applica il filtro **`hic_brevo_event`** che consente di modificare il payload:

```php
add_filter('hic_brevo_event', function( $event_data, $reservation ) {
    // Aggiungi o modifica i dati dell'evento
    return $event_data;
}, 10, 2);
```

- `$event_data`: array dell'evento inviato a Brevo
- `$reservation`: dati originali della prenotazione

## Test API e Diagnostica

Il pulsante **Test API** della diagnosi crea temporaneamente un contatto di prova
utilizzando la lista italiana predefinita. Il contatto viene eliminato subito dopo
il test per mantenere pulite le liste reali.
