<?php
// =============================================================
// simulate.php — Simulateur d'événements capteurs (dev local)
// Remplace Domoticz + dzVents pour tester le moteur de jeu
// =============================================================

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/EventManager.php';
require_once __DIR__ . '/../core/GameEngine.php';

$pdo = getDB();

// Charger capteurs et événements pour le formulaire (schéma V2)
$capteurs   = $pdo->query("SELECT * FROM capteur WHERE actif = 1 ORDER BY nom_capteur")->fetchAll();
$evenements = $pdo->query("SELECT * FROM evenement_type ORDER BY type_capteur, code_evenement")->fetchAll();
$scenarios  = $pdo->query("SELECT * FROM scenario WHERE actif = 1")->fetchAll();

// Grouper les événements par type_capteur pour le JS
$evenementsByType = [];
foreach ($evenements as $e) {
    $evenementsByType[$e['type_capteur']][] = $e;
}

$result = null;

// Traitement du formulaire de simulation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'send_event') {
        $capteurId = (int)$_POST['sensor_id'];
        $eventCode = $_POST['event_code'];

        $stmt = $pdo->prepare("SELECT * FROM capteur WHERE id_capteur = ?");
        $stmt->execute([$capteurId]);
        $capteur = $stmt->fetch();

        if ($capteur) {
            $nvalue = match($eventCode) {
                'BUTTON_PRESS', 'DOOR_OPEN', 'MOTION_DETECTED' => 1,
                'BUTTON_DOUBLE_PRESS' => 2,
                'BUTTON_TRIPLE_PRESS' => 3,
                'BUTTON_HOLD'         => 10, // placeholder — ajuster après test hardware
                default => 0,
            };

            $payload = [
                'idx'    => $capteur['domoticz_idx'],
                'nvalue' => $nvalue,
                'svalue' => '',
            ];

            $event = EventManager::fromWebhook($payload);

            if ($event !== null) {
                GameEngine::process($event);
                $result = [
                    'type'     => 'event',
                    'sensor'   => $capteur['nom_capteur'],
                    'event'    => $eventCode,
                    'response' => [
                        'status'         => 'ok',
                        'code_evenement' => $event['code_evenement'],
                        'capteur'        => $event['capteur']['nom_capteur'],
                    ],
                ];
            } else {
                $result = [
                    'type'     => 'event',
                    'sensor'   => $capteur['nom_capteur'],
                    'event'    => $eventCode,
                    'response' => ['status' => 'error', 'message' => 'Événement non reconnu par EventManager.'],
                ];
            }
        }
    }

    if ($_POST['action'] === 'start_game') {
        $idScenario = (int)$_POST['id_scenario'];
        $nomJoueur  = trim($_POST['nom_joueur'] ?: 'Équipe Test');

        GameEngine::resetActiveSession();

        // Créer ou retrouver le joueur
        $stmt = $pdo->prepare("SELECT id_joueur FROM joueur WHERE nom_joueur = ? LIMIT 1");
        $stmt->execute([$nomJoueur]);
        $joueur = $stmt->fetch();

        if ($joueur) {
            $idJoueur = $joueur['id_joueur'];
        } else {
            $pdo->prepare("INSERT INTO joueur (nom_joueur) VALUES (?)")->execute([$nomJoueur]);
            $idJoueur = (int)$pdo->lastInsertId();
        }

        try {
            $idSession = GameEngine::startSession($idScenario, $idJoueur);
            $result = [
                'type'     => 'start',
                'response' => ['status' => 'ok', 'id_session' => $idSession, 'message' => 'Partie démarrée.'],
            ];
        } catch (RuntimeException $e) {
            $result = [
                'type'     => 'start',
                'response' => ['status' => 'error', 'message' => $e->getMessage()],
            ];
        }
    }

    if ($_POST['action'] === 'reset') {
        GameEngine::resetActiveSession();
        $result = [
            'type'     => 'reset',
            'response' => ['status' => 'ok', 'message' => 'Session réinitialisée.'],
        ];
    }
}

