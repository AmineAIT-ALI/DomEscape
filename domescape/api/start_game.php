<?php

// =============================================================
// start_game.php — Créer une nouvelle session (capitaine uniquement)
//
// POST :
//   id_scenario : id du scénario
//   nom_joueur  : nom de l'équipe (clé HTTP conservée pour compatibilité)
//
// Règle mono-salle : bloqué si une session en_attente ou en_cours existe déjà.
// Le créateur est automatiquement inséré comme capitaine dans session_utilisateur.
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

$idScenario = (int)( $_POST['id_scenario'] ?? 0);
$nomEquipe  = trim(  $_POST['nom_joueur']  ?? '');   // clé HTTP conservée pour compatibilité formulaire

if ($idScenario === 0 || $nomEquipe === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Paramètres manquants (id_scenario, nom_joueur).']);
    exit;
}

$pdo = getDB();

// ---------------------------------------------------------------
// Règle mono-salle : bloquer si une session active existe déjà
// ---------------------------------------------------------------
$stmtActive = $pdo->query("
    SELECT id_session, statut_session
    FROM session
    WHERE statut_session IN ('en_attente', 'en_cours')
    LIMIT 1
");
$sessionActive = $stmtActive->fetch();

if ($sessionActive) {
    http_response_code(409);
    echo json_encode([
        'status'     => 'session_exists',
        'id_session' => $sessionActive['id_session'],
        'message'    => 'Une session est déjà active. Rejoignez la session existante.',
    ]);
    exit;
}

// Résoudre la version active du scénario + contraintes de participants
$stmtVer = $pdo->prepare("
    SELECT sv.id_scenario_version, sc.nb_joueurs_min, sc.nb_joueurs_max
    FROM scenario sc
    LEFT JOIN scenario_version sv
        ON sv.id_scenario = sc.id_scenario AND sv.statut_version = 'active'
    WHERE sc.id_scenario = ?
    LIMIT 1
");
$stmtVer->execute([$idScenario]);
$scenarioRow       = $stmtVer->fetch();
$idScenarioVersion = $scenarioRow ? ($scenarioRow['id_scenario_version'] ? (int)$scenarioRow['id_scenario_version'] : null) : null;
$minJoueurs        = $scenarioRow && $scenarioRow['nb_joueurs_min'] !== null ? (int)$scenarioRow['nb_joueurs_min'] : 1;

// Récupérer l'utilisateur connecté (capitaine)
$authUser      = Auth::user();
$idUtilisateur = $authUser ? (int)$authUser['id'] : null;

// Créer ou retrouver l'équipe par son nom
$stmt = $pdo->prepare("SELECT id_equipe FROM equipe WHERE nom_equipe = ? LIMIT 1");
$stmt->execute([$nomEquipe]);
$equipe = $stmt->fetch();

if ($equipe) {
    $idEquipe = (int)$equipe['id_equipe'];
    if ($idUtilisateur !== null) {
        $pdo->prepare("UPDATE equipe SET id_utilisateur = ? WHERE id_equipe = ? AND id_utilisateur IS NULL")
            ->execute([$idUtilisateur, $idEquipe]);
    }
} else {
    $pdo->prepare("INSERT INTO equipe (nom_equipe, id_utilisateur) VALUES (?, ?)")
        ->execute([$nomEquipe, $idUtilisateur]);
    $idEquipe = (int)$pdo->lastInsertId();
}

// Ajouter dans equipe_utilisateur (table de membres de l'équipe)
if ($idUtilisateur !== null) {
    $pdo->prepare("
        INSERT IGNORE INTO equipe_utilisateur (id_equipe, id_utilisateur)
        VALUES (?, ?)
    ")->execute([$idEquipe, $idUtilisateur]);
}

try {
    $idSession = GameEngine::startSession($idScenario, $idEquipe, $idScenarioVersion, $idUtilisateur, $minJoueurs);

    // Insérer le créateur dans session_utilisateur
    if ($idUtilisateur !== null) {
        $pdo->prepare("
            INSERT IGNORE INTO session_utilisateur (id_session, id_utilisateur)
            VALUES (?, ?)
        ")->execute([$idSession, $idUtilisateur]);
    }

    // Si min > 1 : tenter le lancement immédiat (au cas où le créateur serait le seul requis)
    GameEngine::tryLaunchSession($idSession);

    // Relire le statut réel après tentative de lancement
    $stmtStatut = $pdo->prepare("SELECT statut_session FROM session WHERE id_session = ? LIMIT 1");
    $stmtStatut->execute([$idSession]);
    $statutFinal = $stmtStatut->fetchColumn();

    echo json_encode([
        'status'              => 'ok',
        'id_session'          => $idSession,
        'statut_session'      => $statutFinal,
        'id_scenario_version' => $idScenarioVersion,
        'message'             => $statutFinal === 'en_cours'
            ? 'Partie démarrée.'
            : 'Session créée. En attente de ' . $minJoueurs . ' joueur(s) minimum.',
    ]);
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
