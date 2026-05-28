<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireLogin();

$pdo = getDB();

$sessions = $pdo->query("
    SELECT se.*, sc.nom_scenario
    FROM session se
    JOIN scenario sc ON se.id_scenario = sc.id_scenario
    ORDER BY se.date_debut DESC
    LIMIT 30
")->fetchAll();

// Stats rapides
$statsQ = $pdo->query("
    SELECT
        COUNT(*)                                                  AS total,
        SUM(statut_session = 'gagnee')                           AS gagnees,
        ROUND(AVG(CASE WHEN duree_secondes > 0 THEN duree_secondes END)) AS avg_duree,
        MAX(score)                                               AS best_score
    FROM session
")->fetch();

$avgDuree = '—';
if ($statsQ['avg_duree']) {
    $s = (int)$statsQ['avg_duree'];
    $avgDuree = floor($s / 60) . 'm ' . ($s % 60) . 's';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historique — DomEscape</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <h1>Historique des sessions</h1>
            <p>Toutes les parties jouées sur la plateforme DomEscape</p>
        </div>
    </div>

    <!-- Stats rapides -->
    <div class="stats-grid" style="margin-bottom:32px;">
        <div class="stat-card">
            <div class="stat-card-value" style="color:#e0e0e0;"><?= (int)$statsQ['total'] ?></div>
            <div class="stat-card-label">Parties jouées</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value" style="color:#00ff88;"><?= (int)$statsQ['gagnees'] ?></div>
            <div class="stat-card-label">Victoires</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value yellow"><?= $avgDuree ?></div>
            <div class="stat-card-label">Durée moyenne</div>
        </div>
        <div class="stat-card">
            <div class="stat-card-value purple"><?= $statsQ['best_score'] ? (int)$statsQ['best_score'] : '—' ?></div>
            <div class="stat-card-label">Meilleur score</div>
        </div>
    </div>

    <!-- Tableau sessions -->
    <div class="section-label">Sessions</div>
    <div class="panel panel-table">
        <div class="panel-head">
            <h2>Toutes les parties</h2>
            <span style="font-size:.68rem;color:#444;"><?= count($sessions) ?> session<?= count($sessions) > 1 ? 's' : '' ?></span>
        </div>
        <?php if (empty($sessions)): ?>
            <div style="padding:40px;text-align:center;color:#333;font-size:.8rem;">Aucune partie jouée pour le moment.</div>
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
                    <th>Durée</th>
                    <th>Erreurs</th>
                    <th>Indices</th>
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
                $sbClass = $sbMap[$statut] ?? '';
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
                    <td style="color:#ccc;font-weight:500;"><?= htmlspecialchars($s['nom_equipe'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#888;"><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><span class="sb <?= $sbClass ?>"><span class="sb-dot"></span><?= $statLabel ?></span></td>
                    <td style="color:<?= (int)$s['score'] > 0 ? '#00ff88' : '#555' ?>;font-weight:<?= (int)$s['score'] > 0 ? '600' : 'normal' ?>;">
                        <?= (int)$s['score'] ?>
                    </td>
                    <td style="color:#555;"><?= $duree ?></td>
                    <td style="color:<?= (int)$s['nb_erreurs'] > 0 ? '#ff6666' : '#555' ?>;"><?= (int)$s['nb_erreurs'] ?></td>
                    <td style="color:#555;"><?= (int)$s['nb_indices'] ?></td>
                    <td style="color:#444;font-size:.72rem;"><?= htmlspecialchars($s['date_debut'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
