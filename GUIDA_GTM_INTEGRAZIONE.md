# Integrazione Google Tag Manager e Google Analytics 4

## Panoramica

Il plugin ora supporta **tre modalità di tracciamento** per le conversioni:

1. **Solo GA4 Measurement Protocol** (Server-side)
2. **Solo Google Tag Manager** (Client-side)  
3. **Ibrido** (GTM + GA4 come backup)

Questo ti permette di scegliere la strategia migliore per il tracciamento delle conversioni evitando la doppia misurazione.

## Modalità di Tracciamento

### 1. Solo GA4 Measurement Protocol (Attuale)

**Quando usare**: Se vuoi mantenere il tracciamento server-side attuale, più affidabile e indipendente dal browser.

**Come funziona**:
- Eventi inviati direttamente da server a GA4 via Measurement Protocol
- Non dipende da JavaScript o cookie del browser
- Più resistente ad AdBlocker e privacy settings
- Tracciamento al 100% delle conversioni

**Configurazione**:
```
Modalità Tracciamento: "Solo GA4 Measurement Protocol (Server-side)"
Measurement ID: G-XXXXXXXXXX
API Secret: [da GA4 Measurement Protocol]
```

### 2. Solo Google Tag Manager (Raccomandato per flessibilità)

**Quando usare**: Se vuoi gestire tutti i tag tramite GTM per maggiore flessibilità e controllo centralizzato.

**Come funziona**:
- Eventi inviati al DataLayer di GTM
- GTM gestisce l'invio a GA4, Meta, e altre piattaforme
- Maggiore flessibilità per configurare trigger e condizioni
- Possibilità di gestire multiple piattaforme da un'unica interfaccia

**Configurazione**:
```
Modalità Tracciamento: "Solo Google Tag Manager (Client-side)"
GTM Container ID: GTM-XXXXXXX
Abilita GTM: ✓
```

**Setup GTM richiesto**:
1. Crea trigger per evento `purchase` nel DataLayer
2. Configura tag GA4 che ascolta questo trigger
3. Imposta Enhanced Ecommerce mapping

### 3. Ibrido (Massima copertura)

**Quando usare**: Se vuoi la massima copertura combinando i vantaggi di entrambi gli approcci.

**Come funziona**:
- GTM per tracciamento client-side (maggiore flessibilità)
- GA4 Measurement Protocol come backup server-side (maggiore affidabilità)
- Logica anti-duplicazione automatica

**Configurazione**:
```
Modalità Tracciamento: "Ibrido (GTM + GA4 backup per server-side)"
GTM Container ID: GTM-XXXXXXX
Measurement ID: G-XXXXXXXXXX (stesso di GTM)
API Secret: [da GA4 Measurement Protocol]
```

## Prevenzione Doppia Misurazione

### Strategia Anti-Duplicazione

Il plugin implementa le seguenti strategie per evitare doppia misurazione:

1. **Modalità Exclusive**: Solo una delle due modalità è attiva
2. **Transaction ID Univoci**: Ogni conversione ha un transaction_id unico
3. **Timestamp Differentiation**: Gli eventi hanno timestamp leggermente diversi
4. **Custom Parameters**: Parametri differenti per distinguere fonte dell'evento

### Configurazione GA4 per Evitare Duplicati

Se usi modalità **Ibrido**, configura GA4 per distinguere tra eventi:

**Opzione A: Eventi Separati**
- Server-side: usa evento `purchase_server`
- Client-side GTM: usa evento `purchase_client`

**Opzione B: Dimensioni Personalizzate**
- Crea dimensione personalizzata `event_source`
- Server-side: `event_source = "measurement_protocol"`
- Client-side: `event_source = "gtm_datalayer"`

### Parametri Evento DataLayer GTM

Gli eventi inviati al DataLayer GTM seguono lo standard Enhanced Ecommerce:

```javascript
{
  'event': 'purchase',
  'ecommerce': {
    'transaction_id': 'HIC_12345',
    'affiliation': 'HotelInCloud',
    'value': 150.00,
    'currency': 'EUR',
    'items': [{
      'item_id': 'HIC_12345',
      'item_name': 'Prenotazione Hotel',
      'item_category': 'Hotel',
      'quantity': 1,
      'price': 150.00
    }]
  },
  'bucket': 'organic',        // gads | fbads | organic
  'vertical': 'hotel',
  'method': 'HotelInCloud',
  'gclid': 'abc123...',      // se presente
  'fbclid': 'xyz789...'      // se presente
}
```

