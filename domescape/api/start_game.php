<?php

// start_game.php — Créer une nouvelle session
// POST :
//   id_scenario : id du scénario
//   nom_equipe  : nom de l'équipe / du joueur

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/GameEngine.php';

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non authentifié.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée.']);
    exit;
}

// Accepte nom_equipe (nouveau) ou nom_joueur (ancien — rétrocompat formulaire)
$idScenario = (int) ($_POST['id_scenario'] ?? 0);
$nomEquipe  = trim($_POST['nom_equipe'] ?? $_POST['nom_joueur'] ?? '');

if ($idScenario === 0 || $nomEquipe === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paramètres manquants (id_scenario, nom_equipe).']);
    exit;
}

$pdo = getDB();

// Règle mono-salle : bloquer si une session en_cours existe déjà
$sessionActive = $pdo->query("
    SELECT id_session FROM session WHERE statut_session = 'en_cours' LIMIT 1
")->fetch();

if ($sessionActive) {
    http_response_code(409);
    echo json_encode([
        'status'     => 'session_exists',
        'id_session' => $sessionActive['id_session'],
        'message'    => 'Une session est déjà en cours.',
    ]);
    exit;
}

try {
    $idSession = GameEngine::startSession($idScenario, $nomEquipe);

    echo json_encode([
        'status'     => 'ok',
        'id_session' => $idSession,
        'message'    => 'Partie démarrée.',
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
