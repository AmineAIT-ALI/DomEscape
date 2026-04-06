<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/ScenarioRepository.php';

RoleGuard::requireLogin();

$pdo = getDB();

// Scénarios actifs avec compte d'étapes
$scenarios = ScenarioRepository::getActive();

$authUser  = Auth::user();
$authRoles = Auth::buildHierarchy(Auth::roles());

// Session mono-salle : une seule session active (en_attente ou en_cours)
$activeSession  = null;
$isMembre       = false;      // l'utilisateur est déjà dans session_utilisateur
$hasPending     = false;      // l'utilisateur a une demande en_attente

$stmtActive = $pdo->query("
    SELECT se.*, sc.nom_scenario
    FROM session se
    JOIN scenario sc ON se.id_scenario = sc.id_scenario
    WHERE se.statut_session IN ('en_attente', 'en_cours')
    ORDER BY se.date_debut DESC
    LIMIT 1
");
$activeSession = $stmtActive->fetch() ?: null;

if ($activeSession && $authUser) {
    $idUser = (int)$authUser['id'];

    // L'utilisateur est-il déjà membre ?
    $stmtM = $pdo->prepare("
        SELECT 1 FROM session_utilisateur
        WHERE id_session = ? AND id_utilisateur = ? LIMIT 1
    ");
    $stmtM->execute([$activeSession['id_session'], $idUser]);
    $isMembre = (bool)$stmtM->fetch();

    // A-t-il une demande en_attente ?
    if (!$isMembre) {
        $stmtP = $pdo->prepare("
            SELECT 1 FROM demande_rejoindre_session
            WHERE id_session = ? AND id_utilisateur = ? AND statut_demande = 'en_attente'
            LIMIT 1
        ");
        $stmtP->execute([$activeSession['id_session'], $idUser]);
        $hasPending = (bool)$stmtP->fetch();
    }
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
            cursor: pointer;
        }
        .btn-resume:hover { background: #00cc6a; color: #080810; }
        .btn-join {
            background: transparent;
            color: #00ff88;
            font-weight: 700;
            font-size: .78rem;
            padding: 8px 18px;
            border: 1px solid rgba(0,255,136,.4);
            border-radius: 4px;
            white-space: nowrap;
            transition: background .15s, color .15s;
            cursor: pointer;
        }
        .btn-join:hover { background: rgba(0,255,136,.1); }
        .btn-pending {
            font-size: .75rem;
            color: #f0c040;
            border: 1px solid rgba(240,192,64,.3);
            background: rgba(240,192,64,.05);
            padding: 8px 18px;
            border-radius: 4px;
            white-space: nowrap;
        }
        .session-banner-status {
            font-size: .65rem;
            padding: 2px 7px;
            border-radius: 3px;
            border: 1px solid;
            margin-left: 8px;
        }
        .status-en-attente { color: #f0c040; border-color: rgba(240,192,64,.3); background: rgba(240,192,64,.06); }
        .status-en-cours   { color: #00ff88; border-color: rgba(0,255,136,.3);  background: rgba(0,255,136,.06); }

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
                <?php
                    $statut = $activeSession['statut_session'];
                    $labelStatut = $statut === 'en_attente' ? 'En attente' : 'En cours';
                    $cssStatut   = $statut === 'en_attente' ? 'status-en-attente' : 'status-en-cours';
                ?>
                <strong><?= htmlspecialchars($activeSession['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="session-banner-status <?= $cssStatut ?>"><?= $labelStatut ?></span>
            </div>

            <?php if ($isMembre): ?>
                <a href="/domescape/public/player.php" class="btn-resume">Reprendre →</a>

            <?php elseif ($statut === 'en_attente'): ?>
                <button class="btn-join" onclick="joinSession(<?= (int)$activeSession['id_session'] ?>)">
                    Rejoindre la session
                </button>

            <?php elseif ($hasPending): ?>
                <span class="btn-pending">⏳ Demande en attente</span>

            <?php else: ?>
                <button class="btn-join" onclick="openRequestModal(<?= (int)$activeSession['id_session'] ?>)">
                    Demander à rejoindre
                </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($activeSession): ?>
            <!-- Session active → impossible de créer une nouvelle partie -->

        <?php elseif (empty($scenarios)): ?>
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
                        <?php if ($s['nb_joueurs_min'] !== null || $s['nb_joueurs_max'] !== null): ?>
                        <div class="meta-item">
                            <i data-lucide="users" style="width:12px;height:12px;"></i>
                            <span>
                            <?php
                                $min = $s['nb_joueurs_min'];
                                $max = $s['nb_joueurs_max'];
                                if ($min !== null && $max !== null) {
                                    echo $min === $max ? $min . ' joueur' . ($min > 1 ? 's' : '') : $min . '–' . $max . ' joueurs';
                                } elseif ($max !== null) {
                                    echo 'max ' . $max . ' joueurs';
                                } else {
                                    echo 'min ' . $min . ' joueur' . ($min > 1 ? 's' : '');
                                }
                            ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <?php if ($s['duree_max_secondes'] !== null): ?>
                        <div class="meta-item">
                            <i data-lucide="clock" style="width:12px;height:12px;"></i>
                            <span><?= floor($s['duree_max_secondes'] / 60) ?>min</span>
                        </div>
                        <?php endif; ?>
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

<!-- Modal demande rejoindre -->
<div class="modal-overlay" id="requestModal" onclick="closeRequestModalOnOverlay(event)">
    <div class="modal-box">
        <div class="modal-head">
            <span class="modal-head-title">Demander à rejoindre</span>
            <button class="modal-close" onclick="closeRequestModal()">×</button>
        </div>
        <div class="modal-body">
            <div class="modal-scenario-name" style="margin-bottom:14px;">La partie est en cours. Envoyez une demande au superviseur.</div>
            <input type="hidden" id="requestSessionId">
            <div class="modal-field">
                <label for="requestMessage">Message (optionnel)</label>
                <input type="text" id="requestMessage" placeholder="ex : Je suis en retard, je peux rejoindre ?" maxlength="200">
            </div>
            <div id="requestError" class="error-inline"></div>
        </div>
        <div class="modal-foot">
            <button class="btn-modal-cancel" onclick="closeRequestModal()">Annuler</button>
            <button class="btn-modal-start" onclick="submitRequest()">Envoyer la demande →</button>
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
        if (e.key === 'Escape') { closeModal(); closeRequestModal(); }
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

// --- Rejoindre directement (session en_attente) ---
function joinSession(idSession) {
    const fd = new FormData();
    fd.append('id_session', idSession);
    fetch('/domescape/api/join_session.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                window.location.href = '/domescape/public/player.php';
            } else {
                alert(data.message || 'Erreur lors de la tentative de rejoindre.');
            }
        })
        .catch(() => alert('Impossible de joindre le serveur.'));
}

// --- Modal demande rejoindre (session en_cours) ---
function openRequestModal(idSession) {
    document.getElementById('requestSessionId').value = idSession;
    document.getElementById('requestMessage').value   = '';
    document.getElementById('requestError').style.display = 'none';
    document.getElementById('requestModal').classList.add('open');
    setTimeout(() => document.getElementById('requestMessage').focus(), 50);
}

function closeRequestModal() {
    document.getElementById('requestModal').classList.remove('open');
}

function closeRequestModalOnOverlay(e) {
    if (e.target === document.getElementById('requestModal')) closeRequestModal();
}

function submitRequest() {
    const idSession = document.getElementById('requestSessionId').value;
    const message   = document.getElementById('requestMessage').value.trim();
    const errEl     = document.getElementById('requestError');
    const btn       = document.querySelector('#requestModal .btn-modal-start');

    btn.textContent = 'Envoi…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('id_session',      idSession);
    fd.append('message_demande', message);

    fetch('/domescape/api/request_join_session.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                closeRequestModal();
                location.reload();
            } else {
                errEl.textContent = data.message || 'Erreur serveur.';
                errEl.style.display = 'block';
                btn.textContent = 'Envoyer la demande →';
                btn.disabled = false;
            }
        })
        .catch(() => {
            errEl.textContent = 'Impossible de joindre le serveur.';
            errEl.style.display = 'block';
            btn.textContent = 'Envoyer la demande →';
            btn.disabled = false;
        });
}
</script>
</body>
</html>
