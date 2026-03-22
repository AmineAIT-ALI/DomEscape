<?php
require_once __DIR__ . '/../core/RoleGuard.php';
RoleGuard::requireLogin();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DomEscape — Joueur</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #0d0d0d; color: #e0e0e0; font-family: 'Courier New', monospace; }
        .status-bar { background: #111; border-bottom: 1px solid #0f3460; padding: 10px 0; }
        .timer { font-size: 2rem; color: #00ff88; letter-spacing: 4px; }
        .puzzle-card { background: #1a1a2e; border: 1px solid #0f3460; border-radius: 8px; padding: 30px; }
        .puzzle-title { color: #00ff88; font-size: 1.5rem; }
        .puzzle-desc { color: #aaa; margin-top: 10px; }
        .step-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; margin: 0 4px; }
        .dot-done    { background: #00ff88; }
        .dot-current { background: #fff; border: 2px solid #00ff88; }
        .dot-future  { background: #333; }
        #winBanner { display: none; background: #00ff88; color: #0d0d0d; padding: 40px; border-radius: 8px; text-align: center; }
        .mistake-badge { color: #ff4444; font-size: 1rem; }
    </style>
</head>
<body>

<div class="status-bar">
    <div class="container d-flex justify-content-between align-items-center">
        <span style="color:#00ff88; font-weight:bold;">&#9632; DomEscape</span>
        <span id="teamDisplay" class="text-muted small"></span>
        <button id="abandonBtn" onclick="abandonGame()"
                style="display:none; background:transparent; border:1px solid #333; color:#666;
                       font-family:'Courier New',monospace; font-size:.75rem; padding:5px 14px;
                       border-radius:4px; cursor:pointer; transition:all .15s;"
                onmouseover="this.style.borderColor='#ff4444';this.style.color='#ff4444';"
                onmouseout="this.style.borderColor='#333';this.style.color='#666';">
            Abandonner
        </button>
    </div>
</div>

<div class="container my-5">

    <!-- Bannière victoire -->
    <div id="winBanner">
        <h1>&#127942; ESCAPE SUCCESSFUL !</h1>
        <p id="winDetails" class="mt-3"></p>
        <a href="/domescape/public/index.php" class="btn btn-dark mt-3">Rejouer</a>
    </div>

    <!-- Vue jeu en cours -->
    <div id="gameView">
        <!-- Timer + score -->
        <div class="text-center mb-4">
            <div class="timer" id="timer">00:00</div>
            <div class="text-muted small mt-1">
                Score : <span id="score">0</span> pts &nbsp;|&nbsp;
                <span class="mistake-badge">&#10007; <span id="mistakes">0</span> erreur(s)</span>
            </div>
        </div>

        <!-- Progression (dots) -->
        <div class="text-center mb-4" id="progressDots"></div>

        <!-- Puzzle courant -->
        <div class="puzzle-card mx-auto" style="max-width: 600px;">
            <div class="text-muted small mb-2">ÉNIGME <span id="puzzleOrder">-</span></div>
            <div class="puzzle-title" id="puzzleTitle">Chargement...</div>
            <div class="puzzle-desc" id="puzzleDesc"></div>
        </div>

        <!-- Pas de session -->
        <div id="noSession" class="text-center mt-5 d-none">
            <p class="text-muted">Aucune partie en cours.</p>
            <a href="/domescape/public/index.php" class="btn btn-outline-light mt-2">Démarrer une partie</a>
        </div>
    </div>
</div>

<script>
let totalPuzzles = 0;
let startTime    = null;
let timerInterval = null;

function formatTime(seconds) {
    const m = String(Math.floor(seconds / 60)).padStart(2, '0');
    const s = String(seconds % 60).padStart(2, '0');
    return `${m}:${s}`;
}

function renderDots(currentOrder, total) {
    const container = document.getElementById('progressDots');
    container.innerHTML = '';
    for (let i = 1; i <= total; i++) {
        const dot = document.createElement('span');
        dot.className = 'step-dot ' + (i < currentOrder ? 'dot-done' : i === currentOrder ? 'dot-current' : 'dot-future');
        container.appendChild(dot);
    }
}

function poll() {
    fetch('/domescape/api/session_status.php')
        .then(r => {
            if (r.status === 401) {
                window.location.href = '/domescape/public/connexion.php';
                return null;
            }
            return r.json();
        })
        .then(data => {
            if (!data) return;
            if (data.status === 'no_session') {
                document.getElementById('noSession').classList.remove('d-none');
                document.getElementById('gameView').querySelector('.puzzle-card').classList.add('d-none');
                document.getElementById('progressDots').innerHTML = '';
                return;
            }

            document.getElementById('noSession').classList.add('d-none');
            document.getElementById('abandonBtn').style.display = 'inline-block';
            document.getElementById('teamDisplay').textContent = data.joueur + ' — ' + data.scenario;
            document.getElementById('score').textContent    = data.score;
            document.getElementById('mistakes').textContent = data.nb_erreurs;

            totalPuzzles = data.total_etapes;

            if (data.status === 'gagnee') {
                clearInterval(timerInterval);
                document.getElementById('gameView').style.display = 'none';
                document.getElementById('winBanner').style.display = 'block';
                const mins = Math.floor(data.elapsed_seconds / 60);
                const secs = data.elapsed_seconds % 60;
                document.getElementById('winDetails').textContent =
                    `Temps : ${mins}m ${secs}s | Score : ${data.score} pts | Erreurs : ${data.nb_erreurs}`;
                return;
            }

            // Timer
            if (!startTime && data.elapsed_seconds > 0) {
                startTime = Date.now() - data.elapsed_seconds * 1000;
                if (!timerInterval) {
                    timerInterval = setInterval(() => {
                        const elapsed = Math.floor((Date.now() - startTime) / 1000);
                        document.getElementById('timer').textContent = formatTime(elapsed);
                    }, 1000);
                }
            }

            // Étape courante
            if (data.etape && data.etape.id) {
                document.getElementById('puzzleOrder').textContent = data.etape.numero + ' / ' + totalPuzzles;
                document.getElementById('puzzleTitle').textContent = data.etape.titre;
                document.getElementById('puzzleDesc').textContent  = data.etape.description;
                renderDots(data.etape.numero, totalPuzzles);
            }
        })
        .catch(() => {});
}

function abandonGame() {
    if (!confirm('Abandonner la partie en cours ?')) return;
    fetch('/domescape/api/abandon_game.php', { method: 'POST' })
        .then(r => r.json())
        .then(() => { window.location.href = '/domescape/public/index.php'; });
}

// Polling toutes les secondes
poll();
setInterval(poll, 1000);
</script>
</body>
</html>
