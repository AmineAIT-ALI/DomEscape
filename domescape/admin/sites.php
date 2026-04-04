<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$pdo     = getDB();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $nom      = trim($_POST['nom_site']    ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $adresse  = trim($_POST['adresse']     ?? '');
        if ($nom === '') {
            $error = 'Le nom du site est requis.';
        } else {
            $pdo->prepare("INSERT INTO site (nom_site, description, adresse, actif) VALUES (?, ?, ?, 1)")
                ->execute([$nom, $desc ?: null, $adresse ?: null]);
            $success = "Site « " . htmlspecialchars($nom, ENT_QUOTES, 'UTF-8') . " » créé.";
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id_site'] ?? 0);
        $pdo->prepare("UPDATE site SET actif = NOT actif WHERE id_site = ?")->execute([$id]);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id_site'] ?? 0);
        $used = $pdo->prepare("SELECT COUNT(*) FROM salle WHERE id_site = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            $error = 'Impossible de supprimer : des salles sont rattachées à ce site.';
        } else {
            $pdo->prepare("DELETE FROM site WHERE id_site = ?")->execute([$id]);
            $success = 'Site supprimé.';
        }
    }
}

$sites = $pdo->query("
    SELECT s.*, COUNT(sa.id_salle) AS nb_salles
    FROM site s
    LEFT JOIN salle sa ON sa.id_site = s.id_site
    GROUP BY s.id_site
    ORDER BY s.cree_le DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sites — DomEscape Admin</title>
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
        .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 16px; }
        .form-group label { font-size: .68rem; color: #555; letter-spacing: .06em; text-transform: uppercase; display: block; margin-bottom: 6px; }
        .form-group input { width: 100%; background: #080810; border: 1px solid #1a1a2e; color: #e0e0e0; font-family: 'Courier New', monospace; font-size: .82rem; padding: 8px 12px; border-radius: 4px; outline: none; transition: border-color .15s; }
        .form-group input:focus { border-color: #00ff88; }
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
            <h1>Sites</h1>
            <p>Gérer les sites physiques (bâtiments, centres)</p>
        </div>
        <a href="/domescape/admin/dashboard.php" style="font-size:.78rem; color:#444; text-decoration:none;">← Dashboard</a>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="create-panel">
        <h2>Nouveau site</h2>
        <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom_site" placeholder="ex : Campus IUT" maxlength="100" required>
                </div>
                <div class="form-group">
                    <label>Adresse</label>
                    <input type="text" name="adresse" placeholder="ex : 1 rue de la Paix, Paris" maxlength="255">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="description" placeholder="Courte description…" maxlength="500">
                </div>
            </div>
            <button type="submit" class="btn-create">Créer le site →</button>
        </form>
    </div>

    <div class="section-label"><?= count($sites) ?> site<?= count($sites) != 1 ? 's' : '' ?></div>
    <div class="panel">
        <?php if (empty($sites)): ?>
            <div style="padding:32px; text-align:center; color:#333; font-size:.8rem;">Aucun site. Créez-en un ci-dessus.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nom</th>
                    <th>Adresse</th>
                    <th>Salles</th>
                    <th>Statut</th>
                    <th>Créé le</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sites as $s): ?>
                <tr>
                    <td style="color:#333;"><?= (int)$s['id_site'] ?></td>
                    <td style="color:#ccc; font-weight:500;"><?= htmlspecialchars($s['nom_site'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td style="color:#666; font-size:.72rem;"><?= $s['adresse'] ? htmlspecialchars($s['adresse'], ENT_QUOTES, 'UTF-8') : '<span style="color:#333;">—</span>' ?></td>
                    <td style="color:<?= $s['nb_salles'] > 0 ? '#e0e0e0' : '#444' ?>;">
                        <a href="/domescape/admin/salles.php?id_site=<?= (int)$s['id_site'] ?>" style="color:inherit; text-decoration:none;">
                            <?= (int)$s['nb_salles'] ?> salle<?= $s['nb_salles'] != 1 ? 's' : '' ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($s['actif']): ?>
                            <span class="active-badge"><span class="dot" style="background:#00ff88;"></span><span style="color:#00ff88;">Actif</span></span>
                        <?php else: ?>
                            <span class="active-badge"><span class="dot" style="background:#555;"></span><span style="color:#555;">Inactif</span></span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#444; font-size:.72rem;"><?= htmlspecialchars(substr($s['cree_le'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id_site" value="<?= (int)$s['id_site'] ?>">
                                <button type="submit" class="btn-action <?= $s['actif'] ? 'btn-toggle-on' : 'btn-toggle-off' ?>">
                                    <?= $s['actif'] ? 'Désactiver' : 'Activer' ?>
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer ce site ?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id_site" value="<?= (int)$s['id_site'] ?>">
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
