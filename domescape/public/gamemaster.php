<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';
RoleGuard::requireAdmin();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DomEscape — Supervision</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

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
            <div class="panel-title">Journal local</div>
            <div id="localLog" style="max-height:400px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;">
                <div class="no-events">—</div>
            </div>
        </div>

        <div class="panel">
            <div class="panel-title">Conditions laboratoire</div>
            <div class="stats-grid" style="grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
                <div class="stat-box">
                    <div class="stat-label">Température</div>
                    <div class="stat-value" id="gmTemp" style="color:#60a5fa;">—</div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Humidité</div>
                    <div class="stat-value" id="gmHumid" style="color:#a78bfa;">—</div>
                </div>
            </div>
            <div id="gmTelemDate" style="font-size:.65rem;color:#333;text-align:center;">—</div>
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

            document.getElementById('gmTeam').textContent  = data.nom_equipe;
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
                addLocalLog('Session #' + data.session_id + ' — ' + data.nom_equipe, 'ok');
            }

            // Événements + actions depuis BDD
            renderEvents(data.events);
            renderActions(data.actions);

            // Télémétrie
            if (data.telemetry) {
                document.getElementById('gmTemp').textContent =
                    data.telemetry.temperature !== null ? data.telemetry.temperature + ' °C' : '—';
                document.getElementById('gmHumid').textContent =
                    data.telemetry.humidite !== null ? data.telemetry.humidite + ' %' : '—';
                document.getElementById('gmTelemDate').textContent =
                    data.telemetry.date ? data.telemetry.date.substr(0, 16) : '—';
            }

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
    fetch('/domescape/api/reset_game.php', { method: 'POST' })
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
