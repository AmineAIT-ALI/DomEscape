<?php

// =============================================================
// gamemaster_status.php — État session + événements BDD
//
// Étend session_status avec les 15 derniers événements
// et les 5 dernières actions de la session active.
// =============================================================

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/GameEngine.php';

Auth::init();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['status' => 'unauthenticated']);
    exit;
}

$session = GameEngine::getActiveSession();

if ($session === null) {
    // Chercher la dernière session terminée (pour afficher le résultat)
    $pdo  = getDB();
    $stmt = $pdo->query("
        SELECT * FROM session
        WHERE statut_session IN ('gagnee','perdue','abandonnee')
        ORDER BY date_fin DESC
        LIMIT 1
    ");
    $last = $stmt->fetch();
    if ($last) {
        $session = $last;
    } else {
        echo json_encode(['status' => 'no_session']);
        exit;
    }
}

$pdo = getDB();

// Étape courante
$etape = null;
if ($session['id_etape_courante']) {
    $s = $pdo->prepare('SELECT * FROM etape WHERE id_etape = ? LIMIT 1');
    $s->execute([$session['id_etape_courante']]);
    $etape = $s->fetch();
}

// Équipe + scénario
$equipeStmt = $pdo->prepare('SELECT * FROM equipe WHERE id_equipe = ? LIMIT 1');
$equipeStmt->execute([$session['id_equipe']]);
$equipe = $equipeStmt->fetch();

$scenarioStmt = $pdo->prepare('SELECT * FROM scenario WHERE id_scenario = ? LIMIT 1');
$scenarioStmt->execute([$session['id_scenario']]);
$scenario = $scenarioStmt->fetch();

// Total étapes
$totalStmt = $pdo->prepare('SELECT COUNT(*) AS total FROM etape WHERE id_scenario = ?');
$totalStmt->execute([$session['id_scenario']]);
$total = (int)$totalStmt->fetch()['total'];

// Durée
$elapsed = 0;
if ($session['statut_session'] === 'en_cours' && $session['date_debut']) {
    $elapsed = time() - strtotime($session['date_debut']);
} elseif ($session['duree_secondes']) {
    $elapsed = (int)$session['duree_secondes'];
}

// 15 derniers événements de la session (avec capteur + type)
$evtStmt = $pdo->prepare("
    SELECT
        es.date_evenement,
        es.evenement_attendu,
        es.valide,
        c.nom_capteur,
        et.code_evenement,
        e.numero_etape
    FROM evenement_session es
    LEFT JOIN capteur          c  ON es.id_capteur        = c.id_capteur
    LEFT JOIN evenement_type   et ON es.id_type_evenement = et.id_type_evenement
    LEFT JOIN etape            e  ON es.id_etape          = e.id_etape
    WHERE es.id_session = ?
    ORDER BY es.date_evenement DESC
    LIMIT 15
");
$evtStmt->execute([$session['id_session']]);
$events = $evtStmt->fetchAll(PDO::FETCH_ASSOC);

// 5 dernières actions
$actStmt = $pdo->prepare("
    SELECT
        ae.date_execution,
        ae.valeur_action,
        ae.statut_execution,
        a.nom_actionneur,
        at.code_action
    FROM action_executee ae
    LEFT JOIN actionneur  a  ON ae.id_actionneur = a.id_actionneur
    LEFT JOIN action_type at ON ae.id_type_action = at.id_type_action
    WHERE ae.id_session = ?
    ORDER BY ae.date_execution DESC
    LIMIT 5
");
$actStmt->execute([$session['id_session']]);
$actions = $actStmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'status'          => $session['statut_session'],
    'session_id'      => $session['id_session'],
    'equipe'          => $equipe['nom_equipe']    ?? 'Équipe inconnue',
    'scenario'        => $scenario['nom_scenario'] ?? '',
    'score'           => $session['score'],
    'nb_erreurs'      => $session['nb_erreurs'],
    'nb_indices'      => (int)$session['nb_indices'],
    'elapsed_seconds' => $elapsed,
    'etape' => [
        'id'          => $etape['id_etape']          ?? null,
        'numero'      => $etape['numero_etape']       ?? null,
        'titre'       => $etape['titre_etape']        ?? '',
        'description' => $etape['description_etape']  ?? '',
    ],
    'total_etapes' => $total,
    'events'        => array_map(fn($r) => [
        'time'     => substr($r['date_evenement'], 11, 8),
        'capteur'  => $r['nom_capteur']  ?? '?',
        'code'     => $r['code_evenement'] ?? '?',
        'etape'    => $r['numero_etape'] ?? null,
        'valide'   => (bool)$r['valide'],
        'attendu'  => (bool)$r['evenement_attendu'],
    ], $events),
    'actions' => array_map(fn($r) => [
        'time'    => substr($r['date_execution'], 11, 8),
        'acteur'  => $r['nom_actionneur'] ?? '?',
        'code'    => $r['code_action']    ?? '?',
        'valeur'  => $r['valeur_action']  ?? '',
        'statut'  => $r['statut_execution'],
    ], $actions),
]);
