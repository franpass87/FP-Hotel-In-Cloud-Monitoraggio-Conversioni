# Attributi Brevo da Configurare

Questo documento elenca tutti gli attributi che devi configurare nel tuo account Brevo per ricevere correttamente i dati dal plugin Hotel in Cloud.

## Attributi Contatto (Contact Attributes)

Il plugin invia i seguenti attributi per ogni contatto creato o aggiornato in Brevo:

### Attributi del Sistema HIC Moderno
Il sistema di polling API moderno (versione attuale) invia **ENTRAMBI** i set di attributi per massima compatibilità:

#### Attributi Moderni (Nuovi)
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
| `HIC_ACCOM_ID` | Testo | ID dell'alloggio | `accommodation_id` dalla prenotazione |
| `HIC_ROOM_ID` | Testo | ID della camera | `room_id` dalla prenotazione |
| `HIC_OFFER` | Testo | Offerta associata | `offer` dalla prenotazione |
| `HIC_PRICE` | Numero | Prezzo originale della prenotazione | `price` dalla prenotazione |
| `HIC_PRESENCE` | Numero (0/1) | Indica se il cliente ha effettuato il check-in | `presence` dalla prenotazione |
| `HIC_BALANCE` | Numero | Saldo non pagato della prenotazione | `unpaid_balance` dalla prenotazione |
| `TAGS` | Testo | Elenco di tag associati al contatto, separati da virgola (inviati anche nel campo `tags` nativo) | Array `tags` dalla prenotazione |

#### Attributi Legacy (Compatibilità)
Il sistema moderno invia **ANCHE** questi attributi legacy per garantire la retrocompatibilità:

| Attributo | Tipo | Descrizione | Fonte Dati |
|-----------|------|-------------|-------------|
| `RESVID` | Testo | ID prenotazione | Mappato da `transaction_id` |
| `GCLID` | Testo | Google Click ID (tracciamento Google Ads) | Recuperato automaticamente dal database* |
| `FBCLID` | Testo | Facebook Click ID (tracciamento Meta) | Recuperato automaticamente dal database* |
| `DATE` | Data | Data della prenotazione | Mappato da `from_date` |
| `AMOUNT` | Numero | Importo della prenotazione | Mappato da `original_price` |
| `CURRENCY` | Testo | Valuta (es. "EUR", "USD") | `currency` dalla prenotazione |
| `PHONE` | Testo | Numero di telefono | `phone` dalla prenotazione |
| `WHATSAPP` | Testo | Numero WhatsApp | Mappato da `phone` |
| `LINGUA` | Testo | Lingua (alias legacy di `LANGUAGE`) | Mappato da `language` |
> **Nota:** l'attributo `LANGUAGE` viene sempre inviato insieme a `LINGUA` per garantire la retro-compatibilità.
>

**\*Nota sui tracking ID**: Nel sistema API moderno, `GCLID` e `FBCLID` sono disponibili solo se la prenotazione è stata originariamente tracciata attraverso il sito web con parametri di tracking. Se la prenotazione è stata creata direttamente in Hotel in Cloud senza passare dal sito web, questi campi saranno vuoti.

### Solo Sistema Webhook Legacy
Il vecchio sistema webhook (ancora supportato) utilizza solo gli attributi legacy sopra elencati, con la mappatura diretta dai campi webhook originali.

**✅ Compatibilità Completa**: Ora il sistema API moderno invia **ENTRAMBI** i set di attributi, quindi puoi utilizzare sia i vecchi nomi attributi che i nuovi in Brevo, indipendentemente dal sistema di connessione scelto.

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
HIC_ACCOM_ID - Tipo: Testo
HIC_ROOM_ID - Tipo: Testo
HIC_OFFER - Tipo: Testo
HIC_PRICE - Tipo: Numero (decimale)
HIC_PRESENCE - Tipo: Numero
HIC_BALANCE - Tipo: Numero (decimale)
TAGS - Tipo: Testo
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
- **Lista Default**: Fallback per lingue mancanti o diverse da "it" e "en"
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