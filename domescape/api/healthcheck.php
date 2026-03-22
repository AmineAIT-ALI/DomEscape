<?php

// =============================================================
// healthcheck.php — État du système DomEscape
//
// GET /api/healthcheck.php
// Retourne 200 si tout est opérationnel, 503 sinon.
// Pas d'auth requise (utilisé pour monitoring / Raspberry Pi).
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

$checks = [];
$allOk  = true;

// 1. Base de données
try {
    $pdo  = getDB();
    $stmt = $pdo->query("SELECT COUNT(*) FROM session WHERE statut_session = 'en_cours'");
    $activeSessions = (int)$stmt->fetchColumn();

    $checks['database'] = ['status' => 'ok', 'active_sessions' => $activeSessions];
} catch (Throwable $e) {
    $checks['database'] = ['status' => 'error', 'message' => $e->getMessage()];
    $allOk = false;
}

// 2. Domoticz
$domoticzUrl = DOMOTICZ_URL . '/json.htm?type=devices&used=true';
$ctx = stream_context_create(['http' => ['timeout' => 2, 'method' => 'GET']]);
$raw = @file_get_contents($domoticzUrl, false, $ctx);

if ($raw !== false) {
    $data = json_decode($raw, true);
    $checks['domoticz'] = [
        'status'      => 'ok',
        'device_count' => count($data['result'] ?? []),
    ];
} else {
    $checks['domoticz'] = ['status' => 'unavailable'];
    // Non bloquant — hardware optionnel en dev
}

// 3. Service LCD
$lcdUrl = LCD_SERVICE_URL . '/status';
$raw    = @file_get_contents($lcdUrl, false, $ctx);
$checks['lcd'] = $raw !== false
    ? ['status' => 'ok']
    : ['status' => 'unavailable'];

// Réponse
http_response_code($allOk ? 200 : 503);

echo json_encode([
    'status'    => $allOk ? 'ok' : 'degraded',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks'    => $checks,
], JSON_PRETTY_PRINT);
