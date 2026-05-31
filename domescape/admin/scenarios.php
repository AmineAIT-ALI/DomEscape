<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireAdmin();

$pdo     = getDB();
$error   = '';
$success = '';

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nom      = trim($_POST['nom_scenario']       ?? '');
        $desc     = trim($_POST['description']        ?? '');
        $theme    = trim($_POST['theme']              ?? '');
        $dureeMax = $_POST['duree_max_secondes'] !== '' ? (int)$_POST['duree_max_secondes'] : null;

        if ($nom === '') {
            $error = 'Le nom du scénario est requis.';
        } else {
            $pdo->prepare("INSERT INTO scenario (nom_scenario, description, theme, actif, duree_max_secondes) VALUES (?, ?, ?, 1, ?)")
                ->execute([$nom, $desc ?: null, $theme ?: null, $dureeMax]);
            $success = "Scénario « " . htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') . " » créé.";
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id_scenario'] ?? 0);
        $pdo->prepare("UPDATE scenario SET actif = NOT actif WHERE id_scenario = ?")
            ->execute([$id]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id_scenario'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM session WHERE id_scenario = ? AND statut_session = 'en_cours'");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            $error = 'Impossible de supprimer : une session est en cours sur ce scénario.';
        } else {
            $pdo->prepare("DELETE FROM etape    WHERE id_scenario = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM scenario WHERE id_scenario = ?")->execute([$id]);
            $success = 'Scénario supprimé.';
        }
    }
}

// Charger les scénarios
$scenarios = $pdo->query("
    SELECT s.*, COUNT(e.id_etape) AS nb_etapes
    FROM scenario s
    LEFT JOIN etape e ON s.id_scenario = e.id_scenario
    GROUP BY s.id_scenario
    ORDER BY s.cree_le DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scénarios — DomEscape Admin</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <h1>Scénarios</h1>
            <p>Gérer les scénarios de jeu et leurs étapes</p>
        </div>
        <a href="/domescape/admin/dashboard.php" style="font-size:.78rem; color:#444; text-decoration:none;">← Tableau de bord</a>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <!-- Créer un scénario -->
    <div class="create-panel">
        <h2>Nouveau scénario</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom_scenario" placeholder="ex : DomEscape Lab 02" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label>Thème</label>
                    <input type="text" name="theme" placeholder="Cybersécurité" maxlength="100">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label>Durée max (s)</label>
                <input type="number" name="duree_max_secondes" placeholder="illimitée" min="60" max="86400">
            </div>
            <div class="form-group" style="margin-bottom:16px;">
                <label>Description</label>
                <input type="text" name="description" placeholder="Courte description…" maxlength="500">
            </div>
            <button type="submit" class="btn btn-primary btn-create">Créer le scénario →</button>
        </form>
    </div>

    <!-- Liste -->
    <div class="section-label"><?= count($scenarios) ?> scénario<?= count($scenarios) != 1 ? 's' : '' ?></div>
    <div class="panel">
        <?php if (empty($scenarios)): ?>
            <div style="padding:32px; text-align:center; color:#333; font-size:.8rem;">Aucun scénario. Créez-en un ci-dessus.</div>
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
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($scenarios as $s): ?>
                <tr>
                    <td style="color:#333;"><?= (int)$s['id_scenario'] ?></td>
                    <td style="color:#ccc; font-weight:500;"><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <?php if ($s['theme']): ?>
                            <span style="font-size:.68rem; color:#888; background:#111; border:1px solid #222; padding:2px 8px; border-radius:3px;">
                                <?= htmlspecialchars($s['theme'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#333;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:<?= $s['nb_etapes'] > 0 ? '#e0e0e0' : '#444' ?>;">
                        <?= (int)$s['nb_etapes'] ?> étape<?= $s['nb_etapes'] != 1 ? 's' : '' ?>
                    </td>
                    <td>
                        <?php if ($s['actif']): ?>
                            <span class="active-badge">
                                <span class="dot" style="background:#00ff88;"></span>
                                <span style="color:#00ff88;">Actif</span>
                            </span>
                        <?php else: ?>
                            <span class="active-badge">
                                <span class="dot" style="background:#555;"></span>
                                <span style="color:#555;">Inactif</span>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#444; font-size:.72rem;"><?= htmlspecialchars(substr($s['cree_le'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <a href="/domescape/admin/scenario_edit.php?id=<?= (int)$s['id_scenario'] ?>" class="btn btn-action btn-edit">
                                <i data-lucide="pencil" style="width:11px;height:11px;"></i> Éditer
                            </a>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id_scenario" value="<?= (int)$s['id_scenario'] ?>">
                                <button type="submit" class="btn btn-action <?= $s['actif'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                                    <?= $s['actif'] ? 'Désactiver' : 'Activer' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce scénario et toutes ses étapes ?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id_scenario" value="<?= (int)$s['id_scenario'] ?>">
                                <button type="submit" class="btn btn-action btn-delete">
                                    <i data-lucide="trash-2" style="width:11px;height:11px;"></i>
                                </button>
                            </form>
                        </div>
                    </td>
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
