<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$pdo = getDB();

// Platform stats
$statsQ = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM utilisateur WHERE actif = 1)       AS nb_users,
        (SELECT COUNT(*) FROM scenario WHERE actif = 1)          AS nb_scenarios,
        (SELECT COUNT(*) FROM session WHERE DATE(date_debut) = CURDATE()) AS sessions_today,
        (SELECT COUNT(*) FROM session)                           AS sessions_total,
        (SELECT COUNT(*) FROM session WHERE statut_session = 'en_cours') AS sessions_active,
        (SELECT COUNT(*) FROM session WHERE statut_session = 'gagnee')   AS sessions_won
")->fetch();

// Scénarios configurés avec count d'étapes
$scenarios = $pdo->query("
    SELECT s.*, COUNT(e.id_etape) AS nb_etapes
    FROM scenario s
    LEFT JOIN etape e ON s.id_scenario = e.id_scenario
    GROUP BY s.id_scenario
    ORDER BY s.cree_le DESC
")->fetchAll();

// Dernières sessions
$sessions = $pdo->query("
    SELECT se.*, j.nom_joueur, sc.nom_scenario
    FROM session se
    JOIN joueur   j  ON se.id_joueur   = j.id_joueur
    JOIN scenario sc ON se.id_scenario = sc.id_scenario
    ORDER BY se.date_debut DESC
    LIMIT 12
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration — DomEscape</title>
    <link href="/domescape/assets/vendor/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
        a { color: #00ff88; }

        .admin-wrap { max-width: 1200px; margin: 0 auto; padding: 40px 24px 80px; }

        /* Header */
        .admin-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        .admin-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; color: #e0e0e0; }
        .admin-header p  { font-size: .72rem; color: #444; margin: 4px 0 0; }

        /* Stats grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-bottom: 36px;
        }
        .stat-card {
            background: #0f0f18;
            border: 1px solid #111;
            border-radius: 6px;
            padding: 18px 16px;
        }
        .stat-card-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #00ff88;
            margin-bottom: 4px;
            line-height: 1;
        }
        .stat-card-value.blue   { color: #60a5fa; }
        .stat-card-value.purple { color: #a78bfa; }
        .stat-card-value.yellow { color: #fbbf24; }
        .stat-card-label {
            font-size: .62rem;
            color: #444;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        /* Quick actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 36px;
        }
        .qa-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 16px;
            border: 1px solid #1a1a2e;
            border-radius: 4px;
            font-size: .78rem;
            color: #888;
            text-decoration: none;
            transition: border-color .15s, color .15s;
            font-family: 'Courier New', monospace;
        }
        .qa-btn:hover { border-color: #00ff88; color: #00ff88; }
        .qa-btn-icon { opacity: .6; }

        /* Sections */
        .section-label {
            font-size: .65rem;
            letter-spacing: .12em;
            color: #444;
            text-transform: uppercase;
            margin-bottom: 14px;
        }

        /* Panel */
        .panel {
            background: #0f0f18;
            border: 1px solid #111;
            border-radius: 6px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .panel-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid #0a0a14;
        }
        .panel-head h2 { font-size: .82rem; font-weight: 700; margin: 0; color: #ccc; }

        /* Tables */
        table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        th {
            font-size: .62rem;
            letter-spacing: .1em;
            color: #444;
            text-transform: uppercase;
            padding: 11px 16px;
            text-align: left;
            font-weight: normal;
            border-bottom: 1px solid #0a0a14;
        }
        td { padding: 12px 16px; border-bottom: 1px solid #0a0a14; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(255,255,255,.02); }

        /* Status badges */
        .sb {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: .65rem;
            padding: 2px 8px;
            border-radius: 3px;
            border: 1px solid;
        }
        .sb-gagnee   { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
        .sb-perdue   { color: #ff6666; border-color: rgba(255,68,68,.3); background: rgba(255,68,68,.06); }
        .sb-en_cours { color: #fbbf24; border-color: rgba(251,191,36,.3); background: rgba(251,191,36,.06); }
        .sb-terminee { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
        .sb-other    { color: #888; border-color: #333; background: rgba(255,255,255,.02); }
        .sb-dot      { width: 4px; height: 4px; border-radius: 50%; background: currentColor; }

        /* Active indicator */
        .active-dot {
            display: inline-block;
            width: 6px; height: 6px;
            border-radius: 50%;
            margin-right: 5px;
            vertical-align: middle;
        }

        @media (max-width: 900px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <!-- Header -->
    <div class="admin-header">
        <div>
            <h1>Administration</h1>
            <p>Vue globale de la plateforme DomEscape</p>
        </div>
        <?php if ((int)$statsQ['sessions_active'] > 0): ?>
        <div style="display:flex;align-items:center;gap:8px;background:rgba(0,255,136,.05);border:1px solid rgba(0,255,136,.2);border-radius:4px;padding:8px 14px;">
            <span style="width:7px;height:7px;background:#00ff88;border-radius:50%;box-shadow:0 0 6px #00ff88;animation:pulse 1.5s infinite;display:inline-block;"></span>
            <span style="font-size:.75rem;color:#00ff88;"><?= (int)$statsQ['sessions_active'] ?> session<?= $statsQ['sessions_active'] > 1 ? 's' : '' ?> en cours</span>
        </div>
        <?php endif; ?>
    </div>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-value"><?= (int)$statsQ['nb_users'] ?></div>
            <div class="stat-card-label">Utilisateurs actifs</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value blue"><?= (int)$statsQ['nb_scenarios'] ?></div>
            <div class="stat-card-label">Scénarios actifs</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value yellow"><?= (int)$statsQ['sessions_today'] ?></div>
            <div class="stat-card-label">Sessions aujourd'hui</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#e0e0e0;"><?= (int)$statsQ['sessions_total'] ?></div>
            <div class="stat-card-label">Sessions totales</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#00ff88;"><?= (int)$statsQ['sessions_won'] ?></div>
            <div class="stat-card-label">Victoires</div>
        </div>
        <div class="stat-card">
            <?php
            $winRate = $statsQ['sessions_total'] > 0
                ? round(($statsQ['sessions_won'] / $statsQ['sessions_total']) * 100)
                : 0;
            ?>
            <div class="stat-card-value purple"><?= $winRate ?>%</div>
            <div class="stat-card-label">Taux de victoire</div>
        </div>
    </div>

    <!-- Quick actions -->
    <div class="section-label">Actions rapides</div>
    <div class="quick-actions">
        <a href="/domescape/admin/scenarios.php" class="qa-btn">
            <i data-lucide="layers" style="width:13px;height:13px;opacity:.6;"></i> Scénarios
        </a>
        <a href="/domescape/admin/versions.php" class="qa-btn">
            <i data-lucide="git-branch" style="width:13px;height:13px;opacity:.6;"></i> Versions
        </a>
        <a href="/domescape/admin/sites.php" class="qa-btn">
            <i data-lucide="map-pin" style="width:13px;height:13px;opacity:.6;"></i> Sites
        </a>
        <a href="/domescape/admin/salles.php" class="qa-btn">
            <i data-lucide="door-open" style="width:13px;height:13px;opacity:.6;"></i> Salles
        </a>
        <a href="/domescape/admin/utilisateurs.php" class="qa-btn">
            <i data-lucide="users" style="width:13px;height:13px;opacity:.6;"></i> Utilisateurs
        </a>
        <a href="/domescape/public/gamemaster.php" class="qa-btn">
            <i data-lucide="monitor" style="width:13px;height:13px;opacity:.6;"></i> Supervision
        </a>
        <a href="/domescape/api/debug_event.php" class="qa-btn" target="_blank">
            <i data-lucide="radio" style="width:13px;height:13px;opacity:.6;"></i> Z-Wave
        </a>
    </div>

    <!-- Scénarios -->
    <div class="section-label">Scénarios configurés</div>
    <div class="panel">
        <div class="panel-head">
            <h2>Scénarios</h2>
            <span style="font-size:.68rem;color:#444;"><?= count($scenarios) ?> scénario<?= count($scenarios) > 1 ? 's' : '' ?></span>
        </div>
        <?php if (empty($scenarios)): ?>
            <div style="padding:32px;text-align:center;color:#333;font-size:.8rem;">Aucun scénario configuré.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nom</th>
                    <th>Thème</th>
                    <th>Étapes</th>
                    <th>Statut</th>
                    <th>Créé le</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scenarios as $s): ?>
                <tr>
                    <td style="color:#333;"><?= (int)$s['id_scenario'] ?></td>
                    <td style="color:#ccc;font-weight:500;"><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($s['theme']): ?>
                            <span style="font-size:.68rem;color:#888;background:#111;border:1px solid #222;padding:2px 8px;border-radius:3px;">
                                <?= htmlspecialchars($s['theme'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#333;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:<?= $s['nb_etapes'] > 0 ? '#e0e0e0' : '#444' ?>;">
                        <?= (int)$s['nb_etapes'] ?> étape<?= $s['nb_etapes'] > 1 ? 's' : '' ?>
                    </td>
                    <td>
                        <?php if ($s['actif']): ?>
                            <span style="color:#00ff88;font-size:.68rem;">
                                <span class="active-dot" style="background:#00ff88;"></span>Actif
                            </span>
                        <?php else: ?>
                            <span style="color:#555;font-size:.68rem;">
                                <span class="active-dot" style="background:#555;"></span>Inactif
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#444;font-size:.72rem;"><?= htmlspecialchars(substr($s['cree_le'],0,10), ENT_QUOTES, 'UTF-8') ?></td>
                    <td><a href="/domescape/admin/scenario_edit.php?id=<?= (int)$s['id_scenario'] ?>" style="font-size:.7rem;color:#60a5fa;text-decoration:none;">Éditer →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Sessions -->
    <div class="section-label">Dernières sessions</div>
    <div class="panel">
        <div class="panel-head">
            <h2>Historique</h2>
            <a href="#" style="font-size:.68rem;color:#555;text-decoration:none;">12 dernières</a>
        </div>
        <?php if (empty($sessions)): ?>
            <div style="padding:32px;text-align:center;color:#333;font-size:.8rem;">Aucune partie jouée.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Joueur</th>
                    <th>Scénario</th>
                    <th>Statut</th>
                    <th>Score</th>
                    <th>Erreurs</th>
                    <th>Durée</th>
                    <th>Début</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $s):
                $statut = $s['statut_session'];
                $sbMap = [
                    'gagnee'   => 'sb-gagnee',
                    'perdue'   => 'sb-perdue',
                    'en_cours' => 'sb-en_cours',
                    'terminee' => 'sb-terminee',
                ];
                $sbClass = $sbMap[$statut] ?? 'sb-other';
                $statLabels = [
                    'gagnee'   => 'Victoire',
                    'perdue'   => 'Défaite',
                    'en_cours' => 'En cours',
                    'terminee' => 'Terminée',
                ];
                $statLabel = $statLabels[$statut] ?? ucfirst($statut);
                $duree = $s['duree_secondes']
                    ? floor($s['duree_secondes'] / 60) . 'm ' . ($s['duree_secondes'] % 60) . 's'
                    : '—';
            ?>
                <tr>
                    <td style="color:#333;"><?= (int)$s['id_session'] ?></td>
                    <td style="color:#ccc;"><?= htmlspecialchars($s['nom_joueur'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#888;"><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span class="sb <?= $sbClass ?>">
                            <span class="sb-dot"></span><?= $statLabel ?>
                        </span>
                    </td>
                    <td style="color:<?= (int)$s['score'] > 0 ? '#00ff88' : '#555' ?>; font-weight:<?= (int)$s['score'] > 0 ? '600' : 'normal' ?>;">
                        <?= (int)$s['score'] ?>
                    </td>
                    <td style="color:<?= (int)$s['nb_erreurs'] > 0 ? '#ff6666' : '#555' ?>;">
                        <?= (int)$s['nb_erreurs'] ?>
                    </td>
                    <td style="color:#555;"><?= $duree ?></td>
                    <td style="color:#444;font-size:.72rem;"><?= htmlspecialchars($s['date_debut'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<style>
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .3; }
}
</style>
<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
