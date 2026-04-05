<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/ScenarioRepository.php';

RoleGuard::requireLogin();

$pdo = getDB();

// Scénarios actifs avec compte d'étapes
$scenarios = ScenarioRepository::getActive();

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

        /* Modal custom */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.7);
            z-index: 200;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        .modal-box {
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            width: 100%;
            max-width: 460px;
            font-family: 'Courier New', monospace;
            animation: modal-in .15s ease;
        }
        @keyframes modal-in { from { opacity:0; transform:scale(.96); } to { opacity:1; transform:scale(1); } }
        .modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 22px;
            border-bottom: 1px solid #111827;
        }
        .modal-head-title { font-size: .9rem; font-weight: 700; color: #00ff88; }
        .modal-close {
            background: transparent;
            border: none;
            color: #444;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
            transition: color .15s;
        }
        .modal-close:hover { color: #e0e0e0; }
        .modal-body { padding: 22px; }
        .modal-scenario-name { font-size: .78rem; color: #555; margin-bottom: 18px; }
        .modal-field label { display: block; font-size: .7rem; color: #666; margin-bottom: 6px; letter-spacing: .03em; }
        .modal-field input {
            width: 100%;
            padding: 10px 12px;
            background: #080810;
            border: 1px solid #1a1a2e;
            border-radius: 4px;
            color: #e0e0e0;
            font-family: 'Courier New', monospace;
            font-size: .875rem;
            outline: none;
            transition: border-color .15s;
        }
        .modal-field input:focus { border-color: #00ff88; }
        .modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            padding: 14px 22px;
            border-top: 1px solid #111827;
        }
        .btn-modal-cancel {
            background: transparent;
            border: 1px solid #1a1a2e;
            color: #888;
            font-size: .8rem;
            font-family: 'Courier New', monospace;
            padding: 8px 18px;
            border-radius: 4px;
            cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .btn-modal-cancel:hover { border-color: #444; color: #e0e0e0; }
        .btn-modal-start {
            background: #00ff88;
            color: #080810;
            font-weight: 700;
            font-size: .8rem;
            font-family: 'Courier New', monospace;
            padding: 8px 18px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-modal-start:hover { background: #00cc6a; }
        .error-inline {
            display: none;
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
                <div style="opacity:.2;"><i data-lucide="inbox" style="width:2.5rem;height:2.5rem;"></i></div>
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
                            <i data-lucide="layers" style="width:12px;height:12px;"></i> <span><?= (int)$s['nb_etapes'] ?> énigme<?= $s['nb_etapes'] > 1 ? 's' : '' ?></span>
                        </div>
                        <div class="meta-item">
                            <i data-lucide="radio" style="width:12px;height:12px;"></i> <span>Z-Wave</span>
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
<div class="modal-overlay" id="startModal" onclick="closeModalOnOverlay(event)">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-head-title">Démarrer une partie</span>
            <button class="modal-close" onclick="closeModal()">×</button>
        </div>
        <div class="modal-body">
            <div id="modalScenarioName" class="modal-scenario-name"></div>
            <input type="hidden" id="selectedScenarioId">
            <input type="hidden" id="selectedSalleId" value="1">
            <div class="modal-field">
                <label for="nomJoueur">Nom de votre équipe</label>
                <input type="text" id="nomJoueur" placeholder="ex : Équipe Alpha" maxlength="100">
            </div>
            <div id="startError" class="error-inline"></div>
        </div>
        <div class="modal-foot">
            <button class="btn-modal-cancel" onclick="closeModal()">Annuler</button>
            <button class="btn-modal-start" onclick="startGame()">Lancer la partie →</button>
        </div>
    </div>
</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('nomJoueur').addEventListener('keydown', e => {
        if (e.key === 'Enter') startGame();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal();
    });
});

function openStartModal(idScenario, titre) {
    document.getElementById('selectedScenarioId').value = idScenario;
    document.getElementById('modalScenarioName').textContent = titre;
    document.getElementById('nomJoueur').value = '';
    document.getElementById('startError').style.display = 'none';
    document.getElementById('startModal').classList.add('open');
    setTimeout(() => document.getElementById('nomJoueur').focus(), 50);
}

function closeModal() {
    document.getElementById('startModal').classList.remove('open');
}

function closeModalOnOverlay(e) {
    if (e.target === document.getElementById('startModal')) closeModal();
}

function startGame() {
    const idScenario = document.getElementById('selectedScenarioId').value;
    const nomJoueur  = document.getElementById('nomJoueur').value.trim();
    const errEl      = document.getElementById('startError');

    if (!nomJoueur) {
        errEl.textContent = 'Veuillez entrer un nom d\'équipe.';
        errEl.style.display = 'block';
        return;
    }

    const btn = document.querySelector('.btn-modal-start');
    btn.textContent = 'Démarrage…';
    btn.disabled = true;

    const idSalle = document.getElementById('selectedSalleId').value;

    const fd = new FormData();
    fd.append('id_scenario', idScenario);
    fd.append('nom_joueur',  nomJoueur);
    fd.append('id_salle',    idSalle);

    fetch('/domescape/api/start_game.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                window.location.href = '/domescape/public/player.php';
            } else {
                errEl.textContent = data.message || 'Erreur serveur.';
                errEl.style.display = 'block';
                btn.textContent = 'Lancer la partie →';
                btn.disabled = false;
            }
        })
        .catch(() => {
            errEl.textContent = 'Impossible de joindre le serveur.';
            errEl.style.display = 'block';
            btn.textContent = 'Lancer la partie →';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
