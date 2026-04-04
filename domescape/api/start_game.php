<?php

// =============================================================
// start_game.php — Démarrer une nouvelle partie
//
// POST :
//   id_scenario : id du scénario
//   nom_joueur  : nom de l'équipe / joueur
// =============================================================

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

$idScenario = (int)(  $_POST['id_scenario'] ?? 0);
$nomJoueur  = trim(   $_POST['nom_joueur']  ?? '');
$idSalle    = (int)(  $_POST['id_salle']    ?? 0);

if ($idScenario === 0 || $nomJoueur === '' || $idSalle === 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paramètres manquants (id_scenario, nom_joueur, id_salle).']);
    exit;
}

// S'il y a déjà une session active, la réinitialiser
GameEngine::resetActiveSession();

$pdo = getDB();

// Récupérer l'utilisateur connecté pour lier le joueur
$authUser   = Auth::user();
$idUtilisateur = $authUser ? $authUser['id'] : null;

// Créer ou retrouver le joueur — le lier à l'utilisateur connecté
$stmt = $pdo->prepare("SELECT id_joueur FROM joueur WHERE nom_joueur = ? LIMIT 1");
$stmt->execute([$nomJoueur]);
$joueur = $stmt->fetch();

if ($joueur) {
    $idJoueur = $joueur['id_joueur'];
    // Mettre à jour le lien utilisateur si absent
    if ($idUtilisateur !== null) {
        $pdo->prepare("UPDATE joueur SET id_utilisateur = ? WHERE id_joueur = ? AND id_utilisateur IS NULL")
            ->execute([$idUtilisateur, $idJoueur]);
    }
} else {
    $pdo->prepare("INSERT INTO joueur (nom_joueur, id_utilisateur) VALUES (?, ?)")
        ->execute([$nomJoueur, $idUtilisateur]);
    $idJoueur = (int)$pdo->lastInsertId();
}

try {
    $idSession = GameEngine::startSession($idScenario, $idJoueur, $idSalle);
    echo json_encode([
        'status'     => 'ok',
        'id_session' => $idSession,
        'message'    => 'Partie démarrée.',
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
