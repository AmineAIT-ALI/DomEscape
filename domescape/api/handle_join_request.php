<?php

// =============================================================
// handle_join_request.php — Traiter une demande de rejoindre
//
// POST :
//   id_demande : identifiant de la demande
//   action     : 'accepter' | 'refuser'
//
// Accès réservé : superviseur ou administrateur
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

// Vérifier le rôle : superviseur ou administrateur uniquement
$roles = Auth::buildHierarchy(Auth::roles());
if (!in_array(ROLE_SUPERVISEUR, $roles, true) && !in_array(ROLE_ADMINISTRATEUR, $roles, true)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Accès réservé au staff.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Méthode non autorisée.']);
    exit;
}

$idDemande     = (int)($_POST['id_demande'] ?? 0);
$action        = trim($_POST['action']      ?? '');
$authUser      = Auth::user();
$idTraitePar   = (int)$authUser['id'];

if ($idDemande === 0 || !in_array($action, ['accepter', 'refuser'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paramètres invalides (id_demande, action).']);
    exit;
}

$pdo = getDB();

// Récupérer la demande
$stmt = $pdo->prepare("
    SELECT * FROM demande_rejoindre_session WHERE id_demande = ? LIMIT 1
");
$stmt->execute([$idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Demande introuvable.']);
    exit;
}

if ($demande['statut_demande'] !== 'en_attente') {
    http_response_code(409);
    echo json_encode(['status' => 'error', 'message' => 'Cette demande a déjà été traitée.']);
    exit;
}

$now           = date('Y-m-d H:i:s');
$nouveauStatut = ($action === 'accepter') ? 'acceptee' : 'refusee';

// Mettre à jour la demande
$pdo->prepare("
    UPDATE demande_rejoindre_session
    SET statut_demande = ?,
        traitee_le     = ?,
        traitee_par    = ?
    WHERE id_demande = ?
")->execute([$nouveauStatut, $now, $idTraitePar, $idDemande]);

// Si acceptée : ajouter l'utilisateur dans session_utilisateur
if ($action === 'accepter') {
    // Vérifier nb_joueurs_max avant d'insérer
    $stmtScen = $pdo->prepare("
        SELECT sc.nb_joueurs_max
        FROM session se
        JOIN scenario sc ON se.id_scenario = sc.id_scenario
        WHERE se.id_session = ?
        LIMIT 1
    ");
    $stmtScen->execute([$demande['id_session']]);
    $scenData = $stmtScen->fetch();

    if ($scenData && $scenData['nb_joueurs_max'] !== null) {
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM session_utilisateur WHERE id_session = ?");
        $stmtCount->execute([$demande['id_session']]);
        if ((int)$stmtCount->fetchColumn() >= (int)$scenData['nb_joueurs_max']) {
            http_response_code(409);
            echo json_encode([
                'status'  => 'error',
                'message' => 'Impossible d\'accepter : la session a atteint le nombre maximum de participants (' . (int)$scenData['nb_joueurs_max'] . ').',
            ]);
            exit;
        }
    }

    $pdo->prepare("
        INSERT IGNORE INTO session_utilisateur (id_session, id_utilisateur)
        VALUES (?, ?)
    ")->execute([$demande['id_session'], $demande['id_utilisateur']]);

    // Tenter de lancer la session si elle est en_attente et que le seuil est atteint
    GameEngine::tryLaunchSession($demande['id_session']);

    echo json_encode([
        'status'  => 'ok',
        'message' => 'Demande acceptée. L\'utilisateur a été ajouté à la session.',
    ]);
} else {
    echo json_encode([
        'status'  => 'ok',
        'message' => 'Demande refusée.',
    ]);
}
