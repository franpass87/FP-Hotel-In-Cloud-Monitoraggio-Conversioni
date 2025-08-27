# Attributi Brevo da Configurare

Questo documento elenca tutti gli attributi che devi configurare nel tuo account Brevo per ricevere correttamente i dati dal plugin Hotel in Cloud.

## Attributi Contatto (Contact Attributes)

Il plugin invia i seguenti attributi per ogni contatto creato o aggiornato in Brevo:

### Attributi Principali del Sistema HIC (Versione Attuale)
Questi sono gli attributi utilizzati dal sistema di polling API moderno:

| Attributo | Tipo | Descrizione | Fonte Dati |
|-----------|------|-------------|-------------|
| `FIRSTNAME` | Testo | Nome del cliente | `guest_first_name` dalla prenotazione |
| `LASTNAME` | Testo | Cognome del cliente | `guest_last_name` dalla prenotazione |
| `PHONE` | Testo | Numero di telefono | `phone` dalla prenotazione |
| `LANGUAGE` | Testo | Lingua del cliente (codice 2 lettere, es. "it", "en") | `language` dalla prenotazione |
| `HIC_RES_ID` | Testo/Numero | ID univoco della prenotazione | `transaction_id` dalla prenotazione |
| `HIC_RES_CODE` | Testo | Codice prenotazione leggibile | `reservation_code` dalla prenotazione |
| `HIC_FROM` | Data | Data di check-in | `from_date` dalla prenotazione |
| `HIC_TO` | Data | Data di check-out | `to_date` dalla prenotazione |
| `HIC_GUESTS` | Numero | Numero di ospiti | `guests` dalla prenotazione |
| `HIC_ROOM` | Testo | Nome dell'alloggio/camera | `accommodation_name` dalla prenotazione |
| `HIC_PRICE` | Numero | Prezzo originale della prenotazione | `price` dalla prenotazione |

### Attributi Legacy (Sistema Webhook)
Questi attributi sono utilizzati dal sistema webhook legacy e potrebbero ancora essere presenti:

| Attributo | Tipo | Descrizione | Fonte Dati |
|-----------|------|-------------|-------------|
| `FIRSTNAME` | Testo | Nome del cliente | `first_name` |
| `LASTNAME` | Testo | Cognome del cliente | `last_name` |
| `RESVID` | Testo | ID prenotazione | `reservation_id` o `id` |
| `GCLID` | Testo | Google Click ID (tracciamento Google Ads) | Tracciamento automatico |
| `FBCLID` | Testo | Facebook Click ID (tracciamento Meta) | Tracciamento automatico |
| `DATE` | Data | Data della prenotazione | `date` o data corrente |
| `AMOUNT` | Numero | Importo della prenotazione | `amount` |
| `CURRENCY` | Testo | Valuta (es. "EUR", "USD") | `currency` |
| `WHATSAPP` | Testo | Numero WhatsApp | `whatsapp` |
| `LINGUA` | Testo | Lingua | `lingua` o `lang` |

## Eventi Brevo (Brevo Events)

Il plugin invia anche eventi personalizzati a Brevo con le seguenti proprietà:

### Evento "purchase"
| Proprietà | Tipo | Descrizione |
|-----------|------|-------------|
| `reservation_id` | Testo | ID della prenotazione |
| `amount` | Numero | Importo della prenotazione |
| `currency` | Testo | Valuta |
| `date` | Data | Data della prenotazione |
| `whatsapp` | Testo | Numero WhatsApp |
| `lingua` | Testo | Lingua |
| `firstname` | Testo | Nome |
| `lastname` | Testo | Cognome |
| `bucket` | Testo | Categoria di attribuzione (Direct, Google, Facebook, etc.) |

## Configurazione Raccomandata in Brevo

### 1. Creazione Attributi Contatto
Nel tuo account Brevo, vai su **Contacts > Settings > Contact attributes** e crea i seguenti attributi:

#### Attributi Obbligatori (Sistema Moderno)
```
FIRSTNAME - Tipo: Testo
LASTNAME - Tipo: Testo  
PHONE - Tipo: Testo
LANGUAGE - Tipo: Testo
HIC_RES_ID - Tipo: Testo
HIC_RES_CODE - Tipo: Testo
HIC_FROM - Tipo: Data
HIC_TO - Tipo: Data
HIC_GUESTS - Tipo: Numero
HIC_ROOM - Tipo: Testo
HIC_PRICE - Tipo: Numero (decimale)
```

#### Attributi Opzionali (Compatibilità Legacy)
```
RESVID - Tipo: Testo
GCLID - Tipo: Testo
FBCLID - Tipo: Testo
DATE - Tipo: Data
AMOUNT - Tipo: Numero (decimale)
CURRENCY - Tipo: Testo
WHATSAPP - Tipo: Testo
LINGUA - Tipo: Testo
```

### 2. Configurazione Liste
Il plugin gestisce automaticamente l'assegnazione ai seguenti tipi di liste:

- **Lista Italiana**: Per contatti con lingua "it"
- **Lista Inglese**: Per contatti con lingua "en"  
- **Lista Default**: Per altre lingue
- **Lista Alias**: Per email temporanee di OTA (Booking.com, Airbnb, etc.)

Configura gli ID di queste liste nel pannello admin del plugin WordPress.

### 3. Configurazione Eventi Personalizzati
Se utilizzi Brevo Automation, puoi creare automazioni basate sull'evento "purchase" e utilizzare le proprietà elencate sopra.

## Gestione Email Alias e Enrichment

Il sistema include funzionalità avanzate per gestire le email alias degli OTA:

- **Email Alias**: Email temporanee di Booking.com, Airbnb, etc. vengono riconosciute automaticamente
- **Enrichment**: Quando arriva l'email reale del cliente, il contatto viene aggiornato automaticamente
- **Double Opt-in**: Opzionale per inviare conferma opt-in quando arriva l'email reale

## Note Importanti

1. **Tipi di Dati**: Assicurati che i tipi di attributi in Brevo corrispondano a quelli indicati (Testo, Numero, Data)
2. **Nomi Esatti**: I nomi degli attributi devono corrispondere esattamente a quelli elencati (case-sensitive)
3. **Attributi Vuoti**: Il plugin filtra automaticamente i valori vuoti prima dell'invio
4. **Aggiornamenti**: Gli attributi vengono aggiornati ad ogni nuova prenotazione dello stesso cliente

Per configurare correttamente il plugin, vai su **WordPress Admin > Impostazioni > HIC Monitoring** e compila la sezione "Brevo Settings" con la tua API key e gli ID delle liste.