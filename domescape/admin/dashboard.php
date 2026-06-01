<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireAdmin();

$pdo = getDB();

// Stats globales
$statsQ = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM utilisateur WHERE actif = 1)       AS nb_users,
        (SELECT COUNT(*) FROM scenario WHERE actif = 1)          AS nb_scenarios,
        (SELECT COUNT(*) FROM session WHERE DATE(date_debut) = CURDATE()) AS sessions_today,
        (SELECT COUNT(*) FROM session)                           AS sessions_total,
        (SELECT COUNT(*) FROM session WHERE statut_session = 'en_cours') AS sessions_active,
        (SELECT COUNT(*) FROM session WHERE statut_session = 'gagnee')   AS sessions_won,
        (SELECT ROUND(AVG(duree_secondes)) FROM session WHERE statut_session IN ('gagnee','perdue') AND duree_secondes > 0) AS avg_duree,
        (SELECT ROUND(AVG(score)) FROM session WHERE statut_session IN ('gagnee','perdue') AND score > 0) AS avg_score,
        (SELECT COUNT(*) FROM evenement_session) AS nb_evenements,
        (SELECT COUNT(*) FROM action_executee)   AS nb_actions
")->fetch();

// Durée moyenne formatée
$avgDuree = '—';
if ($statsQ['avg_duree']) {
    $s = (int)$statsQ['avg_duree'];
    $avgDuree = floor($s / 60) . 'm ' . ($s % 60) . 's';
}

