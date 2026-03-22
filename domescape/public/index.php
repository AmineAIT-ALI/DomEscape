<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireLogin();

$pdo = getDB();

// Scénarios actifs avec compte d'étapes
$scenarios = $pdo->query("
    SELECT s.*, COUNT(e.id_etape) AS nb_etapes
    FROM scenario s
    LEFT JOIN etape e ON s.id_scenario = e.id_scenario
    WHERE s.actif = 1
    GROUP BY s.id_scenario
    ORDER BY s.nom_scenario
")->fetchAll();

// Session active de ce joueur (via utilisateur)
$authUser   = Auth::user();
$authRoles  = Auth::buildHierarchy(Auth::roles());
$activeSession = null;
if ($authUser) {
    $stmt = $pdo->prepare("
        SELECT se.*, sc.nom_scenario
        FROM session se
        JOIN joueur j ON se.id_joueur = j.id_joueur
        JOIN scenario sc ON se.id_scenario = sc.id_scenario
        WHERE j.id_utilisateur = ? AND se.statut_session = 'en_cours'
        ORDER BY se.date_debut DESC
        LIMIT 1
    ");
    $stmt->execute([$authUser['id']]);
    $activeSession = $stmt->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jouer — DomEscape</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }

        /* Page header */
        .play-header {
            padding: 40px 0 32px;
            border-bottom: 1px solid #111;
        }
        .play-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #e0e0e0;
            margin: 0 0 6px;
        }
        .play-header p {
            font-size: .8rem;
            color: #555;
            margin: 0;
        }

        /* Active session banner */
        .session-banner {
            background: rgba(0,255,136,.05);
            border: 1px solid rgba(0,255,136,.2);
            border-radius: 6px;
            padding: 16px 20px;
            margin-bottom: 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .session-banner-dot {
            width: 8px; height: 8px;
            background: #00ff88;
            border-radius: 50%;
            box-shadow: 0 0 8px #00ff88;
            animation: pulse-dot 1.5s infinite;
            flex-shrink: 0;
        }
        @keyframes pulse-dot {
            0%, 100% { opacity: 1; }
            50% { opacity: .3; }
        }
        .session-banner-text {
            flex: 1;
            font-size: .82rem;
        }
        .session-banner-text strong { color: #00ff88; }
        .session-banner-text span { color: #888; }
        .btn-resume {
            background: #00ff88;
            color: #080810;
            font-weight: 700;
            font-size: .78rem;
            padding: 8px 18px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            white-space: nowrap;
            transition: background .15s;
        }
        .btn-resume:hover { background: #00cc6a; color: #080810; }

        /* Scenario grid */
        .section-label {
            font-size: .68rem;
            letter-spacing: .12em;
            color: #555;
            text-transform: uppercase;
            margin-bottom: 20px;
        }
        .scenario-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 48px;
        }
        .scenario-card {
            background: #0f0f18;
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            transition: border-color .2s, transform .2s;
            cursor: pointer;
        }
        .scenario-card:hover {
            border-color: rgba(0,255,136,.4);
            transform: translateY(-2px);
        }
        .scenario-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 8px;
        }
        .scenario-title {
            font-size: .95rem;
            font-weight: 700;
            color: #e0e0e0;
        }
        .scenario-theme {
            font-size: .65rem;
            padding: 2px 8px;
            border-radius: 3px;
            border: 1px solid #333;
            color: #888;
            background: rgba(255,255,255,.03);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .scenario-desc {
            font-size: .78rem;
            color: #666;
            line-height: 1.55;
            flex: 1;
        }
        .scenario-meta {
            display: flex;
            align-items: center;
            gap: 16px;
            padding-top: 12px;
            border-top: 1px solid #111;
        }
        .meta-item {
            font-size: .7rem;
            color: #555;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .meta-item span { color: #888; }
        .btn-play {
            background: #00ff88;
            color: #080810;
            font-weight: 700;
            font-size: .8rem;
            padding: 9px 0;
            border: none;
            border-radius: 4px;
            width: 100%;
            font-family: 'Courier New', monospace;
            transition: background .15s;
        }
        .btn-play:hover { background: #00cc6a; }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 80px 0;
            color: #333;
        }
        .empty-state p { font-size: .85rem; margin-top: 12px; }

        /* Modal */
        .modal-content {
            background: #0f0f18;
            border: 1px solid #1a1a2e;
            color: #e0e0e0;
            font-family: 'Courier New', monospace;
        }
        .modal-header { border-bottom: 1px solid #111; }
        .modal-footer { border-top: 1px solid #111; }
        .modal-title { color: #00ff88; font-size: .95rem; }
        .modal-scenario-name {
            font-size: .82rem;
            color: #555;
            margin-bottom: 20px;
        }
        .form-label { font-size: .78rem; color: #888; }
        .form-control {
            background: #080810;
            border: 1px solid #1a1a2e;
            color: #e0e0e0;
            font-family: 'Courier New', monospace;
            font-size: .875rem;
        }
        .form-control:focus {
            background: #080810;
            border-color: #00ff88;
            color: #e0e0e0;
            box-shadow: none;
        }
        .btn-modal-cancel {
            background: transparent;
            border: 1px solid #1a1a2e;
            color: #888;
            font-size: .8rem;
            font-family: 'Courier New', monospace;
            padding: 7px 18px;
            border-radius: 4px;
        }
        .btn-modal-start {
            background: #00ff88;
            color: #080810;
            font-weight: 700;
            font-size: .8rem;
            font-family: 'Courier New', monospace;
            padding: 7px 18px;
            border: none;
            border-radius: 4px;
        }
        .btn-modal-start:hover { background: #00cc6a; }
        .error-inline {
            background: rgba(255,68,68,.08);
            border: 1px solid rgba(255,68,68,.3);
            color: #ff6666;
            padding: 8px 12px;
            border-radius: 4px;
            font-size: .78rem;
            margin-top: 12px;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="container">
    <div class="play-header">
        <h1>Choisir un scénario</h1>
        <p>Sélectionnez un scénario pour lancer une partie. Le jeu est piloté en temps réel par les capteurs Z-Wave.</p>
    </div>

    <div class="py-4">

        <?php if ($activeSession): ?>
        <div class="session-banner">
            <div class="session-banner-dot"></div>
            <div class="session-banner-text">
                <strong>Session en cours</strong> &nbsp;—&nbsp;
                <span><?= htmlspecialchars($activeSession['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <a href="/domescape/public/player.php" class="btn-resume">Reprendre →</a>
        </div>
        <?php endif; ?>

        <?php if (empty($scenarios)): ?>
            <div class="empty-state">
                <div style="font-size:2.5rem;opacity:.2;">&#9632;</div>
                <p>Aucun scénario disponible pour le moment.</p>
                <?php if (in_array(ROLE_ADMINISTRATEUR, $authRoles, true)): ?>
                    <a href="/domescape/admin/dashboard.php" style="color:#00ff88;font-size:.8rem;">Configurer un scénario →</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="section-label"><?= count($scenarios) ?> scénario<?= count($scenarios) > 1 ? 's' : '' ?> disponible<?= count($scenarios) > 1 ? 's' : '' ?></div>
            <div class="scenario-grid">
                <?php foreach ($scenarios as $s): ?>
                <div class="scenario-card"
                     onclick="openStartModal(<?= $s['id_scenario'] ?>, '<?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES) ?>')">

                    <div class="scenario-card-head">
                        <div class="scenario-title"><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($s['theme']): ?>
                            <span class="scenario-theme"><?= htmlspecialchars($s['theme'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </div>

                    <?php if ($s['description']): ?>
                        <div class="scenario-desc"><?= htmlspecialchars($s['description'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>

                    <div class="scenario-meta">
                        <div class="meta-item">
                            &#9632; <span><?= (int)$s['nb_etapes'] ?> énigme<?= $s['nb_etapes'] > 1 ? 's' : '' ?></span>
                        </div>
                        <div class="meta-item">
                            &#9675; <span>Z-Wave</span>
                        </div>
                    </div>

                    <button class="btn-play">Jouer ce scénario →</button>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal démarrage -->
<div class="modal fade" id="startModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Démarrer une partie</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="modalScenarioName" class="modal-scenario-name"></div>
                <input type="hidden" id="selectedScenarioId">
                <div class="mb-0">
                    <label class="form-label" for="nomJoueur">Nom de votre équipe</label>
                    <input type="text" id="nomJoueur" class="form-control"
                           placeholder="ex : Équipe Alpha" maxlength="100" autofocus>
                </div>
                <div id="startError" class="error-inline d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-modal-cancel" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn-modal-start" onclick="startGame()">Lancer la partie →</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let startModal;

document.addEventListener('DOMContentLoaded', () => {
    startModal = new bootstrap.Modal(document.getElementById('startModal'));
    document.getElementById('startModal').addEventListener('shown.bs.modal', () => {
        document.getElementById('nomJoueur').focus();
    });
    document.getElementById('nomJoueur').addEventListener('keydown', e => {
        if (e.key === 'Enter') startGame();
    });
});

function openStartModal(idScenario, titre) {
    document.getElementById('selectedScenarioId').value = idScenario;
    document.getElementById('modalScenarioName').textContent = titre;
    document.getElementById('nomJoueur').value = '';
    document.getElementById('startError').classList.add('d-none');
    startModal.show();
}

function startGame() {
    const idScenario = document.getElementById('selectedScenarioId').value;
    const nomJoueur  = document.getElementById('nomJoueur').value.trim();
    const errEl      = document.getElementById('startError');

    if (!nomJoueur) {
        errEl.textContent = 'Veuillez entrer un nom d\'équipe.';
        errEl.classList.remove('d-none');
        return;
    }

    const btn = document.querySelector('.btn-modal-start');
    btn.textContent = 'Démarrage…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('id_scenario', idScenario);
    fd.append('nom_joueur',  nomJoueur);

    fetch('/domescape/api/start_game.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                window.location.href = '/domescape/public/player.php';
            } else {
                errEl.textContent = data.message || 'Erreur serveur.';
                errEl.classList.remove('d-none');
                btn.textContent = 'Lancer la partie →';
                btn.disabled = false;
            }
        })
        .catch(() => {
            errEl.textContent = 'Impossible de joindre le serveur.';
            errEl.classList.remove('d-none');
            btn.textContent = 'Lancer la partie →';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
