# Quick Reference: Attributi Brevo Essenziali

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

## Evento "purchase"

Il plugin invia automaticamente eventi "purchase" con queste proprietà:
- reservation_id, amount, currency, date
- whatsapp, lingua, firstname, lastname, bucket

Questi eventi possono essere usati per automazioni in Brevo.

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