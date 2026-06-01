<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireLogin();

$pdo     = getDB();
$isAdmin = Auth::isAdmin();

// Scénarios actifs avec compte d'étapes
$scenarios = $pdo->query("
    SELECT s.*, COUNT(e.id_etape) AS nb_etapes
    FROM scenario s
    LEFT JOIN etape e ON s.id_scenario = e.id_scenario
    WHERE s.actif = 1
    GROUP BY s.id_scenario
    ORDER BY s.cree_le DESC
")->fetchAll();

// Session active (mono-salle)
$stmtActive = $pdo->query("
    SELECT se.*, sc.nom_scenario
    FROM session se
    JOIN scenario sc ON se.id_scenario = sc.id_scenario
    WHERE se.statut_session = 'en_cours'
    LIMIT 1
");
$activeSession = $stmtActive->fetch() ?: null;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jouer — DomEscape</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
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
                <strong><?= htmlspecialchars($activeSession['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></strong>
                <span class="session-banner-status">En cours</span>
                &nbsp;—&nbsp;
                <span style="color:#555;"><?= htmlspecialchars($activeSession['nom_equipe'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <a href="/domescape/public/player.php" class="btn btn-primary btn-resume">Reprendre →</a>
        </div>
        <?php endif; ?>

        <?php if ($activeSession): ?>

        <?php elseif (empty($scenarios)): ?>
            <div class="empty-state">
                <div style="opacity:.2;"><i data-lucide="inbox" style="width:2.5rem;height:2.5rem;"></i></div>
                <p>Aucun scénario disponible pour le moment.</p>
                <?php if ($isAdmin): ?>
                    <a href="/domescape/admin/scenarios.php" style="color:#00ff88;font-size:.8rem;">Configurer un scénario →</a>
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
                            <i data-lucide="layers" style="width:12px;height:12px;"></i>
                            <span><?= (int)$s['nb_etapes'] ?> énigme<?= $s['nb_etapes'] > 1 ? 's' : '' ?></span>
                        </div>
                        <?php if ($s['duree_max_secondes'] !== null): ?>
                        <div class="meta-item">
                            <i data-lucide="clock" style="width:12px;height:12px;"></i>
                            <span><?= floor($s['duree_max_secondes'] / 60) ?>min</span>
                        </div>
                        <?php endif; ?>
                    </div>

                    <button class="btn btn-primary btn-block btn-play">Jouer ce scénario →</button>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
</div>

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
                <label for="nomEquipe">Nom de l'équipe</label>
                <input type="text" id="nomEquipe" placeholder="ex : Équipe Alpha" maxlength="100">
            </div>
            <div id="startError" class="error-inline"></div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-outline btn-modal-cancel" onclick="closeModal()">Annuler</button>
            <button class="btn btn-primary btn-modal-start" onclick="startGame()">Lancer la partie →</button>
        </div>
    </div>
</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('nomEquipe').addEventListener('keydown', e => {
        if (e.key === 'Enter') startGame();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeModal();
    });
});

function openStartModal(idScenario, titre) {
    document.getElementById('selectedScenarioId').value = idScenario;
    document.getElementById('modalScenarioName').textContent = titre;
    document.getElementById('nomEquipe').value = '';
    document.getElementById('startError').style.display = 'none';
    document.getElementById('startModal').classList.add('open');
    setTimeout(() => document.getElementById('nomEquipe').focus(), 50);
}

function closeModal() {
    document.getElementById('startModal').classList.remove('open');
}

function closeModalOnOverlay(e) {
    if (e.target === document.getElementById('startModal')) closeModal();
}

function startGame() {
    const idScenario = document.getElementById('selectedScenarioId').value;
    const nomEquipe  = document.getElementById('nomEquipe').value.trim();
    const errEl      = document.getElementById('startError');

    if (!nomEquipe) {
        errEl.textContent = 'Veuillez entrer un nom d\'équipe.';
        errEl.style.display = 'block';
        return;
    }

    const btn = document.querySelector('.btn-modal-start');
    btn.textContent = 'Démarrage…';
    btn.disabled = true;

    const fd = new FormData();
    fd.append('id_scenario', idScenario);
    fd.append('nom_equipe',  nomEquipe);

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