// Session courante
$session = $pdo->query("
    SELECT s.*, j.nom_joueur, sc.nom_scenario, e.titre_etape, e.numero_etape
    FROM session s
    JOIN joueur j   ON s.id_joueur  = j.id_joueur
    JOIN scenario sc ON s.id_scenario = sc.id_scenario
    LEFT JOIN etape e ON s.id_etape_courante = e.id_etape
    WHERE s.statut_session = 'en_cours'
    LIMIT 1
")->fetch();

// 5 derniers événements
$recentEvents = $pdo->query("
    SELECT es.*, c.nom_capteur, et.code_evenement
    FROM evenement_session es
    LEFT JOIN capteur c      ON es.id_capteur        = c.id_capteur
    LEFT JOIN evenement_type et ON es.id_type_evenement = et.id_type_evenement
    ORDER BY es.date_evenement DESC
    LIMIT 5
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>DomEscape — Simulateur</title>
    <link href="/domescape/assets/vendor/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; font-family: 'Courier New', monospace; }
        .panel { background: #1a1a2e; border: 1px solid #0f3460; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
        h5 { color: #00ff88; }
        label { color: #aaa; font-size: 0.85rem; }
        .form-control, .form-select {
            background: #0d0d0d; color: #e0e0e0;
            border: 1px solid #0f3460;
        }
        .form-control:focus, .form-select:focus {
            background: #0d0d0d; color: #e0e0e0;
            border-color: #00ff88; box-shadow: none;
        }
        .btn-fire { background: #00ff88; color: #0d0d0d; font-weight: bold; border: none; }
        .btn-fire:hover { background: #00cc6a; color: #0d0d0d; }
        .result-ok  { background: #00ff8811; border: 1px solid #00ff88; border-radius: 6px; padding: 12px; }
        .result-err { background: #ff000011; border: 1px solid #ff4444; border-radius: 6px; padding: 12px; }
        .badge-ok  { background: #00ff8833; color: #00ff88; }
        .badge-err { background: #ff444433; color: #ff4444; }
        .badge-run { background: #ffaa0033; color: #ffaa00; }
        pre { color: #00ff88; font-size: 0.8rem; margin: 0; }
    </style>
</head>
<body>

<nav class="navbar navbar-dark" style="background:#111; border-bottom:1px solid #0f3460;">
    <div class="container-fluid px-4">
        <span class="navbar-brand" style="color:#00ff88;">&#9632; DomEscape — Simulateur Dev</span>
        <div>
            <a href="http://localhost/domescape/public/player.php" target="_blank"
               class="btn btn-sm btn-outline-light me-2">Vue Joueur</a>
            <a href="http://localhost/domescape/public/gamemaster.php" target="_blank"
               class="btn btn-sm btn-outline-secondary">Game Master</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 my-4">
<div class="row g-4">

    <!-- Colonne gauche : contrôles -->
    <div class="col-md-5">

        <!-- Session courante -->
        <div class="panel">
            <h5 class="mb-3">Session en cours</h5>
            <?php if ($session): ?>
                <div class="mb-1"><span class="badge badge-run">en_cours</span></div>
                <div class="mt-2"><span style="color:#888;">Équipe :</span> <?= htmlspecialchars($session['nom_joueur']) ?></div>
                <div><span style="color:#888;">Scénario :</span> <?= htmlspecialchars($session['nom_scenario']) ?></div>
                <div><span style="color:#888;">Étape en cours :</span>
                    <strong style="color:#00ff88;"><?= htmlspecialchars($session['titre_etape'] ?? '—') ?></strong>
                    (étape <?= $session['numero_etape'] ?? '?' ?>)
                </div>
                <div><span style="color:#888;">Erreurs :</span> <?= $session['nb_erreurs'] ?></div>
                <form method="POST" class="mt-3">
                    <input type="hidden" name="action" value="reset">
                    <button class="btn btn-sm btn-outline-danger">&#8635; Reset session</button>
                </form>
            <?php else: ?>
                <p class="text-muted small mb-0">Aucune session active.</p>
            <?php endif; ?>
        </div>

        <!-- Démarrer une partie -->
        <div class="panel">
            <h5 class="mb-3">Démarrer une partie</h5>
            <form method="POST">
                <input type="hidden" name="action" value="start_game">
                <div class="mb-3">
                    <label>Scénario</label>
                    <select name="id_scenario" class="form-select mt-1">
                        <?php foreach ($scenarios as $sc): ?>
                            <option value="<?= $sc['id_scenario'] ?>"><?= htmlspecialchars($sc['nom_scenario']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label>Nom de l'équipe</label>
                    <input type="text" name="nom_joueur" class="form-control mt-1"
                           placeholder="Équipe Alpha" value="Équipe Test">
                </div>
                <button class="btn btn-fire w-100">&#9654; Démarrer</button>
            </form>
        </div>

        <!-- Simuler un événement capteur -->
        <div class="panel">
            <h5 class="mb-3">Simuler un événement capteur</h5>
            <form method="POST" id="eventForm">
                <input type="hidden" name="action" value="send_event">
                <div class="mb-3">
                    <label>Capteur</label>
                    <select name="sensor_id" id="sensorSelect" class="form-select mt-1"
                            onchange="updateEvents()">
                        <?php foreach ($capteurs as $c): ?>
                            <option value="<?= $c['id_capteur'] ?>"
                                    data-type="<?= $c['type_capteur'] ?>">
                                <?= htmlspecialchars($c['nom_capteur']) ?>
                                (<?= $c['type_capteur'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label>Événement</label>
                    <select name="event_code" id="eventSelect" class="form-select mt-1"></select>
                </div>
                <button class="btn btn-fire w-100">&#9889; Envoyer l'événement</button>
            </form>
        </div>

    </div>

    <!-- Colonne droite : résultats & logs -->
    <div class="col-md-7">

        <!-- Résultat de la dernière action -->
        <?php if ($result): ?>
        <div class="panel">
            <h5 class="mb-3">Résultat</h5>
            <?php
            $isOk = ($result['response']['status'] ?? '') === 'ok';
            $cls  = $isOk ? 'result-ok' : 'result-err';
            ?>
            <div class="<?= $cls ?>">
                <?php if ($result['type'] === 'event'): ?>
                    <div class="mb-1">
                        <strong><?= htmlspecialchars($result['sensor']) ?></strong>
                        → <code><?= htmlspecialchars($result['event']) ?></code>
                    </div>
                <?php endif; ?>
                <pre><?= json_encode($result['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?></pre>
            </div>
        </div>
        <?php endif; ?>

        <!-- Derniers événements en BDD -->
        <div class="panel">
            <h5 class="mb-3">Derniers événements enregistrés</h5>
            <?php if (empty($recentEvents)): ?>
                <p class="text-muted small">Aucun événement enregistré.</p>
            <?php else: ?>
                <?php foreach ($recentEvents as $ev): ?>
                <div class="d-flex justify-content-between align-items-center mb-2 pb-2"
                     style="border-bottom:1px solid #0f3460;">
                    <div>
                        <span class="badge <?= $ev['evenement_attendu'] ? 'badge-ok' : 'badge-err' ?>">
                            <?= $ev['evenement_attendu'] ? '✓ attendu' : '✗ inattendu' ?>
                        </span>
                        <span class="ms-2"><?= htmlspecialchars($ev['nom_capteur'] ?? '?') ?></span>
                        <code class="ms-2" style="color:#aaa;"><?= htmlspecialchars($ev['code_evenement'] ?? '?') ?></code>
                    </div>
                    <small class="text-muted"><?= $ev['date_evenement'] ?></small>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Aide -->
        <div class="panel">
            <h5 class="mb-2">Flux de test</h5>
            <ol class="text-muted small" style="line-height:2;">
                <li>Démarrer une partie ci-contre</li>
                <li>Ouvrir la <a href="http://localhost/domescape/public/player.php" target="_blank" style="color:#00ff88;">Vue Joueur</a> dans un autre onglet</li>
                <li>Simuler les événements dans l'ordre :
                    <code>BUTTON_PRESS</code> →
                    <code>DOOR_OPEN</code> →
                    <code>MOTION_DETECTED</code> →
                    <code>BUTTON_DOUBLE_PRESS</code>
                </li>
                <li>Observer la progression en temps réel</li>
            </ol>
        </div>

    </div>
</div>
</div>

<script>
// Catalogue des événements par type_capteur
const catalog = <?= json_encode($evenementsByType) ?>;

function updateEvents() {
    const select     = document.getElementById('sensorSelect');
    const deviceType = select.options[select.selectedIndex].dataset.type;
    const eventSel   = document.getElementById('eventSelect');

    eventSel.innerHTML = '';
    const events = catalog[deviceType] || [];
    events.forEach(e => {
        const opt = document.createElement('option');
        opt.value       = e.code_evenement;
        opt.textContent = e.code_evenement + ' — ' + e.libelle_evenement;
        eventSel.appendChild(opt);
    });
}

// Init au chargement
updateEvents();
</script>
</body>
</html>
