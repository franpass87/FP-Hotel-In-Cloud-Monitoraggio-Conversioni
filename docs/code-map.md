# FP Hotel in Cloud Monitoraggio Conversioni — Code Map

## Overview
Da versione 3.6.0 il plugin e focalizzato esclusivamente su integrazione HIC -> Brevo.

## Entry point
- `FP-Hotel-In-Cloud-Monitoraggio-Conversioni.php`
  - header plugin e costanti:
    - `FP_HIC_BREVO_VERSION`
    - `FP_HIC_BREVO_FILE`
    - `FP_HIC_BREVO_DIR`
  - bootstrap unico:
    - `require_once includes/simple-brevo-sync.php`
    - `\FpHic\SimpleBrevoSync\Bootstrap::init()`

## Core module
- `includes/simple-brevo-sync.php`
  - **Admin**
    - `registerAdminPage()`
    - `registerSettings()`
    - `renderAdminPage()`
    - `enqueueAdminAssets()`
  - **Webhook**
    - `registerRestRoutes()`
    - `webhookPermission()`
    - `handleWebhook()`
  - **Normalization**
    - `normalizeBooking()`
    - helper scalar/sanitize
  - **Brevo**
    - `sendBrevoContact()`
    - `sendBrevoEvent()` (v3/legacy)
    - `validateBrevoResponse()`
  - **Tracking**
    - `dispatchFpTrackingEvent()`
  - **Admin testing**
    - `ajaxTestBrevoConnection()`
    - `runBrevoConnectionTest()`
    - storico test (`appendBrevoTestHistory()`, `getBrevoTestHistory()`)
  - **Payload debug**
    - `storeLastHicPayload()`
    - `renderLastPayloadPanel()`
    - masking PII (`maskSensitiveData()`, `maskEmail()`, `maskPhone()`, `maskGeneric()`)

## REST surface
| Namespace | Route | Methods | Callback |
| --- | --- | --- | --- |
| `hic/v1` | `/conversion` | `POST` | `Bootstrap::handleWebhook` |

## Admin AJAX surface
| Action | Callback |
| --- | --- |
| `hic_test_brevo_connection` | `Bootstrap::ajaxTestBrevoConnection` |
| `hic_clear_brevo_test_history` | `Bootstrap::ajaxClearBrevoTestHistory` |

## Persistent options principali
- `hic_webhook_token`
- `hic_brevo_api_key`
- `hic_brevo_list_id`
- `hic_brevo_event_mode`
- `hic_brevo_event_endpoint`
- `hic_brevo_event_api_key`
- `hic_enable_fp_tracking`
- `hic_brevo_test_history`
- `hic_last_hic_payload`

## Eventi emessi
- `do_action('fp_tracking_event', 'booking_confirmed', $params)`
- `do_action('fp_tracking_event', 'purchase', $params)`
- `do_action('fp_tracking_event', 'hic_booking_created', $params)`
- `do_action('fp_tracking_event', 'hic_brevo_booking_synced', $params)`
