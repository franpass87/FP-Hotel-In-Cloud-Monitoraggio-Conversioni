# FP-Hotel-In-Cloud-Monitoraggio-Conversioni

Plugin WordPress per il tracciamento delle conversioni da Hotel in Cloud verso GA4, Facebook Meta e Brevo.

## Configurazione API

### API Hotel in Cloud

Il plugin supporta due metodi di autenticazione per le API Hotel in Cloud:

#### Basic Authentication (Raccomandato)
- **API Base URL**: `https://api.hotelincloud.com/api/partner`
- **API Email**: Email del tuo account Hotel in Cloud
- **API Password**: Password del tuo account Hotel in Cloud  
- **ID Struttura (propId)**: ID numerico della tua struttura

#### Configurazione tramite costanti PHP (Opzionale)

Per maggiore sicurezza, puoi definire le credenziali come costanti PHP nel file `wp-config.php`:

```php
define('HIC_API_EMAIL','email@example.com');
define('HIC_API_PASSWORD','***');
define('HIC_PROPERTY_ID', 355787);
```

Le costanti PHP hanno priorità sui valori inseriti nell'interfaccia admin.

### Esecuzione manuale

Per testare l'integrazione API, puoi eseguire manualmente una chiamata:

```php
do_action('hic_fetch_reservations', 355787, 'presence', '2025-08-01', '2025-08-31', 50);
```

Parametri:
- `propId`: ID della struttura
- `date_type`: Tipo di data (`checkin`, `checkout`, `presence`)
- `from_date`: Data inizio (formato Y-m-d)
- `to_date`: Data fine (formato Y-m-d)  
- `limit`: Numero massimo di risultati (opzionale)

## Note su Privacy e Rate Limits

- Il plugin rispetta i rate limits delle API Hotel in Cloud
- I dati sensibili vengono loggati in forma ridotta per proteggere la privacy
- Le credenziali API non vengono mai loggate