## Setup Google Tag Manager

### 1. Configurazione Container GTM

1. **Crea Container**: Se non hai già un container GTM
2. **Installa Codice**: Il plugin installa automaticamente il codice GTM
3. **Verifica**: Controlla che il DataLayer riceva gli eventi

### 2. Configurazione Tag GA4 in GTM

**Variabili Built-in necessarie**:
- Event
- Transaction ID  
- Value
- Currency
- Items

**Trigger**: 
- Tipo: Custom Event
- Nome evento: `purchase`

**Tag GA4**:
- Tipo: Google Analytics: GA4 Event
- Configuration Tag: [Il tuo GA4 Configuration Tag]
- Event Name: `purchase`
- Parameters:
  - `transaction_id`: `{{Transaction ID}}`
  - `value`: `{{Value}}`
  - `currency`: `{{Currency}}`
  - `items`: `{{Items}}`

### 3. Test e Debug

**GTM Preview Mode**:
1. Attiva Preview Mode in GTM
2. Crea prenotazione di test
3. Verifica che l'evento `purchase` appaia nel DataLayer
4. Controlla che il tag GA4 si attivi

**GA4 Real-time Reports**:
1. Vai su GA4 > Reports > Realtime > Events
2. Cerca evento `purchase`
3. Verifica parametri ecommerce

## Raccomandazioni per Diversi Scenari

### Scenario A: Nuovo Setup
**Raccomandazione**: Solo GTM
- Maggiore flessibilità per il futuro
- Gestione centralizzata di tutti i tag
- Standard industry per enterprise

### Scenario B: Setup Esistente GA4
**Raccomandazione**: Mantieni Solo GA4
- Se il setup attuale funziona bene
- Meno complessità di configurazione
- Tracciamento più affidabile

### Scenario C: Massima Precisione
**Raccomandazione**: Modalità Ibrida
- Tracciamento client-side + server-side
- Maggiore copertura delle conversioni
- Backup automatico in caso di problemi

### Scenario D: Múltiple Piattaforme
**Raccomandazione**: Solo GTM
- Gestisci GA4, Meta, LinkedIn, etc. da GTM
- Configurazione una tantum
- Controllo granulare dei trigger

## Migrazione da GA4 a GTM

### Passo 1: Preparazione
1. Configura GTM container
2. Imposta tag GA4 in GTM  
3. Testa in modalità preview

### Passo 2: Switch Graduale
1. Cambia modalità a "Ibrido"
2. Monitora che entrambi i tracciamenti funzionino
3. Dopo 1-2 settimane, switch a "Solo GTM"

### Passo 3: Cleanup
1. Rimuovi Measurement ID e API Secret (opzionale)
2. Mantieni come backup per emergenze

## Troubleshooting

### Problema: Non vedo eventi in GA4 con GTM
**Soluzione**:
1. Verifica che GTM container sia attivo
2. Controlla che il tag GA4 in GTM sia configurato correttamente
3. Usa GTM Preview per debuggare

### Problema: Eventi duplicati in GA4
**Soluzione**:
1. Verifica modalità tracciamento
2. Se in modalità ibrida, configura eventi separati o dimensioni personalizzate
3. Controlla che non ci siano tag GA4 extra nel sito

### Problema: GTM DataLayer non riceve eventi
**Soluzione**:
1. Verifica che GTM sia abilitato nelle impostazioni plugin
2. Controlla che il Container ID sia corretto (formato: GTM-XXXXXXX)
3. Verifica che ci siano prenotazioni in coda da processare

### Problema: Perdita di attribution (gclid/fbclid)
**Soluzione**:
1. GTM conserva i parametri `gclid` e `fbclid` nell'evento
2. Configura Enhanced Attribution in GTM
3. Verifica che i parametri siano passati ai tag finali

## Monitoraggio e Analytics

### Metriche da Monitorare
- **Copertura Eventi**: Confronta volumi tra modalità
- **Attribution Quality**: Verifica bucket assignment
- **Performance**: Tempo di caricamento pagina con GTM
- **Errori**: Monitor console errors legati a GTM

### Report Raccomandati in GA4
1. **Conversioni per Source/Medium**
2. **Enhanced Ecommerce Performance**  
3. **Real-time Events Monitoring**
4. **Custom Report con dimensione `bucket`**

Questo setup ti permette di ottimizzare il tracciamento delle conversioni mantenendo la flessibilità per future esigenze di marketing e analytics.