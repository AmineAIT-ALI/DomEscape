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
        .topbar-brand { font-size: .78rem; font-weight: 700; color: #00ff88; letter-spacing: .1em; text-decoration: none; }
        .topbar-title { font-size: .72rem; color: #444; letter-spacing: .08em; text-transform: uppercase; }
        .topbar-links { display: flex; gap: 16px; }
        .topbar-link {
            font-size: .72rem;
            color: #444;
            text-decoration: none;
            padding: 4px 10px;
            border: 1px solid #1f2937;
            border-radius: 3px;
            transition: color .15s, border-color .15s;
        }
        .topbar-link:hover { color: #e0e0e0; border-color: #374151; }

        /* ── Layout ── */
        .layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 16px;
            padding: 20px;
            flex: 1;
        }
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
        }

        /* ── Panel ── */
        .panel {
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            padding: 20px;
        }
        .panel-title {
            font-size: .68rem;
            color: #333;
            letter-spacing: .12em;
            text-transform: uppercase;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 1px solid #111827;
        }
        .panel-title span { color: #00ff88; }

        /* ── Status badge ── */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .7rem;
            padding: 3px 10px;
            border-radius: 20px;
        }
        .status-dot { width: 6px; height: 6px; border-radius: 50%; }
        .badge-running { background: rgba(0,255,136,.08); color: #00ff88; border: 1px solid rgba(0,255,136,.3); }
        .badge-running .status-dot { background: #00ff88; box-shadow: 0 0 6px #00ff88; animation: blink 1.2s infinite; }
        .badge-won     { background: rgba(68,136,255,.08); color: #4488ff; border: 1px solid rgba(68,136,255,.3); }
        .badge-won .status-dot { background: #4488ff; }
        .badge-idle    { background: rgba(255,255,255,.03); color: #444; border: 1px solid #1f2937; }
        .badge-idle .status-dot { background: #333; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }

        /* ── Stats grid ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: #080810;
            border: 1px solid #111827;
            border-radius: 6px;
            padding: 14px 16px;
        }
        .stat-label { font-size: .62rem; color: #333; letter-spacing: .1em; text-transform: uppercase; margin-bottom: 6px; }
        .stat-value { font-size: 1.3rem; font-weight: 700; color: #e0e0e0; }
        .stat-value.green  { color: #00ff88; }
        .stat-value.red    { color: #ff4444; }
        .stat-value.yellow { color: #f0c040; }

        /* ── Puzzle info ── */
        .puzzle-info {
            background: #080810;
            border: 1px solid #111827;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 20px;
        }
        .puzzle-info-label {
            font-size: .6rem;
            color: #333;
            letter-spacing: .1em;
            text-transform: uppercase;
            margin-bottom: 6px;
        }
        .puzzle-info-title { font-size: .92rem; color: #e0e0e0; margin-bottom: 6px; }
        .puzzle-info-desc  { font-size: .78rem; color: #555; line-height: 1.55; }

        /* ── Event timeline ── */
        .event-list { display: flex; flex-direction: column; gap: 4px; }
        .event-row {
            display: grid;
            grid-template-columns: 60px 1fr auto;
            align-items: center;
            gap: 10px;
            padding: 7px 10px;
            border-radius: 4px;
            font-size: .75rem;
            background: rgba(255,255,255,.02);
            border: 1px solid transparent;
            transition: border-color .2s;
        }
        .event-row:hover { border-color: #1f2937; }
        .event-time  { color: #333; font-size: .7rem; }
        .event-body  { display: flex; flex-direction: column; gap: 2px; }
        .event-code  { color: #aaa; font-size: .75rem; }
        .event-sensor{ color: #444; font-size: .68rem; }
        .event-badge {
            font-size: .62rem;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 700;
            white-space: nowrap;
        }
        .badge-ok  { background: rgba(0,255,136,.1);  color: #00ff88; }
        .badge-err { background: rgba(255,68,68,.1);  color: #ff4444; }
        .badge-ign { background: rgba(255,255,255,.04); color: #444; }
        .no-events { font-size: .75rem; color: #333; padding: 16px 0; text-align: center; }

        /* ── Action log ── */
        .action-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 0;
            border-bottom: 1px solid #0d0d1a;
            font-size: .73rem;
        }
        .action-time   { color: #333; font-size: .68rem; flex-shrink: 0; }
        .action-code   { color: #888; flex: 1; }
        .action-target { color: #444; font-size: .68rem; }
        .action-ok  { color: #00ff88; font-size: .65rem; }
        .action-err { color: #ff4444; font-size: .65rem; }

        /* ── Controls ── */
        .controls { display: flex; flex-direction: column; gap: 8px; }
        .btn-ctrl {
            display: block;
            width: 100%;
            padding: 10px 14px;
            background: transparent;
            border: 1px solid #1f2937;
            color: #888;
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            border-radius: 4px;
            cursor: pointer;
            text-align: left;
            text-decoration: none;
            transition: border-color .15s, color .15s;
        }
        .btn-ctrl:hover { border-color: #374151; color: #e0e0e0; }
        .btn-ctrl.yellow:hover { border-color: #f0c040; color: #f0c040; }
        .btn-ctrl.red:hover    { border-color: #ff4444; color: #ff4444; }
        .btn-ctrl.green:hover  { border-color: #00ff88; color: #00ff88; }
        .btn-ctrl-prefix { color: #333; margin-right: 8px; }
    </style>
</head>
<body>

<div class="topbar">
    <a href="/domescape/public/index.php" class="topbar-brand">&#9632; DomEscape</a>
    <div class="topbar-title">Game Master</div>
    <div class="topbar-links">
        <a href="/domescape/public/player.php" class="topbar-link" target="_blank">Vue joueur ↗</a>
        <a href="/domescape/admin/dashboard.php" class="topbar-link">Admin</a>
    </div>
</div>

<div class="layout">

    <!-- Colonne gauche : session + timeline -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <!-- Session -->
        <div class="panel">
            <div class="panel-title" style="display:flex;align-items:center;justify-content:space-between;">
                <span>Session active</span>
                <span id="statusBadge" class="status-badge badge-idle">
                    <span class="status-dot"></span> Aucune session
                </span>
            </div>

            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Équipe</div>
                    <div class="stat-value" id="gmTeam" style="font-size:1rem;">—</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Temps</div>
                    <div class="stat-value green" id="gmTimer">00:00</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Score</div>
                    <div class="stat-value" id="gmScore">0</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Erreurs</div>
                    <div class="stat-value red" id="gmMistakes">0</div>
                </div>
            </div>

            <div class="puzzle-info">
                <div class="puzzle-info-label">
                    Étape <span id="gmProgress" style="color:#00ff88;">—</span>
                    &nbsp;—&nbsp; <span id="gmGame" style="color:#555;font-size:.7rem;">—</span>
                </div>
                <div class="puzzle-info-title" id="gmPuzzle">—</div>
                <div class="puzzle-info-desc"  id="gmPuzzleDesc">—</div>
            </div>
        </div>

        <!-- Événements BDD -->
        <div class="panel">
            <div class="panel-title">
                Événements capteurs &nbsp;<span id="evtCount"></span>
            </div>
            <div class="event-list" id="eventList">
                <div class="no-events">En attente d'événements…</div>
            </div>
        </div>

        <!-- Actions exécutées -->
        <div class="panel">
            <div class="panel-title">Dernières actions physiques</div>
            <div id="actionList">
                <div class="no-events">Aucune action exécutée.</div>
            </div>
        </div>

    </div>

    <!-- Colonne droite : contrôles -->
    <div style="display:flex;flex-direction:column;gap:16px;">

        <div class="panel">
            <div class="panel-title">Contrôles</div>
            <div class="controls">
                <button class="btn-ctrl yellow" onclick="sendHint()">
                    <span class="btn-ctrl-prefix">›</span> Envoyer un indice
                </button>
                <button class="btn-ctrl red" onclick="resetSession()">
                    <span class="btn-ctrl-prefix">›</span> Réinitialiser la session
                </button>
                <a href="/domescape/public/player.php" class="btn-ctrl green" target="_blank">
                    <span class="btn-ctrl-prefix">›</span> Ouvrir vue joueur
                </a>
                <a href="/domescape/admin/dashboard.php" class="btn-ctrl">
                    <span class="btn-ctrl-prefix">›</span> Administration
                </a>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Log local</div>
            <div id="localLog" style="max-height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;">
                <div class="no-events">—</div>
            </div>
        </div>

    </div>
</div>

<script>
let lastSessionId = null;
let startTime     = null;
let timerInterval = null;
let lastEvtCount  = 0;

function fmt(s) {
    return String(Math.floor(s / 60)).padStart(2,'0') + ':' + String(s % 60).padStart(2,'0');
}

function setBadge(status) {
    const b = document.getElementById('statusBadge');
    if (status === 'en_cours') {
        b.className = 'status-badge badge-running';
        b.innerHTML = '<span class="status-dot"></span> En cours';
    } else if (status === 'gagnee') {
        b.className = 'status-badge badge-won';
        b.innerHTML = '<span class="status-dot"></span> Victoire';
    } else {
        b.className = 'status-badge badge-idle';
        b.innerHTML = '<span class="status-dot"></span> Aucune session';
    }
}

function renderEvents(events) {
    const list = document.getElementById('eventList');
    if (!events || events.length === 0) {
        list.innerHTML = '<div class="no-events">Aucun événement enregistré.</div>';
        return;
    }
    document.getElementById('evtCount').textContent = events.length + ' entrées';
    list.innerHTML = events.map(e => {
        const badge = e.valide
            ? (e.attendu ? '<span class="event-badge badge-ok">✓ valide</span>'
                         : '<span class="event-badge badge-ign">ignoré</span>')
            : '<span class="event-badge badge-err">✗ hors session</span>';
        const etape = e.etape ? ` · étape ${e.etape}` : '';
        return `<div class="event-row">
            <div class="event-time">${e.time}</div>
            <div class="event-body">
                <div class="event-code">${e.code}</div>
                <div class="event-sensor">${e.capteur}${etape}</div>
            </div>
            ${badge}
        </div>`;
    }).join('');
}

function renderActions(actions) {
    const list = document.getElementById('actionList');
    if (!actions || actions.length === 0) {
        list.innerHTML = '<div class="no-events">Aucune action exécutée.</div>';
        return;
    }
    list.innerHTML = actions.map(a => {
        const statClass = a.statut === 'ok' ? 'action-ok' : 'action-err';
        const val = a.valeur ? ` — "${a.valeur}"` : '';
        return `<div class="action-row">
            <div class="action-time">${a.time}</div>
            <div class="action-code">${a.code}${val}</div>
            <div class="action-target">${a.acteur}</div>
            <div class="${statClass}">${a.statut}</div>
        </div>`;
    }).join('');
}

function addLocalLog(msg, type) {
    const log = document.getElementById('localLog');
    if (log.querySelector('.no-events')) log.innerHTML = '';
    const div = document.createElement('div');
    div.style.cssText = 'font-size:.7rem;padding:3px 0;border-bottom:1px solid #0d0d1a;';
    div.style.color = type === 'ok' ? '#00ff88' : type === 'err' ? '#ff4444' : '#444';
    div.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
    log.prepend(div);
    if (log.children.length > 60) log.lastChild.remove();
}

function poll() {
    fetch('/domescape/api/gamemaster_status.php')
        .then(r => {
            if (r.status === 401 || r.status === 403) {
                window.location.href = '/domescape/public/connexion.php';
                return null;
            }
            return r.json();
        })
        .then(data => {
            if (!data) return;

            if (data.status === 'no_session') {
                setBadge('idle');
                clearInterval(timerInterval); timerInterval = null; startTime = null;
                return;
            }

            setBadge(data.status);

            document.getElementById('gmTeam').textContent  = data.joueur;
            document.getElementById('gmGame').textContent  = data.scenario;
            document.getElementById('gmScore').textContent = data.score;
            document.getElementById('gmMistakes').textContent = data.nb_erreurs;

            if (data.etape && data.etape.id) {
                document.getElementById('gmProgress').textContent =
                    data.etape.numero + ' / ' + data.total_etapes;
                document.getElementById('gmPuzzle').textContent    = data.etape.titre;
                document.getElementById('gmPuzzleDesc').textContent = data.etape.description;
            }

            // Timer
            if (data.status === 'en_cours') {
                if (!startTime) {
                    startTime = Date.now() - data.elapsed_seconds * 1000;
                    if (!timerInterval) {
                        timerInterval = setInterval(() => {
                            document.getElementById('gmTimer').textContent =
                                fmt(Math.floor((Date.now() - startTime) / 1000));
                        }, 1000);
                    }
                }
            } else {
                clearInterval(timerInterval); timerInterval = null;
                document.getElementById('gmTimer').textContent = fmt(data.elapsed_seconds);
            }

            // Nouvelle session détectée
            if (lastSessionId !== data.session_id) {
                lastSessionId = data.session_id;
                startTime = null;
                addLocalLog('Session #' + data.session_id + ' — ' + data.joueur, 'ok');
            }

            // Événements + actions depuis BDD
            renderEvents(data.events);
            renderActions(data.actions);

            // Nouveaux événements depuis dernier poll
            if (data.events && data.events.length > lastEvtCount && lastEvtCount > 0) {
                const newest = data.events[0];
                const type = newest.valide && newest.attendu ? 'ok' : 'ignore';
                addLocalLog(newest.code + ' — ' + newest.capteur, type);
            }
            lastEvtCount = data.events ? data.events.length : 0;
        })
        .catch(() => addLocalLog('Connexion perdue', 'err'));
}

function sendHint() {
    fetch('/domescape/api/send_hint.php', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                addLocalLog('Indice envoyé : ' + data.indice, 'ok');
            } else {
                addLocalLog(data.message || 'Erreur indice', 'err');
            }
        });
}

function resetSession() {
    if (!confirm('Réinitialiser la session en cours ?')) return;
    fetch('/domescape/api/reset_game.php')
        .then(r => r.json())
        .then(() => {
            addLocalLog('Session réinitialisée.', 'err');
            startTime = null;
            clearInterval(timerInterval); timerInterval = null;
        });
}

poll();
setInterval(poll, 2000);
</script>
</body>
</html>
