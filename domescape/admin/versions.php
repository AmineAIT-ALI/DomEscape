<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$pdo     = getDB();
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $idScenario = (int)($_POST['id_scenario']    ?? 0);
        $version    = trim($_POST['numero_version']  ?? '');
        $commentaire = trim($_POST['commentaire']    ?? '');

        if ($idScenario === 0 || $version === '') {
            $error = 'Le scénario et le numéro de version sont requis.';
        } else {
            $pdo->prepare("INSERT INTO scenario_version (id_scenario, numero_version, statut_version, commentaire) VALUES (?, ?, 'draft', ?)")
                ->execute([$idScenario, $version, $commentaire ?: null]);
            $success = "Version « " . htmlspecialchars($version, ENT_QUOTES, 'UTF-8') . " » créée.";
        }
    }

    if ($action === 'activate') {
        $id         = (int)($_POST['id_scenario_version'] ?? 0);
        $idScenario = (int)($_POST['id_scenario']         ?? 0);
        // Une seule version active par scénario
        $pdo->prepare("UPDATE scenario_version SET statut_version = 'archived' WHERE id_scenario = ? AND statut_version = 'active'")
            ->execute([$idScenario]);
        $pdo->prepare("UPDATE scenario_version SET statut_version = 'active' WHERE id_scenario_version = ?")
            ->execute([$id]);
        $success = 'Version activée.';
    }

    if ($action === 'archive') {
        $id = (int)($_POST['id_scenario_version'] ?? 0);
        $pdo->prepare("UPDATE scenario_version SET statut_version = 'archived' WHERE id_scenario_version = ?")
            ->execute([$id]);
        $success = 'Version archivée.';
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id_scenario_version'] ?? 0);
        // Vérifier qu'aucune session n'utilise cette version
        $used = $pdo->prepare("SELECT COUNT(*) FROM session WHERE id_scenario_version = ?");
        $used->execute([$id]);
        if ((int)$used->fetchColumn() > 0) {
            $error = 'Impossible de supprimer : des sessions utilisent cette version.';
        } else {
            $pdo->prepare("DELETE FROM scenario_version WHERE id_scenario_version = ?")->execute([$id]);
            $success = 'Version supprimée.';
        }
    }

    if ($action === 'clone') {
        $idSource = (int)($_POST['id_scenario_version'] ?? 0);

        // Récupérer la version source
        $stmtSrc = $pdo->prepare("SELECT * FROM scenario_version WHERE id_scenario_version = ?");
        $stmtSrc->execute([$idSource]);
        $source = $stmtSrc->fetch();

        if (!$source) {
            $error = 'Version source introuvable.';
        } else {
            $pdo->beginTransaction();
            try {
                // 1. Créer la nouvelle version (draft)
                $pdo->prepare("
                    INSERT INTO scenario_version (id_scenario, numero_version, statut_version, commentaire)
                    VALUES (?, ?, 'draft', ?)
                ")->execute([
                    $source['id_scenario'],
                    $source['numero_version'] . '-copy',
                    'Copie de ' . $source['numero_version'],
                ]);
                $idNouvelle = (int)$pdo->lastInsertId();

                // 2. Récupérer les étapes de la version source
                $etapes = $pdo->prepare("SELECT * FROM etape WHERE id_scenario_version = ?");
                $etapes->execute([$idSource]);

                foreach ($etapes->fetchAll() as $etape) {
                    // 3. Copier l'étape avec la nouvelle version
                    $pdo->prepare("
                        INSERT INTO etape
                            (id_scenario, id_scenario_version, numero_etape, titre_etape,
                             description_etape, message_succes, message_echec,
                             indice, points, finale)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([
                        $etape['id_scenario'],
                        $idNouvelle,
                        $etape['numero_etape'],
                        $etape['titre_etape'],
                        $etape['description_etape'],
                        $etape['message_succes'],
                        $etape['message_echec'],
                        $etape['indice'],
                        $etape['points'],
                        $etape['finale'],
                    ]);
                    $idNouvelleEtape = (int)$pdo->lastInsertId();
                    $idAncienneEtape = (int)$etape['id_etape'];

                    // 4. Copier etape_attend
                    $attend = $pdo->prepare("SELECT * FROM etape_attend WHERE id_etape = ?");
                    $attend->execute([$idAncienneEtape]);
                    foreach ($attend->fetchAll() as $a) {
                        $pdo->prepare("
                            INSERT IGNORE INTO etape_attend (id_etape, id_capteur, id_type_evenement, obligatoire)
                            VALUES (?, ?, ?, ?)
                        ")->execute([$idNouvelleEtape, $a['id_capteur'], $a['id_type_evenement'], $a['obligatoire']]);
                    }

                    // 5. Copier etape_declenche
                    $declenche = $pdo->prepare("SELECT * FROM etape_declenche WHERE id_etape = ?");
                    $declenche->execute([$idAncienneEtape]);
                    foreach ($declenche->fetchAll() as $d) {
                        $pdo->prepare("
                            INSERT INTO etape_declenche
                                (id_etape, id_actionneur, id_type_action, ordre_action, valeur_action, moment_declenchement)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $idNouvelleEtape,
                            $d['id_actionneur'],
                            $d['id_type_action'],
                            $d['ordre_action'],
                            $d['valeur_action'],
                            $d['moment_declenchement'],
                        ]);
                    }
                }

                $pdo->commit();
                $success = 'Version « ' . htmlspecialchars($source['numero_version'], ENT_QUOTES, 'UTF-8') . ' » clonée en draft.';

            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Erreur lors du clonage : ' . $e->getMessage();
            }
        }
    }
}

