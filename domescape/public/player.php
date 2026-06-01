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
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body style="display:flex;flex-direction:column">

<div class="topbar">
    <a href="/domescape/public/index.php" class="topbar-brand">
        <img src="/domescape/assets/logo-icon.svg" alt="DomEscape" style="height:22px;width:auto;opacity:.9;">
        DomEscape
    </a>
    <div class="topbar-team" id="teamDisplay"></div>
    <div class="topbar-right">
        <button id="abandonBtn" class="btn-abandon" style="display:none;" onclick="abandonGame()">Abandonner</button>
    </div>
</div>

<div class="net-error" id="networkError">
    Connexion perdue — nouvelle tentative en cours…
</div>

<div class="main" id="gameView">

    <div class="timer-display" id="timer">00:00</div>
    <div class="timer-label">temps écoulé</div>

    <div class="progress-track" id="progressTrack"></div>

    <div class="puzzle-card" id="puzzleCard">
        <div class="puzzle-meta">ÉNIGME <span id="puzzleOrder">—</span></div>
        <div class="puzzle-title" id="puzzleTitle">Chargement…</div>
        <div class="puzzle-desc"  id="puzzleDesc"></div>
        <div class="hint-box" id="hintBox">
            <div class="hint-label">Indice</div>
            <div class="hint-text" id="hintText"></div>
        </div>
    </div>

    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-value" id="score">0</div>
            <div class="stat-label">Points</div>
        </div>
        <div class="stat-item stat-errors">
            <div class="stat-value" id="mistakes">0</div>
            <div class="stat-label">Erreurs</div>
        </div>
    </div>

    <div class="no-session" id="noSession">
        <p>Aucune partie en cours.</p>
        <a href="/domescape/public/index.php" class="btn-start">Démarrer une partie →</a>
    </div>

</div>

<div class="end-screen win-screen" id="winScreen">
    <div class="end-icon">✓</div>
    <div class="end-title">ÉVASION RÉUSSIE</div>
    <div class="end-subtitle" id="winSubtitle"></div>
    <div class="end-stats" id="winStats"></div>
    <div class="end-actions">
        <a href="/domescape/public/index.php" class="btn-replay">Rejouer →</a>
        <a href="/domescape/public/index.php" class="btn-quit">Quitter la session</a>
    </div>
</div>

<div class="end-screen lose-screen" id="loseScreen">
    <div class="end-icon">✗</div>
    <div class="end-title">TEMPS ÉCOULÉ</div>
    <div class="end-subtitle" id="loseSubtitle"></div>
    <div class="end-stats" id="loseStats"></div>
    <a href="/domescape/public/index.php" class="btn-replay btn-replay-red">Réessayer →</a>
</div>

<script>
let totalPuzzles  = 0;
let startTime     = null;
let timerInterval = null;
let lastStatus    = null;

function fmt(s) {
    return String(Math.floor(s / 60)).padStart(2,'0') + ':' + String(s % 60).padStart(2,'0');
}

function buildProgress(currentNum, total) {
    const track = document.getElementById('progressTrack');
    track.innerHTML = '';
    for (let i = 1; i <= total; i++) {
        if (i > 1) {
            const conn = document.createElement('div');
            conn.className = 'progress-connector ' + (i <= currentNum ? 'connector-done' : 'connector-pending');
            track.appendChild(conn);
        }
        const stepWrap = document.createElement('div');
        stepWrap.className = 'progress-step';

        const circle = document.createElement('div');
        const cls = i < currentNum ? 'step-done' : i === currentNum ? 'step-current' : 'step-future';
        circle.className = 'progress-step-circle ' + cls;
        circle.textContent = i < currentNum ? '✓' : i;

        stepWrap.appendChild(circle);
        track.appendChild(stepWrap);
    }
}

