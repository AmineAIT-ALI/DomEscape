<?php

// =============================================================
// join_session.php — Rejoindre directement une session en_attente
//
// POST :
//   id_session : identifiant de la session à rejoindre
//
// Conditions :
//   - session doit être en statut 'en_attente'
//   - utilisateur ne doit pas déjà en être membre
//   - utilisateur doit être authentifié
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

$idSession     = (int)($_POST['id_session'] ?? 0);
$authUser      = Auth::user();
$idUtilisateur = (int)$authUser['id'];

if ($idSession === 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id_session manquant.']);
    exit;
}

$pdo = getDB();

// Vérifier que la session existe et est en_attente
$stmt = $pdo->prepare("SELECT id_session, statut_session FROM session WHERE id_session = ? LIMIT 1");
$stmt->execute([$idSession]);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Session introuvable.']);
    exit;
}

if ($session['statut_session'] !== 'en_attente') {
    http_response_code(409);
    echo json_encode([
        'status'  => 'error',
        'message' => 'La session n\'est plus en attente. Utilisez la demande de rejoindre si elle est en cours.',
    ]);
    exit;
}

// Vérifier si l'utilisateur est déjà membre
$stmtCheck = $pdo->prepare("
    SELECT 1 FROM session_utilisateur
    WHERE id_session = ? AND id_utilisateur = ?
    LIMIT 1
");
$stmtCheck->execute([$idSession, $idUtilisateur]);

if ($stmtCheck->fetch()) {
    echo json_encode(['status' => 'ok', 'message' => 'Vous êtes déjà membre de cette session.']);
    exit;
}

// Vérifier nb_joueurs_max
$stmtScen = $pdo->prepare("
    SELECT sc.nb_joueurs_max
    FROM session se
    JOIN scenario sc ON se.id_scenario = sc.id_scenario
    WHERE se.id_session = ?
    LIMIT 1
");
$stmtScen->execute([$idSession]);
$scenData = $stmtScen->fetch();

if ($scenData && $scenData['nb_joueurs_max'] !== null) {
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM session_utilisateur WHERE id_session = ?");
    $stmtCount->execute([$idSession]);
    if ((int)$stmtCount->fetchColumn() >= (int)$scenData['nb_joueurs_max']) {
        http_response_code(409);
        echo json_encode([
            'status'  => 'error',
            'message' => 'La session a atteint le nombre maximum de participants (' . (int)$scenData['nb_joueurs_max'] . ').',
        ]);
        exit;
    }
}

// Insertion comme participant
$pdo->prepare("
    INSERT INTO session_utilisateur (id_session, id_utilisateur)
    VALUES (?, ?)
")->execute([$idSession, $idUtilisateur]);

// Tenter de lancer la session si le seuil minimum est atteint
$launched = GameEngine::tryLaunchSession($idSession);

echo json_encode([
    'status'   => 'ok',
    'message'  => $launched ? 'Vous avez rejoint la session. La partie commence !' : 'Vous avez rejoint la session.',
    'launched' => $launched,
]);
