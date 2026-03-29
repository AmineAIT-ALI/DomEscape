<?php
// =============================================================
// abandon_game.php — Abandonner sa propre session en cours
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../config/database.php';

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Non authentifié.']);
    exit;
}

$pdo    = getDB();
$userId = Auth::user()['id'];

// Trouver la session en cours appartenant à ce joueur
$stmt = $pdo->prepare("
    SELECT se.id_session
    FROM session se
    JOIN joueur j ON j.id_joueur = se.id_joueur
    WHERE j.id_utilisateur = ? AND se.statut_session = 'en_cours'
    LIMIT 1
");
$stmt->execute([$userId]);
$session = $stmt->fetch();

if (!$session) {
    echo json_encode(['status' => 'error', 'message' => 'Aucune session en cours.']);
    exit;
}

$pdo->prepare("
    UPDATE session
    SET statut_session = 'abandonnee',
        date_fin       = NOW(),
        duree_secondes = TIMESTAMPDIFF(SECOND, date_debut, NOW())
    WHERE id_session = ?
")->execute([$session['id_session']]);

echo json_encode(['status' => 'ok', 'message' => 'Partie abandonnée.']);