function showEndScreen(type, data) {
    clearInterval(timerInterval);
    timerInterval = null;
    document.getElementById('gameView').style.display = 'none';

    const mins = Math.floor(data.elapsed_seconds / 60);
    const secs = data.elapsed_seconds % 60;

    if (type === 'win') {
        document.getElementById('winSubtitle').textContent =
            data.scenario + ' — ' + data.nom_equipe;
        document.getElementById('winStats').innerHTML =
            `<div class="stat-item"><div class="end-stat-value">${mins}m ${secs}s</div><div class="end-stat-label">Temps</div></div>` +
            `<div class="stat-item"><div class="end-stat-value">${data.score}</div><div class="end-stat-label">Points</div></div>` +
            `<div class="stat-item"><div class="end-stat-value">${data.nb_erreurs}</div><div class="end-stat-label">Erreurs</div></div>`;
        document.getElementById('winScreen').classList.add('active');
    } else {
        document.getElementById('loseSubtitle').textContent =
            data.scenario + ' — ' + data.nom_equipe;
        document.getElementById('loseStats').innerHTML =
            `<div class="stat-item"><div class="end-stat-value">${data.score}</div><div class="end-stat-label">Points</div></div>` +
            `<div class="stat-item"><div class="end-stat-value">${data.nb_erreurs}</div><div class="end-stat-label">Erreurs</div></div>`;
        document.getElementById('loseScreen').classList.add('active');
    }
}

function poll() {
    fetch('/domescape/api/session_status.php')
        .then(r => {
            if (r.status === 401) { window.location.href = '/domescape/public/connexion.php'; return null; }
            return r.json();
        })
        .then(data => {
            if (!data) return;
            document.getElementById('networkError').style.display = 'none';

            if (data.status === 'no_session') {
                document.getElementById('noSession').style.display   = 'block';
                document.getElementById('puzzleCard').style.display  = 'none';
                document.getElementById('progressTrack').innerHTML   = '';
                document.getElementById('abandonBtn').style.display  = 'none';
                return;
            }

            document.getElementById('noSession').style.display  = 'none';
            document.getElementById('puzzleCard').style.display = 'block';
            document.getElementById('abandonBtn').style.display = 'inline-block';
            document.getElementById('teamDisplay').textContent  = data.nom_equipe + ' — ' + data.scenario;
            document.getElementById('score').textContent    = data.score;
            document.getElementById('mistakes').textContent = data.nb_erreurs;
            totalPuzzles = data.total_etapes;

            // États terminaux
            if (data.status === 'gagnee' && lastStatus !== 'gagnee') {
                lastStatus = 'gagnee';
                showEndScreen('win', data);
                return;
            }
            if ((data.status === 'perdue' || data.status === 'abandonnee') && lastStatus !== data.status) {
                lastStatus = data.status;
                showEndScreen('lose', data);
                return;
            }
            if (data.status === 'gagnee' || data.status === 'perdue' || data.status === 'abandonnee') return;

            lastStatus = data.status;

            // Timer
            if (!startTime && data.elapsed_seconds >= 0) {
                startTime = Date.now() - data.elapsed_seconds * 1000;
                if (!timerInterval) {
                    timerInterval = setInterval(() => {
                        document.getElementById('timer').textContent =
                            fmt(Math.floor((Date.now() - startTime) / 1000));
                    }, 1000);
                }
            }

            // Étape
            if (data.etape && data.etape.id) {
                document.getElementById('puzzleOrder').textContent = data.etape.numero + ' / ' + totalPuzzles;
                document.getElementById('puzzleTitle').textContent = data.etape.titre;
                document.getElementById('puzzleDesc').textContent  = data.etape.description;
                buildProgress(data.etape.numero, totalPuzzles);
            }

            // Indice
            const hintBox = document.getElementById('hintBox');
            if (data.nb_indices > 0 && data.etape && data.etape.indice) {
                document.getElementById('hintText').textContent = data.etape.indice;
                hintBox.style.display = 'block';
            } else {
                hintBox.style.display = 'none';
            }
        })
        .catch(() => {
            document.getElementById('networkError').style.display = 'block';
        });
}

function abandonGame() {
    if (!confirm('Abandonner la partie en cours ?')) return;
    fetch('/domescape/api/abandon_game.php', { method: 'POST' })
        .then(r => r.json())
        .then(() => { window.location.href = '/domescape/public/index.php'; });
}

poll();
setInterval(poll, 2000);
</script>
</body>
</html>
