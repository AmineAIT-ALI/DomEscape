<?php

// =============================================================
// request_join_session.php — Demander à rejoindre une session en cours
//
// POST :
//   id_session      : identifiant de la session
//   message_demande : message optionnel (max 500 chars)
//
// Conditions :
//   - session doit être en statut 'en_cours'
//   - utilisateur ne doit pas déjà en être membre
//   - aucune demande en_attente en cours pour cette session/utilisateur
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';

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

$idSession      = (int)($_POST['id_session']      ?? 0);
$messageDemande = mb_substr(trim($_POST['message_demande'] ?? ''), 0, 500) ?: null;
$authUser       = Auth::user();
$idUtilisateur  = (int)$authUser['id'];

if ($idSession === 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'id_session manquant.']);
    exit;
}

$pdo = getDB();

// Vérifier que la session est en_cours
$stmt = $pdo->prepare("SELECT id_session, statut_session FROM session WHERE id_session = ? LIMIT 1");
$stmt->execute([$idSession]);
$session = $stmt->fetch();

if (!$session) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Session introuvable.']);
    exit;
}

if ($session['statut_session'] !== 'en_cours') {
    http_response_code(409);
    echo json_encode([
        'status'  => 'error',
        'message' => 'La session n\'est pas en cours. Rejoignez directement si elle est en attente.',
    ]);
    exit;
}

// Vérifier si déjà membre
$stmtMembre = $pdo->prepare("
    SELECT 1 FROM session_utilisateur
    WHERE id_session = ? AND id_utilisateur = ?
    LIMIT 1
");
$stmtMembre->execute([$idSession, $idUtilisateur]);
if ($stmtMembre->fetch()) {
    echo json_encode(['status' => 'ok', 'message' => 'Vous êtes déjà membre de cette session.']);
    exit;
}

// Vérifier si une demande en_attente existe déjà
$stmtExist = $pdo->prepare("
    SELECT 1 FROM demande_rejoindre_session
    WHERE id_session = ? AND id_utilisateur = ? AND statut_demande = 'en_attente'
    LIMIT 1
");
$stmtExist->execute([$idSession, $idUtilisateur]);
if ($stmtExist->fetch()) {
    echo json_encode(['status' => 'ok', 'message' => 'Votre demande est déjà en attente de traitement.']);
    exit;
}

// Insérer la demande
$pdo->prepare("
    INSERT INTO demande_rejoindre_session (id_session, id_utilisateur, message_demande)
    VALUES (?, ?, ?)
")->execute([$idSession, $idUtilisateur, $messageDemande]);

echo json_encode([
    'status'  => 'ok',
    'message' => 'Votre demande a été envoyée. Un superviseur va la traiter.',
]);
