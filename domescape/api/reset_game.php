<?php

// =============================================================
// reset_game.php — Réinitialiser la session active (Game Master)
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/GameEngine.php';

Auth::init();
if (!Auth::check() || !Auth::hasRole(ROLE_SUPERVISEUR)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Accès refusé.']);
    exit;
}

GameEngine::resetActiveSession();

echo json_encode(['status' => 'ok', 'message' => 'Session réinitialisée.']);
