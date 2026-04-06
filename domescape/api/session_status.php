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

$pdo  = getDB();
$user = Auth::user();

if ($session === null) {
    // Vérifier si une session en_attente implique cet utilisateur
    $stmtWait = $pdo->prepare("
        SELECT se.*
        FROM session se
        JOIN session_utilisateur su ON su.id_session = se.id_session
        WHERE se.statut_session = 'en_attente'
          AND su.id_utilisateur = ?
        ORDER BY se.id_session DESC
        LIMIT 1
    ");
    $stmtWait->execute([$user['id']]);
    $session = $stmtWait->fetch() ?: null;
}

if ($session === null) {
    // Vérifier si une session vient de se terminer (dans les 10 dernières minutes)
    $stmtRecent = $pdo->prepare("
        SELECT * FROM session
        WHERE statut_session IN ('gagnee', 'perdue')
        AND date_fin >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ORDER BY date_fin DESC
        LIMIT 1
    ");
    $stmtRecent->execute();
    $session = $stmtRecent->fetch() ?: null;

    if ($session === null) {
        echo json_encode(['status' => 'no_session']);
        exit;
    }
}

// Étape courante
$etapeStmt = $pdo->prepare("SELECT * FROM etape WHERE id_etape = ? LIMIT 1");
$etapeStmt->execute([$session['id_etape_courante']]);
$etape = $etapeStmt->fetch();

// Équipe
$equipeStmt = $pdo->prepare("SELECT * FROM equipe WHERE id_equipe = ? LIMIT 1");
$equipeStmt->execute([$session['id_equipe']]);
$equipe = $equipeStmt->fetch();

// Scénario
$scenarioStmt = $pdo->prepare("SELECT * FROM scenario WHERE id_scenario = ? LIMIT 1");
$scenarioStmt->execute([$session['id_scenario']]);
$scenario = $scenarioStmt->fetch();

// Nombre total d'étapes — par version si disponible, sinon par scénario
if (!empty($session['id_scenario_version'])) {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM etape WHERE id_scenario_version = ?");
    $totalStmt->execute([$session['id_scenario_version']]);
} else {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) as total FROM etape WHERE id_scenario = ?");
    $totalStmt->execute([$session['id_scenario']]);
}
$total = (int)$totalStmt->fetch()['total'];

// Durée écoulée
$elapsed = 0;
if ($session['statut_session'] === 'gagnee' && !empty($session['duree_secondes'])) {
    $elapsed = (int)$session['duree_secondes'];
} elseif ($session['date_debut']) {
    $elapsed = time() - strtotime($session['date_debut']);
}

// Auto-close si duree_max_secondes dépassée (polled chaque seconde)
if ($session['statut_session'] === 'en_cours'
    && $scenario !== false
    && $scenario['duree_max_secondes'] !== null
    && $elapsed > (int)$scenario['duree_max_secondes']
) {
    $pdo->prepare("
        UPDATE session
        SET statut_session = 'perdue',
            date_fin       = NOW(),
            duree_secondes = ?
        WHERE id_session = ?
    ")->execute([$elapsed, $session['id_session']]);
    $session['statut_session'] = 'perdue';
    $session['duree_secondes'] = $elapsed;
}

// Joueurs dans la session (utile pour l'écran en_attente)
$nbJoueursStmt = $pdo->prepare("SELECT COUNT(*) FROM session_utilisateur WHERE id_session = ?");
$nbJoueursStmt->execute([$session['id_session']]);
$nbJoueursActuel = (int)$nbJoueursStmt->fetchColumn();

echo json_encode([
    'status'           => $session['statut_session'],
    'session_id'       => $session['id_session'],
    'equipe'           => $equipe['nom_equipe'] ?? 'Équipe inconnue',
    'scenario'         => $scenario['nom_scenario'] ?? '',
    'score'            => $session['score'],
    'nb_erreurs'       => $session['nb_erreurs'],
    'nb_indices'       => (int)$session['nb_indices'],
    'elapsed_seconds'  => $elapsed,
    'nb_joueurs_actuel'=> $nbJoueursActuel,
    'nb_joueurs_min'   => $scenario['nb_joueurs_min'] !== null ? (int)$scenario['nb_joueurs_min'] : null,
    'etape' => [
        'id'          => $etape['id_etape']      ?? null,
        'numero'      => $etape['numero_etape']  ?? null,
        'titre'       => $etape['titre_etape']   ?? '',
        'description' => $etape['description_etape'] ?? '',
        'indice'      => $etape['indice']        ?? '',
        'finale'      => (bool)($etape['finale'] ?? false),
    ],
    'total_etapes' => $total,
]);
