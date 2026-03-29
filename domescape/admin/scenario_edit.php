<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$pdo = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    header('Location: /domescape/admin/scenarios.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM scenario WHERE id_scenario = ? LIMIT 1");
$stmt->execute([$id]);
$scenario = $stmt->fetch();

if (!$scenario) {
    header('Location: /domescape/admin/scenarios.php');
    exit;
}

$error   = '';
$success = '';

// --- Actions POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Mettre à jour scénario
    if ($action === 'update_scenario') {
        $nom   = trim($_POST['nom_scenario'] ?? '');
        $desc  = trim($_POST['description']  ?? '');
        $theme = trim($_POST['theme']        ?? '');
        $actif = isset($_POST['actif']) ? 1 : 0;

        if ($nom === '') {
            $error = 'Le nom est requis.';
        } else {
            $pdo->prepare("UPDATE scenario SET nom_scenario=?, description=?, theme=?, actif=? WHERE id_scenario=?")
                ->execute([$nom, $desc ?: null, $theme ?: null, $actif, $id]);
            $success = 'Scénario mis à jour.';
            $stmt = $pdo->prepare("SELECT * FROM scenario WHERE id_scenario = ? LIMIT 1");
            $stmt->execute([$id]);
            $scenario = $stmt->fetch();
        }
    }

    // Ajouter étape
    if ($action === 'add_etape') {
        $titre  = trim($_POST['titre_etape']       ?? '');
        $desc   = trim($_POST['description_etape'] ?? '');
        $msg_ok = trim($_POST['message_succes']    ?? '');
        $msg_ko = trim($_POST['message_echec']     ?? '');
        $indice = trim($_POST['indice']            ?? '');
        $points = max(0, (int)($_POST['points']    ?? 100));
        $finale = isset($_POST['finale']) ? 1 : 0;

        if ($titre === '') {
            $error = 'Le titre de l\'étape est requis.';
        } else {
            $maxNum = $pdo->prepare("SELECT COALESCE(MAX(numero_etape), 0) FROM etape WHERE id_scenario = ?");
            $maxNum->execute([$id]);
            $nextNum = (int)$maxNum->fetchColumn() + 1;

            $pdo->prepare("INSERT INTO etape (id_scenario, numero_etape, titre_etape, description_etape, message_succes, message_echec, indice, points, finale) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$id, $nextNum, $titre, $desc ?: null, $msg_ok ?: null, $msg_ko ?: null, $indice ?: null, $points, $finale]);
            $success = "Étape $nextNum « " . htmlspecialchars($titre, ENT_QUOTES, 'UTF-8') . " » ajoutée.";
        }
    }

    // Mettre à jour étape
    if ($action === 'update_etape') {
        $idEtape = (int)($_POST['id_etape']          ?? 0);
        $titre   = trim($_POST['titre_etape']        ?? '');
        $desc    = trim($_POST['description_etape']  ?? '');
        $msg_ok  = trim($_POST['message_succes']     ?? '');
        $msg_ko  = trim($_POST['message_echec']      ?? '');
        $indice  = trim($_POST['indice']             ?? '');
        $points  = max(0, (int)($_POST['points']     ?? 100));
        $finale  = isset($_POST['finale']) ? 1 : 0;

        if ($titre === '') {
            $error = 'Le titre est requis.';
        } else {
            $pdo->prepare("UPDATE etape SET titre_etape=?, description_etape=?, message_succes=?, message_echec=?, indice=?, points=?, finale=? WHERE id_etape=? AND id_scenario=?")
                ->execute([$titre, $desc ?: null, $msg_ok ?: null, $msg_ko ?: null, $indice ?: null, $points, $finale, $idEtape, $id]);
            $success = 'Étape mise à jour.';
            header("Location: /domescape/admin/scenario_edit.php?id=$id");
            exit;
        }
    }

    // Supprimer étape
    if ($action === 'delete_etape') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $pdo->prepare("DELETE FROM etape WHERE id_etape = ? AND id_scenario = ?")->execute([$idEtape, $id]);
        // Réordonner
        $all = $pdo->prepare("SELECT id_etape FROM etape WHERE id_scenario = ? ORDER BY numero_etape");
        $all->execute([$id]);
        $n = 1;
        foreach ($all->fetchAll() as $row) {
            $pdo->prepare("UPDATE etape SET numero_etape = ? WHERE id_etape = ?")->execute([$n++, $row['id_etape']]);
        }
        $success = 'Étape supprimée.';
    }
}

