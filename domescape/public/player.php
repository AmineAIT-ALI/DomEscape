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
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: #080810;
            color: #e0e0e0;
            font-family: 'Courier New', monospace;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Top bar ── */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 24px;
            height: 52px;
            background: #0a0a14;
            border-bottom: 1px solid #111827;
            flex-shrink: 0;
        }
        .topbar-brand {
            font-size: .78rem;
            font-weight: 700;
            color: #00ff88;
            letter-spacing: .1em;
            text-transform: uppercase;
            text-decoration: none;
        }
        .topbar-team {
            font-size: .75rem;
            color: #555;
        }
        .topbar-team strong { color: #aaa; }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .btn-abandon {
            background: transparent;
            border: 1px solid #1f2937;
            color: #444;
            font-family: 'Courier New', monospace;
            font-size: .72rem;
            padding: 5px 12px;
            border-radius: 3px;
            cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .btn-abandon:hover { border-color: #ff4444; color: #ff4444; }

        /* ── Network error ── */
        .net-error {
            display: none;
            background: #1a0808;
            border-bottom: 1px solid #ff444433;
            color: #ff6666;
            text-align: center;
            padding: 7px;
            font-size: .72rem;
        }

        /* ── Main ── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }

        /* ── Timer ── */
        .timer-display {
            font-size: 3rem;
            font-weight: 700;
            color: #00ff88;
            letter-spacing: .12em;
            line-height: 1;
            margin-bottom: 6px;
            text-shadow: 0 0 24px rgba(0, 255, 136, .3);
        }
        .timer-label {
            font-size: .65rem;
            color: #333;
            letter-spacing: .15em;
            text-transform: uppercase;
            margin-bottom: 40px;
        }

        /* ── Progress ── */
        .progress-track {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 40px;
        }
        .progress-step {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
        }
        .progress-step-circle {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .68rem;
            font-weight: 700;
            transition: background .3s, border-color .3s;
        }
        .step-done    { background: #00ff88; color: #080810; border: 2px solid #00ff88; }
        .step-current { background: transparent; color: #00ff88; border: 2px solid #00ff88;
                        box-shadow: 0 0 10px rgba(0,255,136,.4); }
        .step-future  { background: transparent; color: #333; border: 2px solid #1f2937; }
        .progress-step-label {
            font-size: .58rem;
            color: #333;
            max-width: 56px;
            text-align: center;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .step-current .progress-step-label, .step-done .progress-step-label { color: #555; }
        .progress-connector {
            width: 32px;
            height: 1px;
            margin-bottom: 20px;
            transition: background .3s;
        }
        .connector-done    { background: #00ff88; }
        .connector-pending { background: #1f2937; }

        /* ── Puzzle card ── */
        .puzzle-card {
            width: 100%;
            max-width: 560px;
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 10px;
            padding: 32px;
        }
        .puzzle-meta {
            font-size: .65rem;
            color: #333;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .puzzle-meta span { color: #00ff88; }
        .puzzle-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #e0e0e0;
            margin-bottom: 14px;
            line-height: 1.35;
        }
        .puzzle-desc {
            font-size: .85rem;
            color: #666;
            line-height: 1.65;
        }

        /* ── Hint ── */
        .hint-box {
            display: none;
            margin-top: 20px;
            padding: 14px 16px;
            background: rgba(240,192,64,.04);
            border: 1px solid rgba(240,192,64,.2);
            border-radius: 6px;
        }
        .hint-label {
            font-size: .62rem;
            color: #f0c040;
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .hint-text { font-size: .82rem; color: #c9a530; line-height: 1.5; }

        /* ── Stats bar ── */
        .stats-bar {
            display: flex;
            gap: 32px;
            margin-top: 28px;
        }
        .stat-item { text-align: center; }
        .stat-value { font-size: 1.1rem; font-weight: 700; color: #e0e0e0; }
        .stat-label { font-size: .62rem; color: #333; letter-spacing: .1em; text-transform: uppercase; margin-top: 2px; }
        .stat-errors .stat-value { color: #ff4444; }

        /* ── No session ── */
        .no-session {
            display: none;
            text-align: center;
            color: #333;
        }
        .no-session p { font-size: .85rem; margin-bottom: 16px; }
        .btn-start {
            display: inline-block;
            background: #00ff88;
            color: #080810;
            font-family: 'Courier New', monospace;
            font-size: .8rem;
            font-weight: 700;
            padding: 10px 24px;
            border-radius: 4px;
            text-decoration: none;
        }

        /* ── End screens ── */
        .end-screen {
            display: none;
            position: fixed;
            inset: 0;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 32px;
            z-index: 100;
        }
        .end-screen.active { display: flex; }

        .win-screen  { background: #030f07; }
        .lose-screen { background: #0f0303; }

        .end-icon { font-size: 4rem; margin-bottom: 24px; }
        .win-screen  .end-icon { color: #00ff88; text-shadow: 0 0 40px rgba(0,255,136,.5); }
        .lose-screen .end-icon { color: #ff4444; text-shadow: 0 0 40px rgba(255,68,68,.4); }

        .end-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: .05em;
        }
        .win-screen  .end-title { color: #00ff88; }
        .lose-screen .end-title { color: #ff4444; }

        .end-subtitle { font-size: .85rem; color: #555; margin-bottom: 32px; }

        .end-stats {
            display: flex;
            gap: 48px;
            margin-bottom: 40px;
            padding: 24px 40px;
            background: rgba(255,255,255,.02);
            border: 1px solid #1a1a2e;
            border-radius: 8px;
        }
        .end-stat-value { font-size: 1.6rem; font-weight: 700; color: #e0e0e0; }
        .end-stat-label { font-size: .65rem; color: #444; text-transform: uppercase; letter-spacing: .1em; margin-top: 4px; }

        .btn-replay {
            display: inline-block;
            background: transparent;
            border: 1px solid #00ff88;
            color: #00ff88;
            font-family: 'Courier New', monospace;
            font-size: .82rem;
            padding: 10px 28px;
            border-radius: 4px;
            text-decoration: none;
            transition: background .15s, color .15s;
        }
        .btn-replay:hover { background: #00ff88; color: #080810; }
        .btn-replay-red {
            border-color: #ff4444;
            color: #ff4444;
        }
        .btn-replay-red:hover { background: #ff4444; color: #080810; }
        .btn-quit {
            display: inline-block;
            background: transparent;
            border: 1px solid #333;
            color: #555;
            font-family: 'Courier New', monospace;
            font-size: .78rem;
            padding: 10px 28px;
            border-radius: 4px;
            text-decoration: none;
            transition: border-color .15s, color .15s;
        }
        .btn-quit:hover { border-color: #666; color: #aaa; }
        .end-actions { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; }

        /* ── Pulse animation on current step ── */
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 10px rgba(0,255,136,.4); }
            50%       { box-shadow: 0 0 20px rgba(0,255,136,.8); }
        }
        .step-current { animation: pulse-glow 2s infinite; }

        /* ── Lobby (en_attente) ── */
        .lobby-screen {
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            padding: 48px 24px;
            text-align: center;
        }
        .lobby-screen.active { display: flex; }
        .lobby-icon {
            font-size: 2.4rem;
            margin-bottom: 24px;
            color: #f0c040;
            text-shadow: 0 0 30px rgba(240,192,64,.4);
        }
        .lobby-title {
            font-size: 1.1rem;
            font-weight: 700;
            color: #e0e0e0;
            margin-bottom: 8px;
        }
        .lobby-subtitle {
            font-size: .82rem;
            color: #555;
            margin-bottom: 36px;
        }
        .lobby-counter {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 32px;
        }
        .lobby-count-current {
            font-size: 3rem;
            font-weight: 700;
            color: #f0c040;
            line-height: 1;
        }
        .lobby-count-sep { font-size: 1.4rem; color: #333; }
        .lobby-count-min { font-size: 1.8rem; color: #333; }
        .lobby-count-label {
            font-size: .65rem;
            color: #444;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 32px;
        }
        .lobby-dots {
            display: flex;
            gap: 6px;
            margin-bottom: 40px;
        }
        .lobby-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            border: 1px solid #1f2937;
            background: transparent;
            transition: background .3s, border-color .3s;
        }
        .lobby-dot.filled {
            background: #f0c040;
            border-color: #f0c040;
            box-shadow: 0 0 6px rgba(240,192,64,.5);
        }
        .lobby-info {
            font-size: .75rem;
            color: #333;
        }
        @keyframes lobby-pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: .3; }
        }
        .lobby-waiting { animation: lobby-pulse 1.8s infinite; }
    </style>
</head>
<body>

<!-- Top bar -->
<div class="topbar">
    <a href="/domescape/public/index.php" class="topbar-brand">&#9632; DomEscape</a>
    <div class="topbar-team" id="teamDisplay"></div>
    <div class="topbar-right">
        <button id="abandonBtn" class="btn-abandon" style="display:none;" onclick="abandonGame()">Abandonner</button>
    </div>
</div>

<!-- Network error -->
<div class="net-error" id="networkError">
    Connexion perdue — nouvelle tentative en cours…
</div>

<!-- Lobby en_attente -->
<div class="lobby-screen" id="lobbyScreen">
    <div class="lobby-icon lobby-waiting">⧖</div>
    <div class="lobby-title">En attente de joueurs</div>
    <div class="lobby-subtitle" id="lobbySubtitle"></div>
    <div class="lobby-counter">
        <span class="lobby-count-current" id="lobbyCountCurrent">1</span>
        <span class="lobby-count-sep">/</span>
        <span class="lobby-count-min" id="lobbyCountMin">?</span>
    </div>
    <div class="lobby-count-label">joueurs connectés / requis pour démarrer</div>
    <div class="lobby-dots" id="lobbyDots"></div>
    <div class="lobby-info">La partie démarrera automatiquement dès que le minimum sera atteint.</div>
</div>

<!-- Main -->
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

<!-- Victoire -->
<div class="end-screen win-screen" id="winScreen">
    <div class="end-icon">✓</div>
    <div class="end-title">ESCAPE SUCCESSFUL</div>
    <div class="end-subtitle" id="winSubtitle"></div>
    <div class="end-stats" id="winStats"></div>
    <div class="end-actions">
        <a href="/domescape/public/index.php" class="btn-replay">Rejouer →</a>
        <a href="/domescape/public/index.php" id="btnQuit" class="btn-quit">Quitter la session</a>
    </div>
</div>

<!-- Défaite -->
<div class="end-screen lose-screen" id="loseScreen">
    <div class="end-icon">✗</div>
    <div class="end-title">TEMPS ÉCOULÉ</div>
    <div class="end-subtitle" id="loseSubtitle"></div>
    <div class="end-stats" id="loseStats"></div>
    <a href="/domescape/public/index.php" class="btn-replay btn-replay-red">Réessayer →</a>
</div>

<script>
let totalPuzzles  = 0;
let stepTitles    = [];
let startTime     = null;
let timerInterval = null;
let lastStatus    = null;

function showLobby(data) {
    document.getElementById('lobbyScreen').classList.add('active');
    document.getElementById('gameView').style.display   = 'none';
    document.getElementById('abandonBtn').style.display = 'inline-block';
    document.getElementById('teamDisplay').textContent  = data.equipe + ' — ' + data.scenario;

    document.getElementById('lobbySubtitle').textContent =
        data.equipe + ' · ' + data.scenario;

    const current = data.nb_joueurs_actuel || 1;
    const min     = data.nb_joueurs_min    || '?';
    document.getElementById('lobbyCountCurrent').textContent = current;
    document.getElementById('lobbyCountMin').textContent     = min;

    // Points visuels
    const dotsEl = document.getElementById('lobbyDots');
    dotsEl.innerHTML = '';
    const total = typeof min === 'number' ? min : current + 1;
    for (let i = 0; i < total; i++) {
        const d = document.createElement('div');
        d.className = 'lobby-dot' + (i < current ? ' filled' : '');
        dotsEl.appendChild(d);
    }
}

function hideLobby() {
    document.getElementById('lobbyScreen').classList.remove('active');
    document.getElementById('gameView').style.display = '';
}

function fmt(s) {
    return String(Math.floor(s / 60)).padStart(2,'0') + ':' + String(s % 60).padStart(2,'0');
}

function buildProgress(currentNum, total, titles) {
    const track = document.getElementById('progressTrack');
    track.innerHTML = '';
    for (let i = 1; i <= total; i++) {
        // Connecteur avant chaque step sauf le premier
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

        const label = document.createElement('div');
        label.className = 'progress-step-label';
        label.textContent = titles[i - 1] || '';

        stepWrap.appendChild(circle);
        stepWrap.appendChild(label);
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
            data.scenario + ' — ' + data.equipe;
        document.getElementById('winStats').innerHTML =
            `<div class="stat-item"><div class="end-stat-value">${mins}m ${secs}s</div><div class="end-stat-label">Temps</div></div>` +
            `<div class="stat-item"><div class="end-stat-value">${data.score}</div><div class="end-stat-label">Points</div></div>` +
            `<div class="stat-item"><div class="end-stat-value">${data.nb_erreurs}</div><div class="end-stat-label">Erreurs</div></div>`;
        document.getElementById('winScreen').classList.add('active');
    } else {
        document.getElementById('loseSubtitle').textContent =
            data.scenario + ' — ' + data.equipe;
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
                document.getElementById('noSession').style.display = 'block';
                document.getElementById('puzzleCard').style.display = 'none';
                document.getElementById('progressTrack').innerHTML = '';
                hideLobby();
                return;
            }

            // Lobby : session en attente de joueurs
            if (data.status === 'en_attente') {
                showLobby(data);
                lastStatus = 'en_attente';
                return;
            }

            // Transition lobby → jeu
            if (lastStatus === 'en_attente') {
                hideLobby();
                startTime = null; // réinitialiser le timer
            }

            document.getElementById('noSession').style.display  = 'none';
            document.getElementById('puzzleCard').style.display = 'block';
            document.getElementById('abandonBtn').style.display = 'inline-block';
            document.getElementById('teamDisplay').textContent  = data.equipe + ' — ' + data.scenario;
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
                buildProgress(data.etape.numero, totalPuzzles, []);
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
