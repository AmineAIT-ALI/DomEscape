<?php

// =============================================================
// DomEscape — Configuration applicative
// =============================================================

// URL de l'API Domoticz (Raspberry Pi local)
define('DOMOTICZ_URL', 'http://localhost:8080');

// URL du service LCD Python
define('LCD_SERVICE_URL', 'http://localhost:5000');

// Durée max d'une session sans activité (secondes)
define('SESSION_TIMEOUT', 3600);

// Webhook : token chargé depuis config/secrets.php (non commité)
// Copier config/secrets.php.example → config/secrets.php et adapter.
require_once __DIR__ . '/secrets.php';