// Dernière mesure télémétrie
$telemetry = $pdo->query("
    SELECT mc.temperature, mc.humidite, mc.date_mesure, c.nom_capteur
    FROM mesure_capteur mc
    JOIN capteur c ON mc.id_capteur = c.id_capteur
    ORDER BY mc.date_mesure DESC
    LIMIT 1
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
    SELECT se.*, sc.nom_scenario
    FROM session se
    JOIN scenario sc ON se.id_scenario = sc.id_scenario
    ORDER BY se.date_debut DESC
    LIMIT 12
")->fetchAll();

// Derniers événements (10 — plateforme entière)
$recentEvents = $pdo->query("
    SELECT es.date_evenement, es.evenement_attendu, c.nom_capteur, et.code_evenement, s.nom_equipe
    FROM evenement_session es
    LEFT JOIN capteur c        ON es.id_capteur        = c.id_capteur
    LEFT JOIN evenement_type et ON es.id_type_evenement = et.id_type_evenement
    LEFT JOIN session s         ON es.id_session        = s.id_session
    ORDER BY es.date_evenement DESC
    LIMIT 10
")->fetchAll();

// Dernières actions physiques (10 — plateforme entière)
$recentActions = $pdo->query("
    SELECT ae.date_execution, ae.valeur_action, ae.statut_execution,
           a.nom_actionneur, at.code_action, s.nom_equipe
    FROM action_executee ae
    LEFT JOIN actionneur  a  ON ae.id_actionneur = a.id_actionneur
    LEFT JOIN action_type at ON ae.id_type_action = at.id_type_action
    LEFT JOIN session s       ON ae.id_session    = s.id_session
    ORDER BY ae.date_execution DESC
    LIMIT 10
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Administration — DomEscape</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

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

    <div class="section-label">Sessions</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-value yellow"><?= (int)$statsQ['sessions_today'] ?></div>
            <div class="stat-card-label">Aujourd'hui</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#e0e0e0;"><?= (int)$statsQ['sessions_total'] ?></div>
            <div class="stat-card-label">Total sessions</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#00ff88;"><?= (int)$statsQ['sessions_won'] ?></div>
            <div class="stat-card-label">Victoires</div>
        </div>
        <div class="stat-card">
            <?php $winRate = $statsQ['sessions_total'] > 0 ? round(($statsQ['sessions_won'] / $statsQ['sessions_total']) * 100) : 0; ?>
            <div class="stat-card-value purple"><?= $winRate ?>%</div>
            <div class="stat-card-label">Taux de réussite</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value yellow"><?= $avgDuree ?></div>
            <div class="stat-card-label">Durée moyenne</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#00ff88;"><?= $statsQ['avg_score'] ? (int)$statsQ['avg_score'] : '—' ?></div>
            <div class="stat-card-label">Score moyen</div>
        </div>
    </div>

    <div class="section-label">Activité système</div>
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-card-value blue"><?= (int)$statsQ['nb_users'] ?></div>
            <div class="stat-card-label">Utilisateurs actifs</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value blue"><?= (int)$statsQ['nb_scenarios'] ?></div>
            <div class="stat-card-label">Scénarios actifs</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#e0e0e0;"><?= (int)$statsQ['nb_evenements'] ?></div>
            <div class="stat-card-label">Événements capteurs</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#e0e0e0;"><?= (int)$statsQ['nb_actions'] ?></div>
            <div class="stat-card-label">Actions physiques</div>
        </div>
        <?php if ($telemetry): ?>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#60a5fa;"><?= number_format((float)$telemetry['temperature'], 1) ?>°C</div>
            <div class="stat-card-label">Température labo</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#a78bfa;"><?= number_format((float)$telemetry['humidite'], 1) ?>%</div>
            <div class="stat-card-label">Humidité labo</div>
        </div>
        <?php else: ?>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#333;">—</div>
            <div class="stat-card-label">Température labo</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#333;">—</div>
            <div class="stat-card-label">Humidité labo</div>
        </div>
        <?php endif; ?>
    </div>

    <div class="section-label">Actions rapides</div>
    <div class="quick-actions">
        <a href="/domescape/admin/scenarios.php" class="btn btn-outline qa-btn">
            <i data-lucide="layers" style="width:13px;height:13px;opacity:.6;"></i> Scénarios
        </a>
        <a href="/domescape/public/gamemaster.php" class="btn btn-outline qa-btn">
            <i data-lucide="monitor" style="width:13px;height:13px;opacity:.6;"></i> Supervision
        </a>
    </div>

    <div class="section-label">Scénarios configurés</div>
    <div class="panel panel-table">
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

    <div class="section-label">Dernières sessions</div>
    <div class="panel panel-table">
        <div class="panel-head">
            <h2>Historique</h2>
            <a href="/domescape/public/mes-parties.php" style="font-size:.68rem;color:#60a5fa;text-decoration:none;">Voir tout →</a>
        </div>
        <?php if (empty($sessions)): ?>
            <div style="padding:32px;text-align:center;color:#333;font-size:.8rem;">Aucune partie jouée.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Équipe</th>
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
                    'gagnee'     => 'sb-gagnee',
                    'perdue'     => 'sb-perdue',
                    'en_cours'   => 'sb-en_cours',
                    'abandonnee' => 'sb-perdue',
                ];
                $sbClass = $sbMap[$statut] ?? 'sb-other';
                $statLabels = [
                    'gagnee'     => 'Victoire',
                    'perdue'     => 'Défaite',
                    'en_cours'   => 'En cours',
                    'abandonnee' => 'Abandonnée',
                ];
                $statLabel = $statLabels[$statut] ?? ucfirst($statut);
                $duree = $s['duree_secondes']
                    ? floor($s['duree_secondes'] / 60) . 'm ' . ($s['duree_secondes'] % 60) . 's'
                    : '—';
            ?>
                <tr>
                    <td style="color:#333;"><?= (int)$s['id_session'] ?></td>
                    <td style="color:#ccc;"><?= htmlspecialchars($s['nom_equipe'], ENT_QUOTES, 'UTF-8') ?></td>
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

    <div class="section-label">Derniers événements capteurs</div>
    <div class="panel panel-table">
        <div class="panel-head">
            <h2>Événements</h2>
            <span style="font-size:.68rem;color:#444;"><?= count($recentEvents) ?> derniers</span>
        </div>
        <?php if (empty($recentEvents)): ?>
            <div style="padding:32px;text-align:center;color:#333;font-size:.8rem;">Aucun événement enregistré.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Horodatage</th>
                    <th>Événement</th>
                    <th>Capteur</th>
                    <th>Équipe</th>
                    <th>Résultat</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentEvents as $e):
                $attendu = (bool)$e['evenement_attendu'];
            ?>
                <tr>
                    <td style="color:#444;font-size:.72rem;font-family:'SF Mono',monospace;"><?= htmlspecialchars(substr($e['date_evenement'],0,19), ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#cbd5e1;"><?= htmlspecialchars($e['code_evenement'] ?? '?', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#888;"><?= htmlspecialchars($e['nom_capteur'] ?? '?', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#555;"><?= htmlspecialchars($e['nom_equipe'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($attendu): ?>
                            <span style="color:#00ff88;font-size:.68rem;">valide</span>
                        <?php else: ?>
                            <span style="color:#555;font-size:.68rem;">ignoré</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="section-label">Dernières actions physiques</div>
    <div class="panel panel-table">
        <div class="panel-head">
            <h2>Actionneurs</h2>
            <span style="font-size:.68rem;color:#444;"><?= count($recentActions) ?> dernières</span>
        </div>
        <?php if (empty($recentActions)): ?>
            <div style="padding:32px;text-align:center;color:#333;font-size:.8rem;">Aucune action exécutée.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Horodatage</th>
                    <th>Action</th>
                    <th>Actionneur</th>
                    <th>Valeur</th>
                    <th>Équipe</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentActions as $a): ?>
                <tr>
                    <td style="color:#444;font-size:.72rem;font-family:'SF Mono',monospace;"><?= htmlspecialchars(substr($a['date_execution'],0,19), ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#cbd5e1;"><?= htmlspecialchars($a['code_action'] ?? '?', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#888;"><?= htmlspecialchars($a['nom_actionneur'] ?? '?', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#555;"><?= $a['valeur_action'] ? htmlspecialchars($a['valeur_action'], ENT_QUOTES, 'UTF-8') : '—' ?></td>
                    <td style="color:#555;"><?= htmlspecialchars($a['nom_equipe'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:<?= $a['statut_execution'] === 'ok' ? '#00ff88' : '#ff6666' ?>;font-size:.68rem;"><?= htmlspecialchars($a['statut_execution'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="section-label">Télémétrie laboratoire</div>
    <div class="panel">
        <div class="panel-title">Dernière mesure — <?= $telemetry ? htmlspecialchars($telemetry['nom_capteur'], ENT_QUOTES, 'UTF-8') : 'Capteur indisponible' ?></div>
        <?php if ($telemetry): ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
            <div class="stat-box">
                <div class="stat-label">Température</div>
                <div class="stat-value" style="color:#60a5fa;"><?= number_format((float)$telemetry['temperature'], 1) ?> °C</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Humidité</div>
                <div class="stat-value" style="color:#a78bfa;"><?= number_format((float)$telemetry['humidite'], 1) ?> %</div>
            </div>
            <div class="stat-box">
                <div class="stat-label">Relevé le</div>
                <div style="font-size:.78rem;color:#555;margin-top:6px;"><?= htmlspecialchars(substr($telemetry['date_mesure'],0,16), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>
        <?php else: ?>
            <div style="color:#333;font-size:.8rem;padding:8px 0;">Aucune mesure disponible. Le cron poll_telemetry.php s'exécute toutes les 5 minutes.</div>
        <?php endif; ?>
    </div>

</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
