<?php

// =============================================================
// send_hint.php — Envoyer l'indice de l'étape courante
//
// Appelé par le Game Master. Incrémente nb_indices sur la session
// et retourne le texte de l'indice de l'étape courante.
// Rôle requis : SUPERVISEUR
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/GameEngine.php';
require_once __DIR__ . '/../config/database.php';

Auth::init();
if (!Auth::check() || !Auth::hasRole(ROLE_SUPERVISEUR)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Accès refusé.']);
    exit;
}

$session = GameEngine::getActiveSession();

if (!$session) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Aucune session active.']);
    exit;
}

$pdo = getDB();

// Indice de l'étape courante
$stmt = $pdo->prepare('SELECT indice FROM etape WHERE id_etape = ? LIMIT 1');
$stmt->execute([$session['id_etape_courante']]);
$etape = $stmt->fetch();

$indice = trim($etape['indice'] ?? '');

if ($indice === '') {
    echo json_encode(['status' => 'no_hint', 'message' => 'Aucun indice défini pour cette étape.']);
    exit;
}

// Incrémenter le compteur d'indices
$pdo->prepare('UPDATE session SET nb_indices = nb_indices + 1 WHERE id_session = ?')
    ->execute([$session['id_session']]);

echo json_encode([
    'status'     => 'ok',
    'indice'     => $indice,
    'nb_indices' => (int)$session['nb_indices'] + 1,
]);
