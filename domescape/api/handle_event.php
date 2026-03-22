<?php

// =============================================================
// handle_event.php — Webhook Domoticz
//
// Appelé par dzVents à chaque événement capteur.
// Méthode : POST
// Paramètres attendus :
//   token  : jeton de sécurité (WEBHOOK_TOKEN)
//   idx    : identifiant du device dans Domoticz
//   nvalue : valeur numérique de l'état
//   svalue : valeur textuelle de l'état
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../core/EventManager.php';
require_once __DIR__ . '/../core/GameEngine.php';

// 1. Vérification du token
$token = $_POST['token'] ?? $_GET['token'] ?? '';
if ($token !== WEBHOOK_TOKEN) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Token invalide.']);
    exit;
}

// 2. Extraction du payload
$payload = [
    'idx'    => $_POST['idx']    ?? $_GET['idx']    ?? 0,
    'nvalue' => $_POST['nvalue'] ?? $_GET['nvalue'] ?? 0,
    'svalue' => $_POST['svalue'] ?? $_GET['svalue'] ?? '',
];

// DEBUG — log du payload entrant
$logDir = __DIR__ . '/../logs';
if (is_dir($logDir) && is_writable($logDir)) {
    $logLine = '[' . date('Y-m-d H:i:s') . '] [WEBHOOK] payload=' . json_encode($payload) . PHP_EOL;
    @file_put_contents($logDir . '/debug.log', $logLine, FILE_APPEND);
}

// 3. Normalisation via EventManager
$event = EventManager::fromWebhook($payload);

if ($event === null) {
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents($logDir . '/debug.log',
            '[' . date('Y-m-d H:i:s') . '] [IGNORED] idx=' . $payload['idx'] . PHP_EOL, FILE_APPEND);
    }
    http_response_code(200);
    echo json_encode(['status' => 'ignored', 'message' => 'Événement non reconnu ou capteur inconnu.']);
    exit;
}

// DEBUG — log de l'événement normalisé
if (is_dir($logDir) && is_writable($logDir)) {
    $logLine = '[' . date('Y-m-d H:i:s') . '] [NORMALIZED] code=' . $event['code_evenement']
             . ' capteur=' . $event['capteur']['nom_capteur'] . PHP_EOL;
    @file_put_contents($logDir . '/debug.log', $logLine, FILE_APPEND);
}

// 4. Traitement par le GameEngine
GameEngine::process($event);

echo json_encode([
    'status'          => 'ok',
    'code_evenement'  => $event['code_evenement'],
    'capteur'         => $event['capteur']['nom_capteur'],
]);
