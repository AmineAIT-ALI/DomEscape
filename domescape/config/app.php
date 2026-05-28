<?php

// =============================================================
// DomEscape — Configuration applicative
// =============================================================

// URL de l'API Domoticz (Raspberry Pi local)
define('DOMOTICZ_URL',  'http://127.0.0.1:8080');
define('DOMOTICZ_USER', 'admin');
define('DOMOTICZ_PASS', 'domoticz');

// URL du service LCD Python
define('LCD_SERVICE_URL', 'http://localhost:5000');

// Webhook : token chargé depuis config/secrets.php (non commité)
// Copier config/secrets.php.example → config/secrets.php et adapter.
require_once __DIR__ . '/secrets.php';
