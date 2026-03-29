// =============================================================
// debug.js — DomEscape Simulation, Demo & Debug Panel v2
// =============================================================

const BASE = '/domescape/api';

// ── State ─────────────────────────────────────────────────────
let pollTimer   = null;
let startTs     = null;
let timerInt    = null;
let demoRunning = false;
let demoTimers  = [];

const MAX_LOG      = 50;
const MAX_TIMELINE = 20;
const logLines     = [];
const tlEvents     = [];

// ── Event simulation ──────────────────────────────────────────

async function simulateEvent(eventCode) {
    const btn = document.querySelector(`[data-event="${eventCode}"]`);
    if (btn && !demoRunning) { btn.disabled = true; btn.style.opacity = '0.5'; }

    // Capture step before
    let stepBefore = null;
    let statusBefore = null;
    try {
        const r = await fetch(`${BASE}/session_status.php`);
        const d = await r.json();
        stepBefore   = d.etape?.numero ?? null;
        statusBefore = d.status;
    } catch (_) {}

    try {
        const res  = await fetch(`${BASE}/debug_event.php?event=${eventCode}`);
        const data = await res.json();

        if (data.status === 'ignored') {
            addLog(`IGNORED   ${eventCode}  →  ${data.message}`, 'warn');
            addTimeline(eventCode, 'ignored', data.capteur ?? '—');
            return;
        }
        if (data.status !== 'ok') {
            addLog(`ERROR     ${eventCode}  →  ${data.message}`, 'error');
            addTimeline(eventCode, 'error', '—');
            return;
        }

        // Capture step after (small delay for DB write)
        await delay(280);
        const r2 = await fetch(`${BASE}/session_status.php`);
        const after = await r2.json();
        const stepAfter = after.etape?.numero ?? null;
        const isWin = after.status === 'gagnee';

        if (isWin) {
            addLog(`VICTOIRE! ${eventCode}  →  scénario terminé 🏆`, 'ok');
            addTimeline(eventCode, 'win', data.capteur);
        } else if (stepAfter !== null && stepBefore !== null && stepAfter > stepBefore) {
            addLog(`VALID     ${eventCode}  →  étape validée, avance`, 'ok');
            addTimeline(eventCode, 'valid', data.capteur);
        } else if (statusBefore === 'no_session') {
            addLog(`IGNORED   ${eventCode}  →  aucune session active`, 'warn');
            addTimeline(eventCode, 'ignored', data.capteur);
        } else {
            addLog(`INVALID   ${eventCode}  →  mauvais événement pour cette étape`, 'warn');
            addTimeline(eventCode, 'invalid', data.capteur);
        }

    } catch (e) {
        addLog(`NETWORK   ${eventCode}  →  fetch failed`, 'error');
        addTimeline(eventCode, 'error', '—');
    } finally {
        if (btn && !demoRunning) { btn.disabled = false; btn.style.opacity = '1'; }
    }
}

// ── Auto Demo ─────────────────────────────────────────────────

const DEMO_SEQUENCE = [
    { event: 'BUTTON_PRESS',    delay: 1200, step: '1/4' },
    { event: 'DOOR_OPEN',       delay: 3500, step: '2/4' },
    { event: 'MOTION_DETECTED', delay: 6000, step: '3/4' },
    { event: 'BUTTON_DOUBLE_PRESS', delay: 8500, step: '4/4' },
];

async function runDemo() {
    if (demoRunning) return;

    // Auto-start session if none active
    const sr   = await fetch(`${BASE}/session_status.php`);
    const sd   = await sr.json();
    if (sd.status === 'no_session') {
        await startSession();
        await delay(600);
    }

    demoRunning = true;
    setDemoMode(true);
    addLog('AUTO-DEMO  Lecture automatique du scénario complet...', 'info');

    DEMO_SEQUENCE.forEach(({ event, delay: ms, step }) => {
        const t = setTimeout(async () => {
            setDemoStep(step, event);
            await simulateEvent(event);
        }, ms);
        demoTimers.push(t);
    });

    // End of demo
    const endTimer = setTimeout(() => {
        demoRunning = false;
        setDemoMode(false);
        setDemoStep('', '');
        addLog('AUTO-DEMO  Séquence terminée', 'ok');
    }, 10000);
    demoTimers.push(endTimer);
}

