<?php

// =============================================================
// session_status.php — État de la session en cours
//
// Appelé par le frontend toutes les secondes (polling JS).
// Retourne l'état complet de la partie active.
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/GameEngine.php';

// Guard JSON-friendly : renvoie 401 au lieu de rediriger
Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'unauthenticated']);
    exit;
}

$session = GameEngine::getActiveSession();

if ($session === null) {
    echo json_encode(['status' => 'no_session']);
    exit;
}

$pdo = getDB();

// Étape courante
$etapeStmt = $pdo->prepare("SELECT * FROM etape WHERE id_etape = ? LIMIT 1");
$etapeStmt->execute([$session['id_etape_courante']]);
$etape = $etapeStmt->fetch();

// Joueur
$joueurStmt = $pdo->prepare("SELECT * FROM joueur WHERE id_joueur = ? LIMIT 1");
$joueurStmt->execute([$session['id_joueur']]);
$joueur = $joueurStmt->fetch();

// Scénario
$scenarioStmt = $pdo->prepare("SELECT * FROM scenario WHERE id_scenario = ? LIMIT 1");
$scenarioStmt->execute([$session['id_scenario']]);
$scenario = $scenarioStmt->fetch();

// Nombre total d'étapes du scénario
$totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM etape WHERE id_scenario = ?");
$totalStmt->execute([$session['id_scenario']]);
$total = (int)$totalStmt->fetch()['total'];

// Durée écoulée
$elapsed = 0;
if ($session['date_debut']) {
    $elapsed = time() - strtotime($session['date_debut']);
}

echo json_encode([
    'status'          => $session['statut_session'],
    'session_id'      => $session['id_session'],
    'joueur'          => $joueur['nom_joueur'] ?? 'Équipe inconnue',
    'scenario'        => $scenario['nom_scenario'] ?? '',
    'score'           => $session['score'],
    'nb_erreurs'      => $session['nb_erreurs'],
    'elapsed_seconds' => $elapsed,
    'etape' => [
        'id'          => $etape['id_etape']      ?? null,
        'numero'      => $etape['numero_etape']  ?? null,
        'titre'       => $etape['titre_etape']   ?? '',
        'description' => $etape['description_etape'] ?? '',
        'finale'      => (bool)($etape['finale'] ?? false),
    ],
    'total_etapes' => $total,
]);
