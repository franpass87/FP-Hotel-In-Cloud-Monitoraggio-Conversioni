# Integrazione Google Tag Manager e Google Analytics 4

> **Versione plugin:** 3.2.0 · **Autore:** Francesco Passeri — [francescopasseri.com](https://francescopasseri.com) — [info@francescopasseri.com](mailto:info@francescopasseri.com)


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

**Variabili personalizzate per attribution**:
- `DLV - bucket` (Data Layer Variable: bucket)
- `DLV - gclid` (Data Layer Variable: gclid) 
- `DLV - fbclid` (Data Layer Variable: fbclid)

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
  - `traffic_source`: `{{DLV - bucket}}` (per distinguere gads/fbads/organic)
  - `gclid`: `{{DLV - gclid}}` (se disponibile)
  - `fbclid`: `{{DLV - fbclid}}` (se disponibile)

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

## Configurazione Attribution Tracking

### Parametri di Provenienza Conservati

Il plugin **conserva automaticamente** tutti i parametri di provenienza:
- `gclid` - Google Ads Click ID 
- `fbclid` - Facebook Click ID
- `bucket` - Classificazione automatica: `gads` | `fbads` | `organic`

### Setup GA4 Custom Dimensions 

Per visualizzare l'attribution in GA4 quando usi GTM:

1. **In GA4**: Vai su Configure > Custom Definitions > Custom Dimensions
2. **Crea dimensioni**:
   - **Traffic Source Bucket**: 
     - Dimension name: `Traffic Source Bucket`
     - Scope: `Event`
     - Event parameter: `traffic_source`
   - **Google Click ID**:
     - Dimension name: `Google Click ID` 
     - Scope: `Event`
     - Event parameter: `gclid`
   - **Facebook Click ID**:
     - Dimension name: `Facebook Click ID`
     - Scope: `Event` 
     - Event parameter: `fbclid`

3. **Verifica**: Dopo 24-48 ore, potrai usare queste dimensioni nei report GA4

### Report Attribution Raccomandato

Crea un report personalizzato con:
- **Metrica primaria**: Conversioni, Revenue
- **Dimensioni**: Traffic Source Bucket, Source/Medium
- **Filtro eventi**: purchase

Questo ti darà visibilità completa sull'attribution delle conversioni.

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
**Causa**: I parametri sono inviati a GTM ma non configurati correttamente in GA4

**Soluzione**:
1. **Verifica DataLayer**: In GTM Preview, controlla che l'evento `purchase` contenga i campi `gclid`, `fbclid`, `bucket`
2. **Configura variabili GTM**:
   - Crea Data Layer Variables per `bucket`, `gclid`, `fbclid`
   - Aggiungi come custom parameters nel tag GA4
3. **Setup GA4 Custom Dimensions**: Segui la guida "Configurazione Attribution Tracking" sopra
4. **Test**: Verifica nei report GA4 Real-time che i parametri arrivino correttamente

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
