<?php

// =============================================================
// debug_event.php — Simulation d'événements sans hardware
//
// Usage (GET ou POST) :
//   ?event=BUTTON_PRESS
//   ?event=DOOR_OPEN
//   ?event=MOTION_DETECTED
//   ?event=KEYFOB_BUTTON_3
//
// Pas de token requis — endpoint réservé au développement.
// Mappe le code événement aux vrais idx du seed data.
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/EventManager.php';
require_once __DIR__ . '/../core/GameEngine.php';

Auth::init();
if (!Auth::check() || !Auth::hasRole(ROLE_ADMINISTRATEUR)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Accès refusé. Rôle administrateur requis.']);
    exit;
}

// Mapping code → payload Domoticz (idx = seed data réels)
// Fibaro Button idx=5 | Door Sensor idx=8 | Multisensor idx=10 | Keyfob idx=7
$mapping = [
    'BUTTON_PRESS'    => ['idx' => 5,  'nvalue' => 1, 'svalue' => ''],
    'DOOR_OPEN'       => ['idx' => 8,  'nvalue' => 1, 'svalue' => ''],
    'DOOR_CLOSE'      => ['idx' => 8,  'nvalue' => 0, 'svalue' => ''],
    'MOTION_DETECTED' => ['idx' => 10, 'nvalue' => 1, 'svalue' => ''],
    'NO_MOTION'       => ['idx' => 10, 'nvalue' => 0, 'svalue' => ''],
    'KEYFOB_BUTTON_1' => ['idx' => 7,  'nvalue' => 1, 'svalue' => ''],
    'KEYFOB_BUTTON_2' => ['idx' => 7,  'nvalue' => 2, 'svalue' => ''],
    'KEYFOB_BUTTON_3' => ['idx' => 7,  'nvalue' => 3, 'svalue' => ''],
    'KEYFOB_BUTTON_4' => ['idx' => 7,  'nvalue' => 4, 'svalue' => ''],
    'KEYFOB_BUTTON_5' => ['idx' => 7,  'nvalue' => 5, 'svalue' => ''],
    'KEYFOB_BUTTON_6' => ['idx' => 7,  'nvalue' => 6, 'svalue' => ''],
];

$eventCode = strtoupper(trim($_GET['event'] ?? $_POST['event'] ?? ''));

if ($eventCode === '') {
    http_response_code(400);
    echo json_encode([
        'status'    => 'error',
        'message'   => 'Paramètre event manquant.',
        'available' => array_keys($mapping),
    ]);
    exit;
}

if (!isset($mapping[$eventCode])) {
    http_response_code(400);
    echo json_encode([
        'status'    => 'error',
        'message'   => "Code événement '$eventCode' inconnu.",
        'available' => array_keys($mapping),
    ]);
    exit;
}

$payload = $mapping[$eventCode];

// Log de simulation
$logLine = '[' . date('Y-m-d H:i:s') . '] [SIMULATE] event=' . $eventCode
         . ' idx=' . $payload['idx'] . ' nvalue=' . $payload['nvalue'] . PHP_EOL;
@file_put_contents(__DIR__ . '/../logs/debug.log', $logLine, FILE_APPEND);

// Normalisation via EventManager
$event = EventManager::fromWebhook($payload);

if ($event === null) {
    echo json_encode([
        'status'    => 'ignored',
        'simulated' => $eventCode,
        'message'   => 'Capteur non reconnu ou événement impossible à normaliser.',
    ]);
    exit;
}

// Traitement par le moteur
GameEngine::process($event);

echo json_encode([
    'status'         => 'ok',
    'simulated'      => $eventCode,
    'code_evenement' => $event['code_evenement'],
    'capteur'        => $event['capteur']['nom_capteur'],
    'idx'            => $payload['idx'],
    'nvalue'         => $payload['nvalue'],
]);
