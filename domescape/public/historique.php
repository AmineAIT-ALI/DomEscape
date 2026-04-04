<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_SUPERVISEUR);

$pdo = getDB();

// Liste des sessions (pour le sélecteur)
$listStmt = $pdo->query("
    SELECT s.id_session, s.statut_session, s.date_debut, s.score, s.nb_erreurs,
           j.nom_joueur, sc.nom_scenario
    FROM session s
    JOIN joueur   j  ON s.id_joueur   = j.id_joueur
    JOIN scenario sc ON s.id_scenario = sc.id_scenario
    ORDER BY s.date_debut DESC
    LIMIT 50
");
$sessions = $listStmt->fetchAll();

// Session sélectionnée
$idSession = isset($_GET['id']) ? (int)$_GET['id'] : ($sessions[0]['id_session'] ?? null);
$current   = null;
$events    = [];
$actions   = [];

if ($idSession) {
    $s = $pdo->prepare("
        SELECT s.*, j.nom_joueur, sc.nom_scenario
        FROM session s
        JOIN joueur   j  ON s.id_joueur   = j.id_joueur
        JOIN scenario sc ON s.id_scenario = sc.id_scenario
        WHERE s.id_session = ?
    ");
    $s->execute([$idSession]);
    $current = $s->fetch();

    // Événements de la session
    $evtStmt = $pdo->prepare("
        SELECT es.date_evenement, es.evenement_attendu, es.valide,
               c.nom_capteur, et.code_evenement, et.libelle_evenement,
               e.numero_etape, e.titre_etape
        FROM evenement_session es
        LEFT JOIN capteur        c  ON es.id_capteur        = c.id_capteur
        LEFT JOIN evenement_type et ON es.id_type_evenement = et.id_type_evenement
        LEFT JOIN etape          e  ON es.id_etape          = e.id_etape
        WHERE es.id_session = ?
        ORDER BY es.date_evenement ASC
    ");
    $evtStmt->execute([$idSession]);
    $events = $evtStmt->fetchAll();

    // Actions de la session
    $actStmt = $pdo->prepare("
        SELECT ae.date_execution, ae.valeur_action, ae.statut_execution,
               a.nom_actionneur, at.code_action, at.libelle_action,
               e.numero_etape
        FROM action_executee ae
        LEFT JOIN actionneur  a  ON ae.id_actionneur = a.id_actionneur
        LEFT JOIN action_type at ON ae.id_type_action = at.id_type_action
        LEFT JOIN etape       e  ON ae.id_etape       = e.id_etape
        WHERE ae.id_session = ?
        ORDER BY ae.date_execution ASC
    ");
    $actStmt->execute([$idSession]);
    $actions = $actStmt->fetchAll();
}

// Durée formatée
function fmtDuration(?int $sec): string {
    if (!$sec) return '—';
    return sprintf('%dm %02ds', intdiv($sec, 60), $sec % 60);
}

function statusLabel(string $s): string {
    return match($s) {
        'gagnee'    => 'Victoire',
        'perdue'    => 'Perdue',
        'abandonnee'=> 'Abandon',
        'en_cours'  => 'En cours',
        default     => $s,
    };
}

function statusColor(string $s): string {
    return match($s) {
        'gagnee'     => '#00ff88',
        'perdue'     => '#ff4444',
        'abandonnee' => '#f0c040',
        'en_cours'   => '#60a5fa',
        default      => '#555',
    };
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historique — DomEscape</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
        a { color: #00ff88; text-decoration: none; }

        .page-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 80px; }

        /* ── Header ── */
        .page-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid #111827;
        }
        .page-title { font-size: 1.1rem; font-weight: 700; color: #e0e0e0; }
        .page-subtitle { font-size: .72rem; color: #444; margin-top: 4px; }

        /* ── Session selector ── */
        .session-select-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .session-select-label { font-size: .7rem; color: #444; }
        select {
            background: #0d0d1a;
            border: 1px solid #1f2937;
            color: #e0e0e0;
            font-family: 'Courier New', monospace;
            font-size: .75rem;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
        }
        select:focus { outline: none; border-color: #00ff88; }

        /* ── Session summary ── */
        .session-summary {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .summary-box {
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 6px;
            padding: 12px 18px;
            min-width: 120px;
        }
        .summary-label { font-size: .6rem; color: #333; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 5px; }
        .summary-value { font-size: 1rem; font-weight: 700; color: #e0e0e0; }

        /* ── Layout ── */
        .timeline-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 780px) { .timeline-layout { grid-template-columns: 1fr; } }

        /* ── Panel ── */
        .panel {
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            overflow: hidden;
        }
        .panel-head {
            padding: 14px 18px;
            border-bottom: 1px solid #111827;
            font-size: .68rem;
            color: #333;
            letter-spacing: .12em;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .panel-head span { color: #555; }
        .panel-body { padding: 0; }

        /* ── Event rows ── */
        .tl-row {
            display: grid;
            grid-template-columns: 64px 16px 1fr auto;
            align-items: start;
            gap: 10px;
            padding: 10px 18px;
            border-bottom: 1px solid #080810;
            font-size: .75rem;
            transition: background .1s;
        }
        .tl-row:hover { background: rgba(255,255,255,.02); }
        .tl-row:last-child { border-bottom: none; }

        .tl-time  { color: #333; font-size: .68rem; padding-top: 2px; }
        .tl-dot   { width: 8px; height: 8px; border-radius: 50%; margin-top: 4px; flex-shrink: 0; }
        .dot-ok   { background: #00ff88; box-shadow: 0 0 5px rgba(0,255,136,.4); }
        .dot-err  { background: #ff4444; }
        .dot-ign  { background: #1f2937; }

        .tl-body  { }
        .tl-code  { color: #aaa; margin-bottom: 2px; }
        .tl-meta  { font-size: .68rem; color: #444; }

        .tl-badge {
            font-size: .6rem;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 700;
            align-self: center;
            white-space: nowrap;
        }
        .badge-ok   { background: rgba(0,255,136,.08);  color: #00ff88; }
        .badge-err  { background: rgba(255,68,68,.08);  color: #ff4444; }
        .badge-ign  { background: rgba(255,255,255,.04); color: #444; }

        /* ── Action rows ── */
        .act-row {
            display: grid;
            grid-template-columns: 64px 1fr auto;
            align-items: start;
            gap: 10px;
            padding: 10px 18px;
            border-bottom: 1px solid #080810;
            font-size: .75rem;
            transition: background .1s;
        }
        .act-row:hover { background: rgba(255,255,255,.02); }
        .act-row:last-child { border-bottom: none; }
        .act-time   { color: #333; font-size: .68rem; padding-top: 2px; }
        .act-body   { }
        .act-code   { color: #888; margin-bottom: 2px; }
        .act-detail { font-size: .68rem; color: #444; }
        .act-val    { font-style: italic; color: #555; }
        .act-ok  { font-size: .6rem; padding: 2px 6px; border-radius: 3px; background: rgba(0,255,136,.08); color: #00ff88; align-self: center; }
        .act-err { font-size: .6px; padding: 2px 6px; border-radius: 3px; background: rgba(255,68,68,.08); color: #ff4444; align-self: center; }

        /* ── Empty ── */
        .empty { padding: 32px; text-align: center; color: #333; font-size: .8rem; }

        /* ── Step separator ── */
        .step-sep {
            padding: 6px 18px;
            font-size: .62rem;
            color: #1f2937;
            letter-spacing: .1em;
            text-transform: uppercase;
            background: rgba(0,255,136,.02);
            border-bottom: 1px solid #080810;
            border-top: 1px solid #080810;
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">

    <div class="page-header">
        <div>
            <div class="page-title">Historique des sessions</div>
            <div class="page-subtitle">Événements capteurs et actions physiques par session</div>
        </div>
        <?php if (!empty($sessions)): ?>
        <div class="session-select-wrap">
            <span class="session-select-label">Session :</span>
            <select onchange="location.href='?id='+this.value">
                <?php foreach ($sessions as $sess): ?>
                    <option value="<?= $sess['id_session'] ?>"
                        <?= $sess['id_session'] == $idSession ? 'selected' : '' ?>>
                        #<?= $sess['id_session'] ?>
                        — <?= htmlspecialchars($sess['nom_joueur'], ENT_QUOTES, 'UTF-8') ?>
                        (<?= statusLabel($sess['statut_session']) ?>)
                        <?= $sess['date_debut'] ? '· ' . substr($sess['date_debut'], 0, 16) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!$current): ?>
        <div class="empty">Aucune session enregistrée.</div>
    <?php else: ?>

    <!-- Résumé session -->
    <div class="session-summary">
        <div class="summary-box">
            <div class="summary-label">Équipe</div>
            <div class="summary-value" style="font-size:.85rem;">
                <?= htmlspecialchars($current['nom_joueur'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Scénario</div>
            <div class="summary-value" style="font-size:.85rem;">
                <?= htmlspecialchars($current['nom_scenario'], ENT_QUOTES, 'UTF-8') ?>
            </div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Résultat</div>
            <div class="summary-value" style="color:<?= statusColor($current['statut_session']) ?>;">
                <?= statusLabel($current['statut_session']) ?>
            </div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Score</div>
            <div class="summary-value"><?= $current['score'] ?> pts</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Durée</div>
            <div class="summary-value"><?= fmtDuration($current['duree_secondes']) ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Erreurs</div>
            <div class="summary-value" style="color:#ff4444;"><?= $current['nb_erreurs'] ?></div>
        </div>
    </div>

    <!-- Timeline -->
    <div class="timeline-layout">

        <!-- Événements capteurs -->
        <div class="panel">
            <div class="panel-head">
                Événements capteurs
                <span><?= count($events) ?> entrées</span>
            </div>
            <div class="panel-body">
                <?php if (empty($events)): ?>
                    <div class="empty">Aucun événement.</div>
                <?php else:
                    $lastEtape = null;
                    foreach ($events as $e):
                        $etapeNum = $e['numero_etape'];
                        if ($etapeNum !== $lastEtape):
                            $lastEtape = $etapeNum;
                ?>
                    <div class="step-sep">
                        Étape <?= $etapeNum ?? '?' ?>
                        <?= $e['titre_etape'] ? '— ' . htmlspecialchars($e['titre_etape'], ENT_QUOTES, 'UTF-8') : '' ?>
                    </div>
                <?php endif;
                    $valide  = (bool)$e['valide'];
                    $attendu = (bool)$e['evenement_attendu'];
                    $dotCls  = $valide ? ($attendu ? 'dot-ok' : 'dot-ign') : 'dot-err';
                    $badgeCls = $valide ? ($attendu ? 'badge-ok' : 'badge-ign') : 'badge-err';
                    $badgeTxt = $valide ? ($attendu ? '✓ valide' : 'ignoré') : '✗ hors session';
                ?>
                <div class="tl-row">
                    <div class="tl-time"><?= substr($e['date_evenement'], 11, 8) ?></div>
                    <div class="tl-dot <?= $dotCls ?>"></div>
                    <div class="tl-body">
                        <div class="tl-code"><?= htmlspecialchars($e['code_evenement'] ?? '?', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="tl-meta"><?= htmlspecialchars($e['nom_capteur'] ?? '?', ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <span class="tl-badge <?= $badgeCls ?>"><?= $badgeTxt ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Actions physiques -->
        <div class="panel">
            <div class="panel-head">
                Actions physiques
                <span><?= count($actions) ?> entrées</span>
            </div>
            <div class="panel-body">
                <?php if (empty($actions)): ?>
                    <div class="empty">Aucune action exécutée.</div>
                <?php else:
                    $lastEtape = null;
                    foreach ($actions as $a):
                        $etapeNum = $a['numero_etape'];
                        if ($etapeNum !== $lastEtape):
                            $lastEtape = $etapeNum;
                ?>
                    <div class="step-sep">Étape <?= $etapeNum ?? '?' ?></div>
                <?php endif; ?>
                <div class="act-row">
                    <div class="act-time"><?= substr($a['date_execution'], 11, 8) ?></div>
                    <div class="act-body">
                        <div class="act-code"><?= htmlspecialchars($a['code_action'] ?? '?', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="act-detail">
                            <?= htmlspecialchars($a['nom_actionneur'] ?? '?', ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($a['valeur_action']): ?>
                                — <span class="act-val">"<?= htmlspecialchars($a['valeur_action'], ENT_QUOTES, 'UTF-8') ?>"</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if ($a['statut_execution'] === 'ok'): ?>
                        <span class="act-ok">ok</span>
                    <?php else: ?>
                        <span class="act-err"><?= htmlspecialchars($a['statut_execution'], ENT_QUOTES, 'UTF-8') ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

    </div>
    <?php endif; ?>

</div>
</body>
</html>
