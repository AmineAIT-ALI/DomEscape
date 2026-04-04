<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$pdo     = getDB();
$error   = '';
$success = '';

// Filtre optionnel par site
$filtreIdSite = (int)($_GET['id_site'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $idSite   = (int)($_POST['id_site']      ?? 0);
        $nom      = trim($_POST['nom_salle']      ?? '');
        $desc     = trim($_POST['description']    ?? '');
        $capacite = (int)($_POST['capacite']      ?? 0);

        if ($idSite === 0 || $nom === '') {
            $error = 'Le site et le nom de la salle sont requis.';
        } else {
            $pdo->prepare("INSERT INTO salle (id_site, nom_salle, description, capacite, actif) VALUES (?, ?, ?, ?, 1)")
                ->execute([$idSite, $nom, $desc ?: null, $capacite ?: null]);
            $success = "Salle « " . htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') . " » créée.";
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id_salle'] ?? 0);
        $pdo->prepare("UPDATE salle SET actif = NOT actif WHERE id_salle = ?")->execute([$id]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id_salle'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM salle_scenario WHERE id_salle = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            $error = 'Impossible de supprimer : des scénarios sont déployés dans cette salle.';
        } else {
            $pdo->prepare("DELETE FROM salle WHERE id_salle = ?")->execute([$id]);
            $success = 'Salle supprimée.';
        }
    }
}

// Liste des sites pour le select
$sites = $pdo->query("SELECT id_site, nom_site FROM site WHERE actif = 1 ORDER BY nom_site")->fetchAll();

// Liste des salles
$whereClause = $filtreIdSite > 0 ? 'WHERE sa.id_site = ' . $filtreIdSite : '';
$salles = $pdo->query("
    SELECT sa.*, si.nom_site,
           COUNT(ss.id_salle_scenario) AS nb_scenarios
    FROM salle sa
    JOIN site si ON si.id_site = sa.id_site
    LEFT JOIN salle_scenario ss ON ss.id_salle = sa.id_salle
    $whereClause
    GROUP BY sa.id_salle
    ORDER BY si.nom_site, sa.nom_salle
")->fetchAll();

$titreSite = '';
if ($filtreIdSite > 0) {
    $row = $pdo->prepare("SELECT nom_site FROM site WHERE id_site = ?");
    $row->execute([$filtreIdSite]);
    $titreSite = $row->fetchColumn() ?: '';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Salles — DomEscape Admin</title>
    <link href="/domescape/assets/vendor/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
        a { color: #00ff88; }
        .admin-wrap { max-width: 1100px; margin: 0 auto; padding: 40px 24px 80px; }
        .admin-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; }
        .admin-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; color: #e0e0e0; }
        .admin-header p  { font-size: .72rem; color: #444; margin: 4px 0 0; }
        .section-label { font-size: .65rem; letter-spacing: .12em; color: #444; text-transform: uppercase; margin-bottom: 14px; }
        .panel { background: #0f0f18; border: 1px solid #111; border-radius: 6px; margin-bottom: 24px; overflow: hidden; }
        table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        th { font-size: .62rem; letter-spacing: .1em; color: #444; text-transform: uppercase; padding: 11px 16px; text-align: left; font-weight: normal; border-bottom: 1px solid #0a0a14; }
        td { padding: 12px 16px; border-bottom: 1px solid #0a0a14; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(255,255,255,.02); }
        .btn-action { display: inline-flex; align-items: center; gap: 5px; padding: 5px 11px; border: 1px solid; border-radius: 3px; font-size: .72rem; cursor: pointer; background: transparent; font-family: 'Courier New', monospace; transition: all .15s; text-decoration: none; white-space: nowrap; }
        .btn-toggle-on  { color: #00ff88; border-color: rgba(0,255,136,.3); }
        .btn-toggle-on:hover  { background: rgba(0,255,136,.08); }
        .btn-toggle-off { color: #888; border-color: #333; }
        .btn-toggle-off:hover { background: rgba(255,255,255,.04); color: #ccc; }
        .btn-delete { color: #ff4444; border-color: rgba(255,68,68,.2); }
        .btn-delete:hover { background: rgba(255,68,68,.07); }
        .active-badge { display: inline-flex; align-items: center; gap: 5px; font-size: .68rem; }
        .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .create-panel { background: #0a0a14; border: 1px solid #111; border-radius: 6px; padding: 24px; margin-bottom: 28px; }
        .create-panel h2 { font-size: .85rem; font-weight: 700; color: #ccc; margin: 0 0 20px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 14px; margin-bottom: 16px; }
        .form-group label { font-size: .68rem; color: #555; letter-spacing: .06em; text-transform: uppercase; display: block; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; background: #080810; border: 1px solid #1a1a2e; color: #e0e0e0; font-family: 'Courier New', monospace; font-size: .82rem; padding: 8px 12px; border-radius: 4px; outline: none; transition: border-color .15s; }
        .form-group input:focus, .form-group select:focus { border-color: #00ff88; }
        .form-group select option { background: #080810; }
        .btn-create { background: #00ff88; color: #080810; font-weight: 700; font-size: .8rem; padding: 9px 20px; border: none; border-radius: 4px; cursor: pointer; font-family: 'Courier New', monospace; transition: background .15s; }
        .btn-create:hover { background: #00cc6a; }
        .alert-error   { background: rgba(255,68,68,.07); border: 1px solid rgba(255,68,68,.25); color: #ff6666; padding: 10px 14px; border-radius: 4px; font-size: .8rem; margin-bottom: 20px; }
        .alert-success { background: rgba(0,255,136,.06); border: 1px solid rgba(0,255,136,.2); color: #00ff88; padding: 10px 14px; border-radius: 4px; font-size: .8rem; margin-bottom: 20px; }
        @media (max-width: 700px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <h1>Salles<?= $titreSite ? ' — ' . htmlspecialchars($titreSite, ENT_QUOTES, 'UTF-8') : '' ?></h1>
            <p>Gérer les salles physiques rattachées aux sites</p>
        </div>
        <div style="display:flex; gap:16px; align-items:center;">
            <a href="/domescape/admin/sites.php" style="font-size:.78rem; color:#444; text-decoration:none;">Sites</a>
            <a href="/domescape/admin/dashboard.php" style="font-size:.78rem; color:#444; text-decoration:none;">← Dashboard</a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (empty($sites)): ?>
        <div class="alert-error">Aucun site actif. <a href="/domescape/admin/sites.php">Créez d'abord un site.</a></div>
    <?php else: ?>
    <div class="create-panel">
        <h2>Nouvelle salle</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group">
                    <label>Site *</label>
                    <select name="id_site" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($sites as $site): ?>
                            <option value="<?= (int)$site['id_site'] ?>"
                                <?= $filtreIdSite === (int)$site['id_site'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($site['nom_site'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom_salle" placeholder="ex : Salle A" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Capacité</label>
                    <input type="number" name="capacite" placeholder="ex : 6" min="1" max="50">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="Courte description…" maxlength="500">
                </div>
            </div>
            <button type="submit" class="btn-create">Créer la salle →</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="section-label"><?= count($salles) ?> salle<?= count($salles) != 1 ? 's' : '' ?></div>
    <div class="panel">
        <?php if (empty($salles)): ?>
            <div style="padding:32px; text-align:center; color:#333; font-size:.8rem;">Aucune salle. Créez-en une ci-dessus.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Salle</th>
                    <th>Site</th>
                    <th>Capacité</th>
                    <th>Scénarios</th>
                    <th>Statut</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($salles as $sa): ?>
                <tr>
                    <td style="color:#333;"><?= (int)$sa['id_salle'] ?></td>
                    <td style="color:#ccc; font-weight:500;"><?= htmlspecialchars($sa['nom_salle'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#666; font-size:.72rem;"><?= htmlspecialchars($sa['nom_site'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#666;"><?= $sa['capacite'] ? (int)$sa['capacite'] . ' pers.' : '<span style="color:#333;">—</span>' ?></td>
                    <td style="color:<?= $sa['nb_scenarios'] > 0 ? '#e0e0e0' : '#444' ?>;">
                        <?= (int)$sa['nb_scenarios'] ?> déploiement<?= $sa['nb_scenarios'] != 1 ? 's' : '' ?>
                    </td>
                    <td>
                        <?php if ($sa['actif']): ?>
                            <span class="active-badge"><span class="dot" style="background:#00ff88;"></span><span style="color:#00ff88;">Active</span></span>
                        <?php else: ?>
                            <span class="active-badge"><span class="dot" style="background:#555;"></span><span style="color:#555;">Inactive</span></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id_salle" value="<?= (int)$sa['id_salle'] ?>">
                                <button type="submit" class="btn-action <?= $sa['actif'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                                    <?= $sa['actif'] ? 'Désactiver' : 'Activer' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette salle ?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id_salle" value="<?= (int)$sa['id_salle'] ?>">
                                <button type="submit" class="btn-action btn-delete">
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