// Charger étapes
$etapesStmt = $pdo->prepare("SELECT * FROM etape WHERE id_scenario = ? ORDER BY numero_etape");
$etapesStmt->execute([$id]);
$etapes = $etapesStmt->fetchAll();

// Étape en cours d'édition
$editEtape = null;
if (isset($_GET['edit_etape'])) {
    $editId = (int)$_GET['edit_etape'];
    foreach ($etapes as $e) {
        if ((int)$e['id_etape'] === $editId) { $editEtape = $e; break; }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Éditer scénario — DomEscape Admin</title>
    <link href="/domescape/assets/vendor/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
        a { color: #00ff88; }

        .admin-wrap { max-width: 1000px; margin: 0 auto; padding: 40px 24px 80px; }

        .admin-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 32px; }
        .admin-header h1 { font-size: 1.1rem; font-weight: 700; margin: 0; color: #e0e0e0; }
        .admin-header p  { font-size: .72rem; color: #444; margin: 4px 0 0; }

        .section-label { font-size: .65rem; letter-spacing: .12em; color: #444; text-transform: uppercase; margin-bottom: 14px; margin-top: 36px; }

        .panel { background: #0f0f18; border: 1px solid #111; border-radius: 6px; padding: 24px; margin-bottom: 12px; }
        .panel-table { padding: 0; overflow: hidden; }

        .form-group { margin-bottom: 16px; }
        .form-group label { font-size: .68rem; color: #555; letter-spacing: .06em; text-transform: uppercase; display: block; margin-bottom: 6px; }
        .form-input {
            width: 100%; background: #080810; border: 1px solid #1a1a2e; color: #e0e0e0;
            font-family: 'Courier New', monospace; font-size: .82rem; padding: 8px 12px;
            border-radius: 4px; outline: none; transition: border-color .15s;
        }
        .form-input:focus { border-color: #00ff88; }
        textarea.form-input { resize: vertical; min-height: 70px; }

        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .btn-save { background: #00ff88; color: #080810; font-weight: 700; font-size: .8rem; padding: 9px 20px; border: none; border-radius: 4px; cursor: pointer; font-family: 'Courier New', monospace; transition: background .15s; }
        .btn-save:hover { background: #00cc6a; }
        .btn-outline { display: inline-flex; align-items: center; gap: 5px; padding: 8px 16px; border: 1px solid #1a1a2e; border-radius: 3px; font-size: .78rem; cursor: pointer; background: transparent; font-family: 'Courier New', monospace; color: #888; transition: all .15s; text-decoration: none; }
        .btn-outline:hover { border-color: #555; color: #e0e0e0; }
        .btn-action { display: inline-flex; align-items: center; gap: 5px; padding: 4px 10px; border: 1px solid; border-radius: 3px; font-size: .7rem; cursor: pointer; background: transparent; font-family: 'Courier New', monospace; transition: all .15s; text-decoration: none; white-space: nowrap; }
        .btn-edit-sm { color: #60a5fa; border-color: rgba(96,165,250,.3); }
        .btn-edit-sm:hover { background: rgba(96,165,250,.08); color: #60a5fa; }
        .btn-del-sm  { color: #ff4444; border-color: rgba(255,68,68,.2); }
        .btn-del-sm:hover { background: rgba(255,68,68,.07); }

        table { width: 100%; border-collapse: collapse; font-size: .78rem; }
        th { font-size: .6rem; letter-spacing: .08em; color: #444; text-transform: uppercase; padding: 9px 14px; text-align: left; font-weight: normal; border-bottom: 1px solid #0a0a14; }
        td { padding: 11px 14px; border-bottom: 1px solid #0a0a14; vertical-align: middle; }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover td { background: rgba(255,255,255,.02); }

        .finale-badge { font-size: .65rem; color: #00ff88; border: 1px solid rgba(0,255,136,.3); background: rgba(0,255,136,.06); padding: 2px 7px; border-radius: 3px; }
        .has-hint { color: #fbbf24; font-size: .7rem; display: inline-flex; align-items: center; gap: 4px; }

        .alert-error   { background: rgba(255,68,68,.07); border: 1px solid rgba(255,68,68,.25); color: #ff6666; padding: 10px 14px; border-radius: 4px; font-size: .8rem; margin-bottom: 20px; }
        .alert-success { background: rgba(0,255,136,.06); border: 1px solid rgba(0,255,136,.2); color: #00ff88; padding: 10px 14px; border-radius: 4px; font-size: .8rem; margin-bottom: 20px; }

        .cb-row { display: flex; align-items: center; gap: 8px; font-size: .82rem; }
        .cb-row input[type=checkbox] { accent-color: #00ff88; width: 15px; height: 15px; cursor: pointer; }
        .cb-row label { color: #888; cursor: pointer; }

        .edit-section { background: rgba(96,165,250,.04); border: 1px solid rgba(96,165,250,.2); border-radius: 6px; padding: 24px; margin-bottom: 12px; }
        .edit-section-title { font-size: .72rem; color: #60a5fa; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 20px; }

        @media (max-width: 700px) { .form-grid-2 { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <h1><?= htmlspecialchars($scenario['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Scénario #<?= (int)$id ?> — <?= count($etapes) ?> étape<?= count($etapes) != 1 ? 's' : '' ?></p>
        </div>
        <a href="/domescape/admin/scenarios.php" class="btn-outline">← Scénarios</a>
    </div>

    <?php if ($error): ?>
        <div class="alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert-success"><?= $success ?></div>
    <?php endif; ?>

    <!-- Métadonnées scénario -->
    <div class="section-label" style="margin-top:0;">Informations du scénario</div>
    <div class="panel">
        <form method="POST">
            <input type="hidden" name="action" value="update_scenario">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nom *</label>
                    <input type="text" name="nom_scenario" class="form-input"
                           value="<?= htmlspecialchars($scenario['nom_scenario'], ENT_QUOTES, 'UTF-8') ?>" required maxlength="150">
                </div>
                <div class="form-group">
                    <label>Thème</label>
                    <input type="text" name="theme" class="form-input"
                           value="<?= htmlspecialchars($scenario['theme'] ?? '', ENT_QUOTES, 'UTF-8') ?>" maxlength="100">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" class="form-input"><?= htmlspecialchars($scenario['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-top:8px;">
                <div class="cb-row">
                    <input type="checkbox" name="actif" id="actif" <?= $scenario['actif'] ? 'checked' : '' ?>>
                    <label for="actif">Scénario actif (visible dans la liste des parties)</label>
                </div>
                <button type="submit" class="btn-save">Enregistrer</button>
            </div>
        </form>
    </div>

    <!-- Liste des étapes -->
    <div class="section-label">Étapes (<?= count($etapes) ?>)</div>
    <div class="panel panel-table">
        <?php if (empty($etapes)): ?>
            <div style="padding:28px; text-align:center; color:#333; font-size:.8rem;">
                Aucune étape. Ajoutez-en une ci-dessous.
            </div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th style="width:36px;">#</th>
                    <th>Titre</th>
                    <th>Description</th>
                    <th>Points</th>
                    <th>Indice</th>
                    <th>Finale</th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($etapes as $e): ?>
                <tr>
                    <td style="color:#555;"><?= (int)$e['numero_etape'] ?></td>
                    <td style="color:#ccc; font-weight:500; max-width:200px;">
                        <?= htmlspecialchars($e['titre_etape'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td style="color:#555; font-size:.72rem; max-width:220px;">
                        <?php if ($e['description_etape']): ?>
                            <?= htmlspecialchars(mb_substr($e['description_etape'], 0, 60), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($e['description_etape']) > 60 ? '…' : '' ?>
                        <?php else: ?>
                            <span style="color:#333;">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#e0e0e0;"><?= (int)$e['points'] ?></td>
                    <td>
                        <?php if (!empty($e['indice'])): ?>
                            <span class="has-hint">
                                <i data-lucide="lightbulb" style="width:11px;height:11px;"></i> Oui
                            </span>
                        <?php else: ?>
                            <span style="color:#333;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($e['finale']): ?>
                            <span class="finale-badge">Finale</span>
                        <?php else: ?>
                            <span style="color:#333;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px; justify-content:flex-end;">
                            <a href="?id=<?= $id ?>&edit_etape=<?= (int)$e['id_etape'] ?>" class="btn-action btn-edit-sm">
                                <i data-lucide="pencil" style="width:10px;height:10px;"></i> Éditer
                            </a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette étape ?');">
                                <input type="hidden" name="action" value="delete_etape">
                                <input type="hidden" name="id_etape" value="<?= (int)$e['id_etape'] ?>">
                                <button type="submit" class="btn-action btn-del-sm">
                                    <i data-lucide="trash-2" style="width:10px;height:10px;"></i>
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

    <?php if ($editEtape): ?>
    <!-- Formulaire édition étape -->
    <div class="section-label">Modifier l'étape <?= (int)$editEtape['numero_etape'] ?></div>
    <div class="edit-section">
        <div class="edit-section-title">
            <i data-lucide="pencil" style="width:12px;height:12px;vertical-align:middle;margin-right:5px;"></i>
            Édition : <?= htmlspecialchars($editEtape['titre_etape'], ENT_QUOTES, 'UTF-8') ?>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="update_etape">
            <input type="hidden" name="id_etape" value="<?= (int)$editEtape['id_etape'] ?>">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Titre *</label>
                    <input type="text" name="titre_etape" class="form-input"
                           value="<?= htmlspecialchars($editEtape['titre_etape'], ENT_QUOTES, 'UTF-8') ?>" required maxlength="150">
                </div>
                <div class="form-group">
                    <label>Points</label>
                    <input type="number" name="points" class="form-input" min="0" max="9999"
                           value="<?= (int)$editEtape['points'] ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Description (affiché au joueur)</label>
                <textarea name="description_etape" class="form-input"><?= htmlspecialchars($editEtape['description_etape'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Message succès</label>
                    <input type="text" name="message_succes" class="form-input" maxlength="300"
                           value="<?= htmlspecialchars($editEtape['message_succes'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="form-group">
                    <label>Message échec</label>
                    <input type="text" name="message_echec" class="form-input" maxlength="300"
                           value="<?= htmlspecialchars($editEtape['message_echec'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Indice (envoyé sur demande du Game Master)</label>
                <textarea name="indice" class="form-input" style="min-height:55px;"><?= htmlspecialchars($editEtape['indice'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-top:8px;">
                <div class="cb-row">
                    <input type="checkbox" name="finale" id="finale_edit" <?= $editEtape['finale'] ? 'checked' : '' ?>>
                    <label for="finale_edit">Étape finale (victoire au succès)</label>
                </div>
                <div style="display:flex; gap:10px;">
                    <a href="?id=<?= $id ?>" class="btn-outline">Annuler</a>
                    <button type="submit" class="btn-save">Enregistrer l'étape</button>
                </div>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- Formulaire ajout étape -->
    <div class="section-label">Ajouter une étape</div>
    <div class="panel">
        <form method="POST">
            <input type="hidden" name="action" value="add_etape">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Titre *</label>
                    <input type="text" name="titre_etape" class="form-input"
                           placeholder="ex : Ouvrir le cadenas" maxlength="150" required>
                </div>
                <div class="form-group">
                    <label>Points</label>
                    <input type="number" name="points" class="form-input" value="100" min="0" max="9999">
                </div>
            </div>
            <div class="form-group">
                <label>Description (affiché au joueur)</label>
                <textarea name="description_etape" class="form-input" placeholder="Ce que le joueur doit faire…"></textarea>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Message succès</label>
                    <input type="text" name="message_succes" class="form-input" maxlength="300"
                           placeholder="Bravo ! Étape réussie.">
                </div>
                <div class="form-group">
                    <label>Message échec</label>
                    <input type="text" name="message_echec" class="form-input" maxlength="300"
                           placeholder="Mauvaise réponse, réessayez.">
                </div>
            </div>
            <div class="form-group">
                <label>Indice (envoyé sur demande du Game Master)</label>
                <textarea name="indice" class="form-input" style="min-height:55px;"
                          placeholder="Regardez derrière le tableau…"></textarea>
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-top:8px;">
                <div class="cb-row">
                    <input type="checkbox" name="finale" id="finale_add">
                    <label for="finale_add">Étape finale (victoire au succès)</label>
                </div>
                <button type="submit" class="btn-save">Ajouter l'étape →</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
