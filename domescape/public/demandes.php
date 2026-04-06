<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/RoleGuard.php';

RoleGuard::requireRole(ROLE_SUPERVISEUR);

$pdo = getDB();

// Demandes en attente
$pending = $pdo->query("
    SELECT d.id_demande, d.message_demande, d.demande_le,
           u.nom AS nom_utilisateur, u.email,
           se.id_session, se.statut_session,
           sc.nom_scenario,
           e.nom_equipe
    FROM demande_rejoindre_session d
    JOIN utilisateur u  ON d.id_utilisateur = u.id
    JOIN session     se ON d.id_session      = se.id_session
    JOIN scenario    sc ON se.id_scenario    = sc.id_scenario
    JOIN equipe      e  ON se.id_equipe      = e.id_equipe
    WHERE d.statut_demande = 'en_attente'
    ORDER BY d.demande_le ASC
")->fetchAll();

// Demandes récemment traitées (24h)
$recent = $pdo->query("
    SELECT d.id_demande, d.statut_demande, d.message_demande, d.demande_le, d.traitee_le,
           u.nom  AS nom_utilisateur,
           ut.nom AS nom_traiteur,
           se.id_session,
           sc.nom_scenario,
           e.nom_equipe
    FROM demande_rejoindre_session d
    JOIN utilisateur u   ON d.id_utilisateur = u.id
    JOIN session     se  ON d.id_session      = se.id_session
    JOIN scenario    sc  ON se.id_scenario    = sc.id_scenario
    JOIN equipe      e   ON se.id_equipe      = e.id_equipe
    LEFT JOIN utilisateur ut ON d.traitee_par = ut.id
    WHERE d.statut_demande IN ('acceptee', 'refusee')
      AND d.traitee_le >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY d.traitee_le DESC
    LIMIT 30
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Demandes — DomEscape</title>
    <style>
        body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
        a { color: #00ff88; }

        .page-wrap { max-width: 1000px; margin: 0 auto; padding: 36px 24px 80px; }

        /* Header */
        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 32px;
            padding-bottom: 20px;
            border-bottom: 1px solid #111827;
        }
        .page-title { font-size: 1.1rem; font-weight: 700; color: #e0e0e0; }
        .page-subtitle { font-size: .72rem; color: #444; margin-top: 4px; }

        /* Section label */
        .section-label {
            font-size: .65rem;
            letter-spacing: .12em;
            color: #444;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        /* Pending card */
        .demande-card {
            background: #0d0d1a;
            border: 1px solid rgba(0,255,136,.15);
            border-radius: 8px;
            padding: 18px 20px;
            margin-bottom: 10px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 16px;
            align-items: center;
        }
        .demande-card:hover { border-color: rgba(0,255,136,.3); }

        .demande-user { font-size: .88rem; font-weight: 600; color: #e0e0e0; margin-bottom: 4px; }
        .demande-email { font-size: .7rem; color: #444; margin-bottom: 8px; }
        .demande-meta { display: flex; flex-wrap: wrap; gap: 10px; }
        .demande-tag {
            font-size: .68rem;
            padding: 2px 8px;
            border-radius: 3px;
            border: 1px solid;
        }
        .tag-session { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
        .tag-scenario { color: #888; border-color: #222; background: rgba(255,255,255,.02); }
        .tag-equipe { color: #fbbf24; border-color: rgba(251,191,36,.3); background: rgba(251,191,36,.04); }
        .tag-date { color: #333; border-color: #1a1a2e; background: transparent; }

        .demande-message {
            font-size: .75rem;
            color: #555;
            font-style: italic;
            margin-top: 8px;
            padding: 6px 10px;
            border-left: 2px solid #1a1a2e;
        }

        /* Action buttons */
        .demande-actions { display: flex; flex-direction: column; gap: 8px; }
        .btn-accept {
            background: rgba(0,255,136,.1);
            color: #00ff88;
            border: 1px solid rgba(0,255,136,.35);
            padding: 7px 18px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
            white-space: nowrap;
        }
        .btn-accept:hover { background: rgba(0,255,136,.2); }
        .btn-accept:disabled { opacity: .4; cursor: default; }
        .btn-refuse {
            background: transparent;
            color: #666;
            border: 1px solid #222;
            padding: 7px 18px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
        }
        .btn-refuse:hover { color: #ff4444; border-color: rgba(255,68,68,.3); }
        .btn-refuse:disabled { opacity: .4; cursor: default; }

        /* Empty state */
        .empty {
            padding: 40px;
            text-align: center;
            color: #333;
            font-size: .82rem;
            background: #0d0d1a;
            border: 1px solid #111;
            border-radius: 8px;
        }
        .empty-icon { font-size: 1.8rem; opacity: .2; margin-bottom: 10px; }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            font-size: .78rem;
            padding: 10px 18px;
            border-radius: 5px;
            border: 1px solid;
            font-family: 'Courier New', monospace;
            opacity: 0;
            transform: translateY(8px);
            transition: opacity .2s, transform .2s;
            pointer-events: none;
            z-index: 999;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast-ok  { background: rgba(0,255,136,.08); color: #00ff88; border-color: rgba(0,255,136,.3); }
        .toast-err { background: rgba(255,68,68,.08); color: #ff4444; border-color: rgba(255,68,68,.3); }

        /* Recent table */
        .panel {
            background: #0d0d1a;
            border: 1px solid #111;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        table { width: 100%; border-collapse: collapse; font-size: .75rem; }
        th {
            font-size: .6rem; letter-spacing: .1em; color: #444;
            text-transform: uppercase; padding: 10px 14px;
            text-align: left; font-weight: normal;
            border-bottom: 1px solid #0a0a14;
        }
        td { padding: 10px 14px; border-bottom: 1px solid #0a0a14; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(255,255,255,.02); }

        .sb {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: .62rem; padding: 2px 7px;
            border-radius: 3px; border: 1px solid;
        }
        .sb-acceptee { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
        .sb-refusee  { color: #ff4444; border-color: rgba(255,68,68,.3);  background: rgba(255,68,68,.06); }

        /* Live badge */
        .live-badge {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: .68rem; color: #f0c040;
        }
        .live-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: #f0c040;
            animation: blink 1.4s infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

        @media (max-width: 640px) {
            .demande-card { grid-template-columns: 1fr; }
            .demande-actions { flex-direction: row; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">

    <div class="page-header">
        <div>
            <div class="page-title">Demandes de rejoindre</div>
            <div class="page-subtitle">Gérer les demandes d'accès aux sessions en cours</div>
        </div>
        <?php if (!empty($pending)): ?>
        <div class="live-badge">
            <span class="live-dot"></span>
            <?= count($pending) ?> en attente
        </div>
        <?php endif; ?>
    </div>

    <!-- Demandes en attente -->
    <div class="section-label">En attente de traitement (<?= count($pending) ?>)</div>

    <?php if (empty($pending)): ?>
    <div class="empty" style="margin-bottom:32px;">
        <div class="empty-icon">✓</div>
        Aucune demande en attente pour l'instant.
    </div>
    <?php else: ?>
    <div id="pendingList" style="margin-bottom:32px;">
        <?php foreach ($pending as $d): ?>
        <div class="demande-card" id="card-<?= (int)$d['id_demande'] ?>">
            <div>
                <div class="demande-user"><?= htmlspecialchars($d['nom_utilisateur'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="demande-email"><?= htmlspecialchars($d['email'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="demande-meta">
                    <span class="demande-tag tag-session">Session #<?= (int)$d['id_session'] ?></span>
                    <span class="demande-tag tag-scenario"><?= htmlspecialchars($d['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="demande-tag tag-equipe"><?= htmlspecialchars($d['nom_equipe'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="demande-tag tag-date"><?= htmlspecialchars(substr($d['demande_le'], 11, 5), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(substr($d['demande_le'], 0, 10), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if ($d['message_demande']): ?>
                <div class="demande-message">"<?= htmlspecialchars($d['message_demande'], ENT_QUOTES, 'UTF-8') ?>"</div>
                <?php endif; ?>
            </div>
            <div class="demande-actions">
                <button class="btn-accept" onclick="traiter(<?= (int)$d['id_demande'] ?>, 'accepter', this)">
                    Accepter →
                </button>
                <button class="btn-refuse" onclick="traiter(<?= (int)$d['id_demande'] ?>, 'refuser', this)">
                    Refuser
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Demandes récemment traitées -->
    <?php if (!empty($recent)): ?>
    <div class="section-label">Traitées dans les 24 dernières heures</div>
    <div class="panel">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Utilisateur</th>
                    <th>Scénario / Équipe</th>
                    <th>Message</th>
                    <th>Résultat</th>
                    <th>Traité par</th>
                    <th>À</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recent as $d): ?>
                <tr>
                    <td style="color:#ccc;"><?= htmlspecialchars($d['nom_utilisateur'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div style="color:#888;font-size:.72rem;"><?= htmlspecialchars($d['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div style="color:#555;font-size:.68rem;"><?= htmlspecialchars($d['nom_equipe'], ENT_QUOTES, 'UTF-8') ?></div>
                    </td>
                    <td style="color:#444;font-style:italic;">
                        <?= $d['message_demande']
                            ? '"' . htmlspecialchars(mb_substr($d['message_demande'], 0, 60), ENT_QUOTES, 'UTF-8') . (mb_strlen($d['message_demande']) > 60 ? '…' : '') . '"'
                            : '<span style="color:#2a2a2a;">—</span>' ?>
                    </td>
                    <td>
                        <span class="sb sb-<?= htmlspecialchars($d['statut_demande'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= $d['statut_demande'] === 'acceptee' ? '✓ Acceptée' : '✗ Refusée' ?>
                        </span>
                    </td>
                    <td style="color:#555;font-size:.72rem;">
                        <?= $d['nom_traiteur'] ? htmlspecialchars($d['nom_traiteur'], ENT_QUOTES, 'UTF-8') : '—' ?>
                    </td>
                    <td style="color:#333;font-size:.68rem;">
                        <?= htmlspecialchars(substr($d['traitee_le'], 11, 5), ENT_QUOTES, 'UTF-8') ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<div id="toast" class="toast"></div>

<script>
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className   = 'toast toast-' + type + ' show';
    setTimeout(() => { t.className = 'toast'; }, 3000);
}

function traiter(idDemande, action, btn) {
    const card    = document.getElementById('card-' + idDemande);
    const buttons = card.querySelectorAll('button');
    buttons.forEach(b => { b.disabled = true; });

    const fd = new FormData();
    fd.append('id_demande', idDemande);
    fd.append('action',     action);

    fetch('/domescape/api/handle_join_request.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.status === 'ok') {
                card.style.transition = 'opacity .3s';
                card.style.opacity    = '0';
                setTimeout(() => {
                    card.remove();
                    checkEmpty();
                }, 300);
                showToast(data.message, 'ok');
            } else {
                showToast(data.message || 'Erreur serveur.', 'err');
                buttons.forEach(b => { b.disabled = false; });
            }
        })
        .catch(() => {
            showToast('Impossible de joindre le serveur.', 'err');
            buttons.forEach(b => { b.disabled = false; });
        });
}

function checkEmpty() {
    const list = document.getElementById('pendingList');
    if (list && list.children.length === 0) {
        list.innerHTML = '<div class="empty"><div class="empty-icon">✓</div>Toutes les demandes ont été traitées.</div>';
    }
}

// Auto-refresh toutes les 15s pour voir les nouvelles demandes
setInterval(() => { location.reload(); }, 15000);
</script>
</body>
</html>
