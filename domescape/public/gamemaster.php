<?php
require_once __DIR__ . '/../core/RoleGuard.php';
RoleGuard::requireRole(ROLE_SUPERVISEUR);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DomEscape — Game Master</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; font-family: 'Courier New', monospace; }
        .panel { background: #1a1a2e; border: 1px solid #0f3460; border-radius: 8px; padding: 24px; }
        .label  { color: #888; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px; }
        .value  { color: #00ff88; font-size: 1.4rem; font-weight: bold; }
        .log-entry { border-bottom: 1px solid #1a1a2e; padding: 6px 0; font-size: 0.85rem; }
        .log-ok  { color: #00ff88; }
        .log-err { color: #ff4444; }
        .log-ignore { color: #888; }
        .status-badge { font-size: 0.9rem; padding: 4px 12px; border-radius: 20px; }
        .running  { background: #00ff8833; color: #00ff88; border: 1px solid #00ff88; }
        .won      { background: #0044ff33; color: #4488ff; border: 1px solid #4488ff; }
        .no-game  { background: #33333333; color: #888; border: 1px solid #444; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="container my-4">
    <div class="row g-4">

        <!-- État de la session -->
        <div class="col-md-8">
            <div class="panel">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h5 class="mb-0" style="color:#00ff88;">Session en cours</h5>
                    <span id="statusBadge" class="status-badge no-game">Aucune session</span>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <div class="label">Équipe</div>
                        <div class="value" id="gmTeam">—</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="label">Jeu</div>
                        <div class="value" id="gmGame" style="font-size:1rem;">—</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="label">Temps</div>
                        <div class="value" id="gmTimer">00:00</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="label">Score</div>
                        <div class="value" id="gmScore">0</div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-4">
                        <div class="label">Puzzle en cours</div>
                        <div class="value" id="gmPuzzle">—</div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="label">Erreurs</div>
                        <div class="value" style="color:#ff4444;" id="gmMistakes">0</div>
                    </div>
                    <div class="col-6 col-md-4">
                        <div class="label">Progression</div>
                        <div class="value" id="gmProgress">—</div>
                    </div>
                </div>

                <!-- Puzzle description -->
                <div class="p-3 rounded" style="background:#0d0d0d; border:1px solid #0f3460;">
                    <div class="label mb-1">Description énigme</div>
                    <div id="gmPuzzleDesc" style="color:#ccc; font-size:0.9rem;">—</div>
                </div>
            </div>
        </div>

        <!-- Contrôles Game Master -->
        <div class="col-md-4">
            <div class="panel">
                <h5 class="mb-4" style="color:#00ff88;">Contrôles</h5>
                <div class="d-grid gap-2">
                    <button class="btn btn-outline-danger" onclick="resetSession()">
                        &#8635; Réinitialiser la session
                    </button>
                    <a href="/domescape/public/player.php" class="btn btn-outline-light" target="_blank">
                        &#128065; Vue joueur
                    </a>
                    <a href="/domescape/admin/dashboard.php" class="btn btn-outline-secondary">
                        &#9881; Administration
                    </a>
                </div>
            </div>

            <!-- Derniers événements -->
            <div class="panel mt-4">
                <h5 class="mb-3" style="color:#00ff88;">Événements récents</h5>
                <div id="eventLog" style="max-height:300px; overflow-y:auto;">
                    <div class="log-ignore">En attente d'événements...</div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
let lastSessionId = null;
let startTime     = null;
let timerInterval = null;
const eventLog    = [];

function formatTime(seconds) {
    const m = String(Math.floor(seconds / 60)).padStart(2, '0');
    const s = String(seconds % 60).padStart(2, '0');
    return `${m}:${s}`;
}

function poll() {
    fetch('/domescape/api/session_status.php')
        .then(r => {
            if (r.status === 401 || r.status === 403) {
                window.location.href = '/domescape/public/connexion.php';
                return null;
            }
            return r.json();
        })
        .then(data => {
            if (!data) return;
            const badge = document.getElementById('statusBadge');

            if (data.status === 'no_session') {
                badge.className = 'status-badge no-game';
                badge.textContent = 'Aucune session';
                clearInterval(timerInterval);
                timerInterval = null;
                startTime = null;
                return;
            }

            // Session active
            if (data.status === 'en_cours') {
                badge.className = 'status-badge running';
                badge.textContent = 'En cours';
            } else if (data.status === 'gagnee') {
                badge.className = 'status-badge won';
                badge.textContent = 'Victoire !';
            }

            document.getElementById('gmTeam').textContent  = data.joueur;
            document.getElementById('gmGame').textContent  = data.scenario;
            document.getElementById('gmScore').textContent = data.score;
            document.getElementById('gmMistakes').textContent = data.nb_erreurs;

            if (data.etape && data.etape.id) {
                document.getElementById('gmPuzzle').textContent = data.etape.titre;
                document.getElementById('gmPuzzleDesc').textContent = data.etape.description;
                document.getElementById('gmProgress').textContent =
                    data.etape.numero + ' / ' + data.total_etapes;
            }

            // Timer
            if (!startTime) {
                startTime = Date.now() - data.elapsed_seconds * 1000;
                if (!timerInterval) {
                    timerInterval = setInterval(() => {
                        const el = Math.floor((Date.now() - startTime) / 1000);
                        document.getElementById('gmTimer').textContent = formatTime(el);
                    }, 1000);
                }
            }

            // Détecter nouvelle session
            if (lastSessionId !== data.session_id) {
                lastSessionId = data.session_id;
                addLog(`Nouvelle session #${data.session_id} — ${data.joueur}`, 'ok');
            }
        })
        .catch(() => {});
}

function addLog(msg, type = 'ok') {
    const logEl = document.getElementById('eventLog');
    const entry = document.createElement('div');
    entry.className = `log-entry log-${type}`;
    const now = new Date().toLocaleTimeString();
    entry.textContent = `[${now}] ${msg}`;
    logEl.prepend(entry);
    if (logEl.children.length > 50) logEl.lastChild.remove();
}

function resetSession() {
    if (!confirm('Réinitialiser la session en cours ?')) return;
    fetch('/domescape/api/reset_game.php')
        .then(r => r.json())
        .then(() => {
            addLog('Session réinitialisée par le Game Master.', 'err');
            startTime = null;
            clearInterval(timerInterval);
            timerInterval = null;
        });
}

poll();
setInterval(poll, 1000);
</script>
</body>
</html>