function cancelDemo() {
    demoTimers.forEach(clearTimeout);
    demoTimers  = [];
    demoRunning = false;
    setDemoMode(false);
    setDemoStep('', '');
    addLog('AUTO-DEMO  Annulé par l\'utilisateur', 'warn');
}

function setDemoMode(active) {
    const runBtn    = document.getElementById('demoBtnRun');
    const cancelBtn = document.getElementById('demoBtnCancel');
    const demoBar   = document.getElementById('demoBar');
    const evBtns    = document.querySelectorAll('.event-btn, .ev-small');

    runBtn.style.display    = active ? 'none'        : 'inline-flex';
    cancelBtn.style.display = active ? 'inline-flex' : 'none';
    demoBar.style.display   = active ? 'flex'        : 'none';

    evBtns.forEach(b => {
        b.disabled     = active;
        b.style.opacity = active ? '0.35' : '1';
    });
}

function setDemoStep(step, event) {
    const el = document.getElementById('demoStepLabel');
    if (!el) return;
    el.textContent = step ? `Step ${step} — ${event}` : '';
}

// ── Timeline ──────────────────────────────────────────────────

const TL_LABELS = {
    valid:   { text: '✓ VALID',    cls: 'tl-valid'   },
    invalid: { text: '✗ INVALID',  cls: 'tl-invalid' },
    win:     { text: '🏆 VICTOIRE', cls: 'tl-win'     },
    ignored: { text: '⊘ IGNORED',  cls: 'tl-ignored' },
    error:   { text: '! ERROR',    cls: 'tl-error'   },
};

function addTimeline(eventCode, result, capteur) {
    const time = new Date().toLocaleTimeString('fr-FR', { hour12: false });
    tlEvents.unshift({ time, eventCode, result, capteur });
    if (tlEvents.length > MAX_TIMELINE) tlEvents.pop();
    renderTimeline();
}

function renderTimeline() {
    const el = document.getElementById('timelineBody');
    const countEl = document.getElementById('timelineCount');
    if (countEl) countEl.textContent = tlEvents.length ? `(${tlEvents.length})` : '';

    if (!tlEvents.length) {
        el.innerHTML = '<div class="tl-empty">Aucun événement. Lancez une session et simulez des actions.</div>';
        return;
    }

    el.innerHTML = tlEvents.map((e, i) => {
        const lbl = TL_LABELS[e.result] || { text: e.result, cls: '' };
        return `<div class="tl-row ${lbl.cls}" style="animation-delay:${i === 0 ? '0' : '0'}s">
            <span class="tl-time">${e.time}</span>
            <span class="tl-event">${e.eventCode}</span>
            <span class="tl-capteur">${e.capteur}</span>
            <span class="tl-result">${lbl.text}</span>
        </div>`;
    }).join('');
}

function clearTimeline() {
    tlEvents.length = 0;
    renderTimeline();
}

// ── Session control ────────────────────────────────────────────

async function startSession() {
    const nomJoueur  = document.getElementById('nomJoueur').value.trim() || 'Debug Team';
    const idScenario = document.getElementById('idScenario').value || '1';

    const fd = new FormData();
    fd.append('id_scenario', idScenario);
    fd.append('nom_joueur',  nomJoueur);

    try {
        const res  = await fetch(`${BASE}/start_game.php`, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.status === 'ok') {
            addLog(`SESSION   Started → #${data.id_session}  joueur: ${nomJoueur}`, 'ok');
            clearTimeline();
            startTs = null;
        } else {
            addLog(`SESSION   Error → ${data.message}`, 'error');
        }
    } catch (e) {
        addLog('SESSION   Network error on start', 'error');
    }
}

async function resetSession() {
    if (demoRunning) cancelDemo();
    try {
        await fetch(`${BASE}/reset_game.php`);
        addLog('RESET     Session abandonnée', 'warn');
        clearTimeline();
        startTs = null;
        clearInterval(timerInt);
        timerInt = null;
        document.getElementById('timerDisplay').textContent = '00:00';
    } catch (e) {
        addLog('RESET     Network error', 'error');
    }
}

