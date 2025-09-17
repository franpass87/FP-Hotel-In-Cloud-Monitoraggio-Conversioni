<?php
/**
 * Dimostrazione Webhook - Come il webhook risolve il problema del mancato redirect
 * 
 * Questo script dimostra come il webhook di Hotel in Cloud permette di tracciare
 * le conversioni anche quando l'utente non torna sul sito WordPress.
 */

// Simula una situazione reale
echo "=== DIMOSTRAZIONE: WEBHOOK RISOLVE PROBLEMA REDIRECT ===\n\n";

echo "🎯 PROBLEMA ORIGINALE:\n";
echo "- Cliente prenota su Hotel in Cloud\n";
echo "- Thank you page rimane su dominio HIC\n";
echo "- NESSUN redirect al sito WordPress\n";
echo "- Come tracciare la conversione?\n\n";

echo "✅ SOLUZIONE WEBHOOK:\n";
echo "- Hotel in Cloud invia automaticamente webhook\n";
echo "- WordPress riceve dati immediatamente\n";
echo "- Conversione tracciata automaticamente\n";
echo "- Indipendente dalla posizione dell'utente\n\n";

echo "🔄 FLUSSO OPERATIVO:\n\n";

// Step 1: Cliente completa prenotazione
echo "1. 👤 Cliente completa prenotazione su HIC\n";
echo "   URL: https://booking.hotelincloud.com/...\n";
echo "   Status: Thank you page mostrata su dominio HIC\n\n";

// Step 2: HIC invia webhook
echo "2. 📡 HIC invia webhook automaticamente\n";
echo "   POST https://tuosito.com/wp-json/hic/v1/conversion?token=ABC123\n";
echo "   Content-Type: application/json\n\n";

// Esempio payload webhook
$webhook_payload = [
    'email' => 'mario.rossi@example.com',
    'reservation_id' => 'HIC_12345',
    'guest_first_name' => 'Mario',
    'guest_last_name' => 'Rossi',
    'amount' => 199.99,
    'currency' => 'EUR',
    'checkin' => '2025-06-01',
    'checkout' => '2025-06-07',
    'room' => 'Camera Deluxe',
    'guests' => 2,
    'language' => 'it'
];

echo "   Payload JSON:\n";
echo "   " . json_encode($webhook_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

// Step 3: WordPress elabora
echo "3. ⚙️  WordPress elabora webhook\n";
echo "   - Valida token di sicurezza\n";
echo "   - Verifica formato dati\n";
echo "   - Previene duplicati\n";
echo "   - Processa conversione\n\n";

// Step 4: Invii automatici
echo "4. 📊 Invii automatici a piattaforme:\n\n";

// GA4
echo "   📈 Google Analytics 4:\n";
$ga4_event = [
    'client_id' => 'client_' . md5($webhook_payload['email']),
    'events' => [[
        'name' => 'purchase',
        'params' => [
            'transaction_id' => $webhook_payload['reservation_id'],
            'currency' => $webhook_payload['currency'],
            'value' => $webhook_payload['amount'],
            'bucket' => 'organic',
            'vertical' => 'hotel'
        ]
    ]]
];
echo "   " . json_encode($ga4_event, JSON_PRETTY_PRINT) . "\n\n";

// Meta
echo "   📱 Meta/Facebook:\n";
$meta_event = [
    'data' => [[
        'event_name' => 'Purchase',
        'event_time' => time(),
        'user_data' => [
            'email' => hash('sha256', strtolower($webhook_payload['email']))
        ],
        'custom_data' => [
            'currency' => $webhook_payload['currency'],
            'value' => $webhook_payload['amount'],
            'bucket' => 'organic',
            'vertical' => 'hotel'
        ]
    ]]
];
echo "   " . json_encode($meta_event, JSON_PRETTY_PRINT) . "\n\n";

// Brevo
echo "   📧 Brevo:\n";
$brevo_event = [
    'event' => 'purchase',
    'email' => $webhook_payload['email'],
    'properties' => [
        'reservation_id' => $webhook_payload['reservation_id'],
        'amount' => $webhook_payload['amount'],
        'currency' => $webhook_payload['currency'],
        'bucket' => 'organic',
        'vertical' => 'hotel'
    ]
];
echo "   " . json_encode($brevo_event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "5. ✅ RISULTATO:\n";
echo "   - Conversione tracciata con successo\n";
echo "   - Tutti i sistemi hanno ricevuto i dati\n";
echo "   - Utente può rimanere su HIC senza problemi\n";
echo "   - Nessuna perdita di tracciamento\n\n";

echo "🎉 PROBLEMA RISOLTO!\n\n";

echo "📖 VANTAGGI CHIAVE:\n";
echo "✓ Tracciamento garantito al 100%\n";
echo "✓ Tempo reale (immediato)\n";
echo "✓ Indipendente dal comportamento utente\n";
echo "✓ Server-to-server (più sicuro)\n";
echo "✓ Supporta multi-platform\n";
echo "✓ Nessuna perdita dati\n";
echo "✓ Funziona anche se utente chiude browser\n\n";

echo "📋 SETUP NECESSARIO:\n";
echo "1. Configurare webhook token in WordPress\n";
echo "2. Comunicare URL webhook a Hotel in Cloud\n";
echo "3. Testare con payload di esempio\n";
echo "4. Monitorare log per verifica\n\n";

echo "URL WEBHOOK ESEMPIO:\n";
echo "https://tuosito.com/wp-json/hic/v1/conversion?token=hic2025ga4\n\n";

echo "Per setup completo vedi: GUIDA_WEBHOOK_CONVERSIONI.md\n";