<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_SUPERVISEUR);

$pdo = getDB();

// ── KPIs globaux ─────────────────────────────��────────────────
$kpi = $pdo->query("
    SELECT
        COUNT(*)                                              AS total,
        SUM(statut_session = 'gagnee')                        AS won,
        SUM(statut_session = 'perdue')                        AS lost,
        SUM(statut_session = 'abandonnee')                    AS abandoned,
        ROUND(AVG(CASE WHEN statut_session = 'gagnee' THEN duree_secondes END)) AS avg_time,
        MIN(CASE WHEN statut_session = 'gagnee' THEN duree_secondes END)        AS best_time,
        ROUND(AVG(CASE WHEN statut_session = 'gagnee' THEN score END))          AS avg_score,
        ROUND(AVG(nb_erreurs))                                AS avg_errors
    FROM session
    WHERE statut_session != 'en_attente'
")->fetch();

$total    = (int)$kpi['total'];
$won      = (int)$kpi['won'];
$winRate  = $total > 0 ? round($won / $total * 100) : 0;

// ── Stats par scénario ────────────────────────────────────────
$byScenario = $pdo->query("
    SELECT sc.nom_scenario,
           COUNT(*)                                AS total,
           SUM(s.statut_session = 'gagnee')        AS won,
           ROUND(AVG(CASE WHEN s.statut_session = 'gagnee' THEN s.duree_secondes END)) AS avg_time,
           MIN(CASE WHEN s.statut_session = 'gagnee' THEN s.duree_secondes END)        AS best_time,
           ROUND(AVG(s.score))                     AS avg_score
    FROM session s
    JOIN scenario sc ON s.id_scenario = sc.id_scenario
    WHERE s.statut_session != 'en_attente'
    GROUP BY sc.id_scenario, sc.nom_scenario
    ORDER BY total DESC
")->fetchAll();

// ── Erreurs par étape ─────────────────────────────────────────
$byEtape = $pdo->query("
    SELECT e.numero_etape, e.titre_etape,
           COUNT(*)                             AS total_events,
           SUM(es.evenement_attendu = 0
               AND es.valide = 1)               AS errors,
           COUNT(DISTINCT es.id_session)        AS sessions_reached
    FROM evenement_session es
    JOIN etape e ON es.id_etape = e.id_etape
    GROUP BY e.id_etape, e.numero_etape, e.titre_etape
    ORDER BY e.numero_etape ASC
")->fetchAll();

// ── 10 dernières sessions ─────────────────────────────────────
$recent = $pdo->query("
    SELECT s.id_session, s.statut_session, s.date_debut, s.score,
           s.nb_erreurs, s.duree_secondes,
           e.nom_equipe, sc.nom_scenario
    FROM session s
    JOIN equipe   e  ON s.id_equipe   = e.id_equipe
    JOIN scenario sc ON s.id_scenario = sc.id_scenario
    WHERE s.statut_session != 'en_attente'
    ORDER BY s.date_debut DESC
    LIMIT 10
")->fetchAll();

function fmtDuration(?int $sec): string {
    if (!$sec) return '—';
    return sprintf('%dm %02ds', intdiv($sec, 60), $sec % 60);
}
function statusColor(string $s): string {
    switch ($s) {
        case 'gagnee':     return '#00ff88';
        case 'perdue':     return '#ff4444';
        case 'abandonnee': return '#f0c040';
        default:           return '#555';
    }
}
function statusLabel(string $s): string {
    switch ($s) {
        case 'gagnee':     return 'Victoire';
        case 'perdue':     return 'Perdue';
        case 'abandonnee': return 'Abandon';
        case 'en_cours':   return 'En cours';
        default:           return $s;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statistiques — DomEscape</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { background: #080810; color: #e0e0e0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; min-height: 100vh; }
        a { color: #00ff88; text-decoration: none; }

        .page-wrap { max-width: 1100px; margin: 0 auto; padding: 32px 20px 80px; }

        /* ── Header ── */
        .page-header { margin-bottom: 32px; padding-bottom: 20px; border-bottom: 1px solid #111827; }
        .page-title  { font-size: 1.1rem; font-weight: 700; color: #e0e0e0; }
        .page-subtitle { font-size: .72rem; color: #444; margin-top: 4px; }

        /* ── Section ── */
        .section { margin-bottom: 32px; }
        .section-title {
            font-size: .65rem;
            color: #333;
            letter-spacing: .14em;
            text-transform: uppercase;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid #111827;
        }

        /* ── KPI grid ── */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
        }
        @media (max-width: 700px) { .kpi-grid { grid-template-columns: repeat(2, 1fr); } }

        .kpi-box {
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            padding: 18px 20px;
        }
        .kpi-label { font-size: .62rem; color: #333; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 8px; }
        .kpi-value { font-size: 1.6rem; font-weight: 700; color: #e0e0e0; line-height: 1; }
        .kpi-value.green  { color: #00ff88; }
        .kpi-value.red    { color: #ff4444; }
        .kpi-value.yellow { color: #f0c040; }
        .kpi-sub   { font-size: .68rem; color: #333; margin-top: 6px; }

        /* ── Win rate bar ── */
        .winrate-bar {
            height: 4px;
            background: #1f2937;
            border-radius: 2px;
            margin-top: 10px;
            overflow: hidden;
        }
        .winrate-fill {
            height: 100%;
            background: #00ff88;
            border-radius: 2px;
            transition: width .6s ease;
        }

        /* ── Table ── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .76rem;
        }
        .data-table th {
            text-align: left;
            font-size: .62rem;
            color: #333;
            letter-spacing: .1em;
            text-transform: uppercase;
            padding: 0 14px 10px;
            border-bottom: 1px solid #111827;
            font-weight: normal;
        }
        .data-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #0d0d0d;
            vertical-align: middle;
        }
        .data-table tr:last-child td { border-bottom: none; }
        .data-table tr:hover td { background: rgba(255,255,255,.01); }

        /* ── Error distribution ── */
        .etape-row {
            display: grid;
            grid-template-columns: 24px 1fr 80px 80px 80px;
            align-items: center;
            gap: 14px;
            padding: 12px 0;
            border-bottom: 1px solid #111827;
            font-size: .76rem;
        }
        .etape-row:last-child { border-bottom: none; }
        .etape-num {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: #1f2937;
            color: #555;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .7rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .etape-title { color: #aaa; }
        .etape-meta  { font-size: .68rem; color: #333; margin-top: 3px; }
        .etape-stat  { text-align: right; }
        .etape-stat-value { font-size: .9rem; font-weight: 700; }
        .etape-stat-label { font-size: .6rem; color: #333; text-transform: uppercase; letter-spacing: .08em; margin-top: 2px; }

        /* ── Mini bar ── */
        .mini-bar-wrap { display: flex; align-items: center; gap: 8px; }
        .mini-bar {
            flex: 1;
            height: 3px;
            background: #1f2937;
            border-radius: 2px;
            overflow: hidden;
        }
        .mini-bar-fill { height: 100%; background: #ff4444; border-radius: 2px; }

        /* ── Panel ── */
        .panel {
            background: #0d0d1a;
            border: 1px solid #1a1a2e;
            border-radius: 8px;
            padding: 20px;
        }

        /* ── Status dot ── */
        .status-dot {
            display: inline-block;
            width: 7px; height: 7px;
            border-radius: 50%;
            margin-right: 6px;
            vertical-align: middle;
        }
    </style>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">

    <div class="page-header">
        <div class="page-title">Statistiques</div>
        <div class="page-subtitle">Analyse globale des sessions · <?= $total ?> session<?= $total > 1 ? 's' : '' ?> enregistrée<?= $total > 1 ? 's' : '' ?></div>
    </div>

    <?php if ($total === 0): ?>
        <div style="text-align:center;color:#333;padding:80px 0;font-size:.85rem;">
            Aucune session terminée pour l'instant.
        </div>
    <?php else: ?>

    <!-- KPIs -->
    <div class="section">
        <div class="section-title">Vue d'ensemble</div>
        <div class="kpi-grid">
            <div class="kpi-box">
                <div class="kpi-label">Sessions jouées</div>
                <div class="kpi-value"><?= $total ?></div>
                <div class="kpi-sub"><?= $won ?> victoire<?= $won > 1 ? 's' : '' ?></div>
                <div class="winrate-bar">
                    <div class="winrate-fill" style="width:<?= $winRate ?>%;"></div>
                </div>
            </div>
            <div class="kpi-box">
                <div class="kpi-label">Taux de victoire</div>
                <div class="kpi-value green"><?= $winRate ?>%</div>
                <div class="kpi-sub"><?= $kpi['lost'] ?? 0 ?> perdue<?= ($kpi['lost'] ?? 0) > 1 ? 's' : '' ?> · <?= $kpi['abandoned'] ?? 0 ?> abandon<?= ($kpi['abandoned'] ?? 0) > 1 ? 's' : '' ?></div>
            </div>
            <div class="kpi-box">
                <div class="kpi-label">Meilleur temps</div>
                <div class="kpi-value green"><?= fmtDuration($kpi['best_time']) ?></div>
                <div class="kpi-sub">Moy. <?= fmtDuration($kpi['avg_time']) ?></div>
            </div>
            <div class="kpi-box">
                <div class="kpi-label">Score moyen</div>
                <div class="kpi-value"><?= $kpi['avg_score'] ?? '—' ?> <span style="font-size:.9rem;color:#555;">pts</span></div>
                <div class="kpi-sub">Moy. <?= round($kpi['avg_errors'] ?? 0, 1) ?> erreur<?= ($kpi['avg_errors'] ?? 0) > 1 ? 's' : '' ?>/session</div>
            </div>
        </div>
    </div>

    <!-- Erreurs par étape -->
    <?php if (!empty($byEtape)): ?>
    <div class="section">
        <div class="section-title">Difficulté par étape</div>
        <div class="panel">
            <?php
            $maxErrors = max(array_column($byEtape, 'errors'));
            foreach ($byEtape as $step):
                $errors   = (int)$step['errors'];
                $pct      = $maxErrors > 0 ? round($errors / $maxErrors * 100) : 0;
            ?>
            <div class="etape-row">
                <div class="etape-num"><?= $step['numero_etape'] ?></div>
                <div>
                    <div class="etape-title"><?= htmlspecialchars($step['titre_etape'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="etape-meta"><?= $step['sessions_reached'] ?> session<?= $step['sessions_reached'] > 1 ? 's' : '' ?> atteinte<?= $step['sessions_reached'] > 1 ? 's' : '' ?></div>
                </div>
                <div class="etape-stat">
                    <div class="etape-stat-value" style="color:#ff4444;"><?= $errors ?></div>
                    <div class="etape-stat-label">erreurs</div>
                </div>
                <div class="etape-stat">
                    <div class="etape-stat-value"><?= $step['total_events'] ?></div>
                    <div class="etape-stat-label">events</div>
                </div>
                <div>
                    <div class="mini-bar-wrap">
                        <div class="mini-bar">
                            <div class="mini-bar-fill" style="width:<?= $pct ?>%;"></div>
                        </div>
                        <span style="font-size:.65rem;color:#444;width:28px;text-align:right;"><?= $pct ?>%</span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Par scénario -->
    <?php if (!empty($byScenario)): ?>
    <div class="section">
        <div class="section-title">Par scénario</div>
        <div class="panel" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Scénario</th>
                        <th>Sessions</th>
                        <th>Victoires</th>
                        <th>Taux</th>
                        <th>Meilleur temps</th>
                        <th>Temps moy.</th>
                        <th>Score moy.</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($byScenario as $row):
                    $rate = $row['total'] > 0 ? round($row['won'] / $row['total'] * 100) : 0;
                ?>
                <tr>
                    <td style="color:#e0e0e0;"><?= htmlspecialchars($row['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#888;"><?= $row['total'] ?></td>
                    <td style="color:#00ff88;"><?= $row['won'] ?></td>
                    <td>
                        <span style="color:<?= $rate >= 50 ? '#00ff88' : '#ff4444' ?>;"><?= $rate ?>%</span>
                    </td>
                    <td style="color:#00ff88;"><?= fmtDuration($row['best_time']) ?></td>
                    <td style="color:#888;"><?= fmtDuration($row['avg_time']) ?></td>
                    <td style="color:#888;"><?= $row['avg_score'] ?? '—' ?> pts</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sessions récentes -->
    <div class="section">
        <div class="section-title">Sessions récentes</div>
        <div class="panel" style="padding:0;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Équipe</th>
                        <th>Scénario</th>
                        <th>Résultat</th>
                        <th>Score</th>
                        <th>Durée</th>
                        <th>Erreurs</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td style="color:#333;"><?= $r['id_session'] ?></td>
                    <td style="color:#aaa;"><?= htmlspecialchars($r['nom_equipe'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#666;"><?= htmlspecialchars($r['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="status-dot" style="background:<?= statusColor($r['statut_session']) ?>;"></span>
                        <span style="color:<?= statusColor($r['statut_session']) ?>;"><?= statusLabel($r['statut_session']) ?></span>
                    </td>
                    <td style="color:#e0e0e0;"><?= $r['score'] ?> pts</td>
                    <td style="color:<?= $r['statut_session'] === 'gagnee' ? '#00ff88' : '#888' ?>;"><?= fmtDuration($r['duree_secondes']) ?></td>
                    <td style="color:<?= $r['nb_erreurs'] > 0 ? '#ff4444' : '#555' ?>;"><?= $r['nb_erreurs'] ?></td>
                    <td style="color:#333;"><?= $r['date_debut'] ? substr($r['date_debut'], 0, 16) : '—' ?></td>
                    <td><a href="historique.php?id=<?= $r['id_session'] ?>" style="font-size:.68rem;color:#333;">détail →</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php endif; ?>

</div>
</body>
</html>
