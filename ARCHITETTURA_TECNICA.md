# Architettura Tecnica del Sistema

## Diagramma del Flusso Dati

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            HOTEL IN CLOUD (HIC)                             │
│                         Sistema di Gestione Hotel                           │
└─────────────────────┬─────────────────┬─────────────────────────────────────┘
                      │                 │
              ┌───────▼────────┐   ┌────▼────────────────────┐
              │   Webhook      │   │    API Endpoint         │
              │   (Tempo       │   │    /reservations        │
              │    Reale)      │   │    (Polling)           │
              └───────┬────────┘   └────┬────────────────────┘
                      │                 │
                      ▼                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        WORDPRESS PLUGIN                                     │
│                         FP HIC Monitor                                      │
│                                                                             │
│  ┌─────────────────┐    ┌──────────────────────────────────────────────┐   │
│  │   Webhook       │    │           Polling System                     │   │
│  │   Handler       │    │      (HIC_Booking_Poller)                   │   │
│  │ /wp-json/hic/   │    │                                              │   │
│  │ v1/conversion   │    │  • Controlla ogni 1-5 minuti                │   │
│  └─────────┬───────┘    │  • Lock anti-overlap                        │   │
│            │            │  • Watchdog system                          │   │
│            │            │  • Indipendente da WP-Cron                  │   │
│            │            └──────────────────┬───────────────────────────┘   │
│            │                               │                               │
│            └───────────┬───────────────────┘                               │
│                        │                                                   │
│                        ▼                                                   │
│            ┌──────────────────────────────────────────────┐                │
│            │         hic_process_booking_data()           │                │
│            │                                              │                │
│            │  1. Validazione dati                        │                │
│            │  2. Recupero tracking IDs (gclid/fbclid)    │                │
│            │  3. Normalizzazione bucket attribution      │                │
│            │  4. Invio parallelo a integrazioni          │                │
│            └──────────────────┬───────────────────────────┘                │
│                               │                                           │
└───────────────────────────────┼───────────────────────────────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
        ▼                       ▼                       ▼
┌─────────────┐      ┌─────────────────┐      ┌─────────────────┐
│    GA4      │      │      META       │      │     BREVO       │
│             │      │   (Facebook)    │      │                 │
│ • Purchase  │      │                 │      │ • Contact       │
│   Event     │      │ • Purchase      │      │   Creation      │
│ • Client    │      │   Event         │      │ • Purchase      │
│   ID        │      │ • Custom Data   │      │   Event         │
│ • Value     │      │ • Attribution   │      │ • List          │
│ • Bucket    │      │   Data          │      │   Assignment    │
│ • Vertical  │      │                 │      │ • Email         │
│             │      │                 │      │   Enrichment    │
└─────────────┘      └─────────────────┘      └─────────────────┘
```

## Dettaglio Sistema di Polling

```
┌─────────────────────────────────────────────────────────────────────────┐
│                      HIC_Booking_Poller Class                           │
│                     Sistema di Scheduling Interno                       │
└─────────────────────────────────────────────────────────────────────────┘

Inizializzazione:
├── add_filter('cron_schedules') → Registra intervalli personalizzati
├── add_action('init') → Inizializza scheduler
└── add_action('hic_reliable_poll_event') → Handler esecuzione

Ciclo di Polling:
┌─────────────────┐
│  init_scheduler │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐     NO     ┌─────────────────────┐
│   should_poll() ├───────────►│ clear_scheduled_    │
└─────────┬───────┘            │ events()            │
          │ YES                └─────────────────────┘
          ▼
┌─────────────────┐
│ ensure_scheduled│
│ _event()        │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ run_watchdog_   │
│ check()         │
└─────────────────┘

Esecuzione Poll:
┌─────────────────┐
│ execute_poll()  │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐     FAIL    ┌─────────────────────┐
│ acquire_lock()  ├────────────►│ log: poll_skipped_  │
└─────────┬───────┘             │ lock                │
          │ SUCCESS             └─────────────────────┘
          ▼
┌─────────────────┐
│ perform_polling │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ release_lock()  │
└─────────────────┘
```

## Bucket Attribution System

```
Funzione: fp_normalize_bucket($gclid, $fbclid)

Input: 
├── $gclid  (Google Click ID)
└── $fbclid (Facebook Click ID)

Logica di Priorità:
┌─────────────────┐     YES     ┌─────────────────┐
│ gclid exists?   ├────────────►│ return "gads"   │
└─────────┬───────┘             └─────────────────┘
          │ NO
          ▼
┌─────────────────┐     YES     ┌─────────────────┐
│ fbclid exists?  ├────────────►│ return "fbads"  │
└─────────┬───────┘             └─────────────────┘
          │ NO
          ▼
┌─────────────────┐
│ return "organic"│
└─────────────────┘

Utilizzo del Bucket:
├── GA4: parametro personalizzato purchase event
├── Meta: custom_data nell'evento Purchase
└── Brevo: proprietà nell'evento purchase
```

## Email Enrichment Flow

```
┌─────────────────────────────────────────────────────────────────────────┐
│                       Email Enrichment System                           │
│                   Gestione Email Alias da OTA                          │
└─────────────────────────────────────────────────────────────────────────┘

Prima Prenotazione (Email Alias):
┌─────────────────┐
│ Booking.com     │
│ guest@booking   │
│ .com            │
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ hic_is_ota_     │
│ alias_email()   │ → TRUE
└─────────┬───────┘
          │
          ▼
┌─────────────────┐
│ Brevo:          │
│ • Lista alias   │
│ • NO opt-in     │
│ • Temporaneo    │
└─────────────────┘

Updates Polling:
┌─────────────────┐     ┌─────────────────┐
│ GET /          │────►│ Email reale     │
│ reservations_   │     │ rilevata        │
│ updates         │     └─────────┬───────┘
└─────────────────┘               │
                                  ▼
                  ┌─────────────────┐
                  │ hic_dispatch_   │
                  │ brevo_          │
                  │ reservation()   │
                  └─────────┬───────┘
                            │
                            ▼
                  ┌─────────────────┐
                  │ Brevo:          │
                  │ • Email reale   │
                  │ • Liste corrette│
                  │ • Opt-in se     │
                  │   configurato   │
                  └─────────────────┘
```

## Configurazioni e Dipendenze

```
Modalità Webhook:
├── Webhook Token (sicurezza)
├── URL: /wp-json/hic/v1/conversion?token=xxx
└── Dipende da HIC per inviare webhook

Modalità API Polling:
├── HIC API Credentials
│   ├── Email account HIC
│   ├── Password account HIC  
│   └── Property ID
├── Intervallo polling (1-5 min)
└── Sistema interno indipendente

Integrazioni:
├── GA4
│   ├── Measurement ID
│   └── API Secret
├── Meta/Facebook
│   ├── Pixel ID
│   └── Access Token
└── Brevo
    ├── API Key
    ├── Liste contatti (IT/EN)
    └── Configurazioni enrichment
```

Questo sistema garantisce un tracciamento completo e affidabile di tutte le conversioni da Hotel in Cloud verso le principali piattaforme di analytics e marketing automation.