$scenarios = $pdo->query("SELECT id_scenario, nom_scenario FROM scenario WHERE actif = 1 ORDER BY nom_scenario")->fetchAll();

$versions = $pdo->query("
    SELECT sv.*, sc.nom_scenario
    FROM scenario_version sv
    JOIN scenario sc ON sc.id_scenario = sv.id_scenario
    ORDER BY sc.nom_scenario, sv.cree_le DESC
")->fetchAll();

$statutColors = [
    'active'   => '#00ff88',
    'draft'    => '#f0c040',
    'archived' => '#555',
];
$statutLabels = [
    'active'   => 'Active',
    'draft'    => 'Brouillon',
    'archived' => 'Archivée',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Versions — DomEscape Admin</title>
    <style>
        body { background: #080810; color: #e0e0e0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; min-height: 100vh; }
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
        .btn-action { font-size: .72rem; padding: 5px 11px; }
        .btn-activate { color: #00ff88; border-color: rgba(0,255,136,.3); }
        .btn-activate:hover { background: rgba(0,255,136,.08); }
        .btn-archive  { color: #888; border-color: #333; }
        .btn-archive:hover { background: rgba(255,255,255,.04); color: #ccc; }
        .btn-delete { color: #ff4444; border-color: rgba(255,68,68,.2); }
        .btn-delete:hover { background: rgba(255,68,68,.07); }
        .active-badge { display: inline-flex; align-items: center; gap: 5px; font-size: .68rem; }
        .dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
        .create-panel { background: #0a0a14; border: 1px solid #111; border-radius: 6px; padding: 24px; margin-bottom: 28px; }
        .create-panel h2 { font-size: .85rem; font-weight: 700; color: #ccc; margin: 0 0 20px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; margin-bottom: 16px; }
        .form-group label { font-size: .68rem; color: #555; letter-spacing: .06em; text-transform: uppercase; display: block; margin-bottom: 6px; }
        .form-group input, .form-group select { width: 100%; background: #080810; border: 1px solid #1a1a2e; color: #e0e0e0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: .82rem; padding: 8px 12px; border-radius: 4px; outline: none; transition: border-color .15s; }
        .form-group input:focus, .form-group select:focus { border-color: #00ff88; }
        .form-group select option { background: #080810; }
        .btn-create { font-size: .8rem; padding: 9px 20px; }
        @media (max-width: 700px) { .form-row { grid-template-columns: 1fr; } }
    </style>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <h1>Versions de scénario</h1>
            <p>Gérer les versions de scénarios (draft → active → archived)</p>
        </div>
        <a href="/domescape/admin/dashboard.php" style="font-size:.78rem; color:#444; text-decoration:none;">← Dashboard</a>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (empty($scenarios)): ?>
        <div class="alert-error">Aucun scénario actif. <a href="/domescape/admin/scenarios.php">Créez d'abord un scénario.</a></div>
    <?php else: ?>
    <div class="create-panel">
        <h2>Nouvelle version</h2>
        <form method="POST">
                <?= Csrf::field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-row">
                <div class="form-group">
                    <label>Scénario *</label>
                    <select name="id_scenario" required>
                        <option value="">— Sélectionner —</option>
                        <?php foreach ($scenarios as $sc): ?>
                            <option value="<?= (int)$sc['id_scenario'] ?>">
                                <?= htmlspecialchars($sc['nom_scenario'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Numéro de version *</label>
                    <input type="text" name="numero_version" placeholder="ex : v1.1, v2.0-beta" maxlength="20" required>
                </div>
                <div class="form-group">
                    <label>Commentaire</label>
                    <input type="text" name="commentaire" placeholder="Notes de version…" maxlength="500">
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-create">Créer la version →</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="section-label"><?= count($versions) ?> version<?= count($versions) != 1 ? 's' : '' ?></div>
    <div class="panel">
        <?php if (empty($versions)): ?>
            <div style="padding:32px; text-align:center; color:#333; font-size:.8rem;">Aucune version. Créez-en une ci-dessus.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Scénario</th>
                    <th>Version</th>
                    <th>Statut</th>
                    <th>Commentaire</th>
                    <th>Créé le</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($versions as $v): ?>
                <?php
                    $statut = $v['statut_version'];
                    $color  = $statutColors[$statut]  ?? '#888';
                    $label  = $statutLabels[$statut]  ?? $statut;
                ?>
                <tr>
                    <td style="color:#333;"><?= (int)$v['id_scenario_version'] ?></td>
                    <td style="color:#ccc;"><?= htmlspecialchars($v['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <span style="font-size:.75rem; color:#e0e0e0; background:#111; border:1px solid #222; padding:2px 8px; border-radius:3px; font-family:monospace;">
                            <?= htmlspecialchars($v['numero_version'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <span class="active-badge">
                            <span class="dot" style="background:<?= $color ?>;"></span>
                            <span style="color:<?= $color ?>;"><?= $label ?></span>
                        </span>
                    </td>
                    <td style="color:#555; font-size:.72rem;">
                        <?= $v['commentaire'] ? htmlspecialchars(mb_strimwidth($v['commentaire'], 0, 50, '…'), ENT_QUOTES, 'UTF-8') : '<span style="color:#333;">—</span>' ?>
                    </td>
                    <td style="color:#444; font-size:.72rem;"><?= htmlspecialchars(substr($v['cree_le'], 0, 10), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div style="display:flex; gap:8px; justify-content:flex-end;">
                            <?php if ($statut !== 'active'): ?>
                            <form method="POST" style="display:inline;">
                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="id_scenario_version" value="<?= (int)$v['id_scenario_version'] ?>">
                                <input type="hidden" name="id_scenario" value="<?= (int)$v['id_scenario'] ?>">
                                <button type="submit" class="btn btn-action btn-activate">Activer</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($statut === 'active'): ?>
                            <form method="POST" style="display:inline;">
                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="archive">
                                <input type="hidden" name="id_scenario_version" value="<?= (int)$v['id_scenario_version'] ?>">
                                <button type="submit" class="btn btn-action btn-archive">Archiver</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;">
                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="clone">
                                <input type="hidden" name="id_scenario_version" value="<?= (int)$v['id_scenario_version'] ?>">
                                <button type="submit" class="btn btn-action btn-archive" title="Dupliquer cette version en brouillon">
                                    <i data-lucide="copy" style="width:11px;height:11px;"></i> Dupliquer
                                </button>
                            </form>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette version ?');">
                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id_scenario_version" value="<?= (int)$v['id_scenario_version'] ?>">
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