// ── Session status polling ─────────────────────────────────────

async function pollStatus() {
    try {
        const res  = await fetch(`${BASE}/session_status.php`);
        const data = await res.json();
        updateSessionUI(data);
    } catch (e) {
        setStatusPill('offline', 'Offline');
    }
}

function updateSessionUI(data) {
    const teamEl  = document.getElementById('sessionTeam');
    const scoreEl = document.getElementById('sessionScore');
    const errEl   = document.getElementById('sessionErrors');
    const etapeEl = document.getElementById('sessionEtape');
    const descEl  = document.getElementById('sessionDesc');
    const totalEl = document.getElementById('sessionTotal');
    const dotsEl  = document.getElementById('progressDots');

    if (data.status === 'no_session') {
        setStatusPill('idle', 'Aucune session');
        teamEl.textContent  = '—';
        scoreEl.textContent = '—';
        errEl.textContent   = '—';
        etapeEl.textContent = '—';
        descEl.textContent  = 'Lancez une session pour commencer.';
        totalEl.textContent = '';
        dotsEl.innerHTML    = '';
        return;
    }

    const statusMap = {
        en_cours:   ['running', 'En cours'],
        gagnee:     ['won',     'Victoire !'],
        perdue:     ['lost',    'Perdue'],
        abandonnee: ['reset',   'Abandonnée'],
        en_attente: ['idle',    'En attente'],
    };
    const [cls, label] = statusMap[data.status] || ['idle', data.status];
    setStatusPill(cls, label);

    teamEl.textContent  = data.joueur     || '—';
    scoreEl.textContent = data.score      ?? '—';
    errEl.textContent   = data.nb_erreurs ?? '—';
    totalEl.textContent = data.total_etapes ? `/ ${data.total_etapes}` : '';

    if (data.etape) {
        etapeEl.textContent = `${data.etape.numero}. ${data.etape.titre}`;
        descEl.textContent  = data.etape.description || '';
        renderDots(data.etape.numero, data.total_etapes);
    }

    // Timer
    if (data.elapsed_seconds > 0 && !startTs) {
        startTs = Date.now() - data.elapsed_seconds * 1000;
        if (!timerInt) {
            timerInt = setInterval(() => {
                const s = Math.floor((Date.now() - startTs) / 1000);
                document.getElementById('timerDisplay').textContent = fmt(s);
            }, 1000);
        }
    }

    if (data.status === 'gagnee' || data.status === 'abandonnee') {
        clearInterval(timerInt);
        timerInt = null;
    }
}

function setStatusPill(cls, label) {
    const el = document.getElementById('sessionStatus');
    el.className   = 'status-pill status-' + cls;
    el.textContent = label;
}

function renderDots(current, total) {
    const el = document.getElementById('progressDots');
    el.innerHTML = '';
    for (let i = 1; i <= total; i++) {
        const d = document.createElement('span');
        d.className = 'dot ' + (i < current ? 'done' : i === current ? 'current' : 'future');
        el.appendChild(d);
    }
}

// ── Console log ────────────────────────────────────────────────

function addLog(msg, type = 'ok') {
    const time = new Date().toLocaleTimeString('fr-FR', { hour12: false });
    logLines.unshift({ time, msg, type });
    if (logLines.length > MAX_LOG) logLines.pop();
    renderLog();
}

function renderLog() {
    const el = document.getElementById('eventConsole');
    el.innerHTML = logLines.map(l =>
        `<div class="log-entry"><span class="log-time">${l.time}</span>`
      + `<span class="log-${l.type}">${escHtml(l.msg)}</span></div>`
    ).join('');
}

function clearLog() {
    logLines.length = 0;
    renderLog();
}

// ── Helpers ────────────────────────────────────────────────────

const delay = ms => new Promise(r => setTimeout(r, ms));

function fmt(s) {
    return String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');
}

function escHtml(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ── Init ───────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    renderTimeline();
    pollStatus();
    pollTimer = setInterval(pollStatus, 1000);
    addLog('PANEL     initialized — polling /api/session_status every 1s', 'info');
});
