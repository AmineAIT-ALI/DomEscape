<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/Csrf.php';
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
    Csrf::verify();
    $action = $_POST['action'] ?? '';

    // Mettre à jour scénario
    if ($action === 'update_scenario') {
        $nom         = trim($_POST['nom_scenario']        ?? '');
        $desc        = trim($_POST['description']         ?? '');
        $theme       = trim($_POST['theme']               ?? '');
        $actif       = isset($_POST['actif']) ? 1 : 0;
        $jouMin      = $_POST['nb_joueurs_min']      !== '' ? (int)$_POST['nb_joueurs_min']      : null;
        $jouMax      = $_POST['nb_joueurs_max']      !== '' ? (int)$_POST['nb_joueurs_max']      : null;
        $dureeMax    = $_POST['duree_max_secondes']  !== '' ? (int)$_POST['duree_max_secondes']  : null;

        if ($nom === '') {
            $error = 'Le nom est requis.';
        } else {
            $pdo->prepare("UPDATE scenario SET nom_scenario=?, description=?, theme=?, actif=?, nb_joueurs_min=?, nb_joueurs_max=?, duree_max_secondes=? WHERE id_scenario=?")
                ->execute([$nom, $desc ?: null, $theme ?: null, $actif, $jouMin, $jouMax, $dureeMax, $id]);
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

    // Enregistrer événements attendus
    if ($action === 'save_attend') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $chk = $pdo->prepare("SELECT id_etape FROM etape WHERE id_etape=? AND id_scenario=?");
        $chk->execute([$idEtape, $id]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM etape_attend WHERE id_etape=?")->execute([$idEtape]);
            $caps   = $_POST['attend_capteur']     ?? [];
            $evts   = $_POST['attend_event']       ?? [];
            $obligs = $_POST['attend_obligatoire'] ?? [];
            $ins = $pdo->prepare("INSERT IGNORE INTO etape_attend (id_etape,id_capteur,id_type_evenement,obligatoire) VALUES (?,?,?,?)");
            foreach ($caps as $i => $cap) {
                $cap  = (int)$cap;
                $evt  = (int)($evts[$i] ?? 0);
                $obli = (int)($obligs[$i] ?? 1);
                if ($cap > 0 && $evt > 0) $ins->execute([$idEtape, $cap, $evt, $obli]);
            }
            $success = 'Événements attendus enregistrés.';
            header("Location: /domescape/admin/scenario_edit.php?id=$id&edit_etape=$idEtape");
            exit;
        }
    }

    // Enregistrer actions déclenchées
    if ($action === 'save_declenche') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $chk = $pdo->prepare("SELECT id_etape FROM etape WHERE id_etape=? AND id_scenario=?");
        $chk->execute([$idEtape, $id]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM etape_declenche WHERE id_etape=?")->execute([$idEtape]);
            $acts    = $_POST['dec_actionneur'] ?? [];
            $types   = $_POST['dec_type']       ?? [];
            $moments = $_POST['dec_moment']     ?? [];
            $valeurs = $_POST['dec_valeur']     ?? [];
            $allowed = ['on_enter','on_success','on_failure','on_hint'];
            $ins = $pdo->prepare("INSERT INTO etape_declenche (id_etape,id_actionneur,id_type_action,ordre_action,valeur_action,moment_declenchement) VALUES (?,?,?,?,?,?)");
            $ordre = 1;
            foreach ($acts as $i => $act) {
                $act    = (int)$act;
                $type   = (int)($types[$i] ?? 0);
                $moment = in_array($moments[$i] ?? '', $allowed) ? $moments[$i] : 'on_success';
                $valeur = trim($valeurs[$i] ?? '') ?: null;
                if ($act > 0 && $type > 0) $ins->execute([$idEtape, $act, $type, $ordre++, $valeur, $moment]);
            }
            $success = 'Actions déclenchées enregistrées.';
            header("Location: /domescape/admin/scenario_edit.php?id=$id&edit_etape=$idEtape");
            exit;
        }
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

// Catalogues
$capteurs    = $pdo->query("SELECT * FROM capteur    WHERE actif=1 ORDER BY nom_capteur")->fetchAll();
$eventTypes  = $pdo->query("SELECT * FROM evenement_type ORDER BY libelle_evenement")->fetchAll();
$actionneurs = $pdo->query("SELECT * FROM actionneur WHERE actif=1 ORDER BY nom_actionneur")->fetchAll();
$actionTypes = $pdo->query("SELECT * FROM action_type ORDER BY libelle_action")->fetchAll();

// Données existantes pour l'étape en cours d'édition
$attendList    = [];
$declencheList = [];
if ($editEtape) {
    $s = $pdo->prepare(
        "SELECT ea.*, c.nom_capteur, et.libelle_evenement
         FROM etape_attend ea
         JOIN capteur c ON c.id_capteur = ea.id_capteur
         JOIN evenement_type et ON et.id_type_evenement = ea.id_type_evenement
         WHERE ea.id_etape = ?");
    $s->execute([$editEtape['id_etape']]);
    $attendList = $s->fetchAll();

    $s = $pdo->prepare(
        "SELECT ed.*, a.nom_actionneur, at.libelle_action
         FROM etape_declenche ed
         JOIN actionneur a ON a.id_actionneur = ed.id_actionneur
         JOIN action_type at ON at.id_type_action = ed.id_type_action
         WHERE ed.id_etape = ?
         ORDER BY ed.moment_declenchement, ed.ordre_action");
    $s->execute([$editEtape['id_etape']]);
    $declencheList = $s->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Éditer scénario — DomEscape Admin</title>
    <style>
        body { background: #080810; color: #e0e0e0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; min-height: 100vh; }
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
            font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; font-size: .82rem; padding: 8px 12px;
            border-radius: 4px; outline: none; transition: border-color .15s;
        }
        .form-input:focus { border-color: #00ff88; }
        textarea.form-input { resize: vertical; min-height: 70px; }

        .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .btn-save { font-size: .8rem; padding: 9px 20px; }
        .btn-outline { font-size: .78rem; padding: 8px 16px; }
        .btn-action { font-size: .7rem; padding: 4px 10px; }
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
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="admin-wrap">

    <div class="admin-header">
        <div>
            <h1><?= htmlspecialchars($scenario['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Scénario #<?= (int)$id ?> — <?= count($etapes) ?> étape<?= count($etapes) != 1 ? 's' : '' ?></p>
        </div>
        <a href="/domescape/admin/scenarios.php" class="btn btn-outline">← Scénarios</a>
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
                <?= Csrf::field() ?>
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
            <div class="form-grid-2" style="margin-top:4px;">
                <div class="form-group">
                    <label>Joueurs min</label>
                    <input type="number" name="nb_joueurs_min" class="form-input" min="1" max="99"
                           value="<?= $scenario['nb_joueurs_min'] !== null ? (int)$scenario['nb_joueurs_min'] : '' ?>"
                           placeholder="—">
                </div>
                <div class="form-group">
                    <label>Joueurs max</label>
                    <input type="number" name="nb_joueurs_max" class="form-input" min="1" max="99"
                           value="<?= $scenario['nb_joueurs_max'] !== null ? (int)$scenario['nb_joueurs_max'] : '' ?>"
                           placeholder="—">
                </div>
            </div>
            <div class="form-group">
                <label>Durée limite (secondes) — laisser vide = illimitée</label>
                <input type="number" name="duree_max_secondes" class="form-input" min="60" max="86400"
                       value="<?= $scenario['duree_max_secondes'] !== null ? (int)$scenario['duree_max_secondes'] : '' ?>"
                       placeholder="ex : 3600">
            </div>
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-top:8px;">
                <div class="cb-row">
                    <input type="checkbox" name="actif" id="actif" <?= $scenario['actif'] ? 'checked' : '' ?>>
                    <label for="actif">Scénario actif (visible dans la liste des parties)</label>
                </div>
                <button type="submit" class="btn btn-primary btn-save">Enregistrer</button>
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
                            <a href="?id=<?= $id ?>&edit_etape=<?= (int)$e['id_etape'] ?>" class="btn btn-action btn-edit-sm">
                                <i data-lucide="pencil" style="width:10px;height:10px;"></i> Éditer
                            </a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette étape ?');">
                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete_etape">
                                <input type="hidden" name="id_etape" value="<?= (int)$e['id_etape'] ?>">
                                <button type="submit" class="btn btn-action btn-del-sm">
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
                <?= Csrf::field() ?>
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
                    <a href="?id=<?= $id ?>" class="btn btn-outline">Annuler</a>
                    <button type="submit" class="btn btn-primary btn-save">Enregistrer l'étape</button>
                </div>
            </div>
        </form>
    </div>

    <?php
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
    $jsCapteurs    = json_encode(array_values(array_map(fn($c)  => ['v' => (int)$c['id_capteur'],        'l' => $c['nom_capteur'],       't' => $c['type_capteur']],    $capteurs)),    $flags);
    $jsEvents      = json_encode(array_values(array_map(fn($e)  => ['v' => (int)$e['id_type_evenement'], 'l' => $e['libelle_evenement'], 't' => $e['type_capteur']], $eventTypes)), $flags);
    $jsActionneurs = json_encode(array_values(array_map(fn($a)  => ['v' => (int)$a['id_actionneur'],     'l' => $a['nom_actionneur']],  $actionneurs)), $flags);
    $jsTypes       = json_encode(array_values(array_map(fn($at) => ['v' => (int)$at['id_type_action'],   'l' => $at['libelle_action']], $actionTypes)), $flags);
    ?>
    <script>
    const CAPTEURS    = <?= $jsCapteurs ?>;
    const EVENTS      = <?= $jsEvents ?>;
    const ACTIONNEURS = <?= $jsActionneurs ?>;
    const ACTION_TYPES = <?= $jsTypes ?>;
    </script>

    <!-- Événements attendus -->
    <div class="section-label" style="margin-top:20px;">Événement attendu pour valider l'étape</div>
    <div class="panel" style="padding:0;">
        <form method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_attend">
            <input type="hidden" name="id_etape" value="<?= (int)$editEtape['id_etape'] ?>">
            <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                <thead>
                    <tr>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Capteur</th>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Événement</th>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Obligatoire</th>
                        <th style="border-bottom:1px solid #0a0a14;width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="attend-body">
                <?php
                $attendRows = !empty($attendList) ? $attendList : [['id_capteur' => '', 'id_type_evenement' => '', 'obligatoire' => 1]];
                foreach ($attendRows as $a):
                ?>
                <tr class="attend-row">
                    <td style="padding:10px 14px;">
                        <select name="attend_capteur[]" class="form-input" style="padding:6px 10px;" onchange="filterEvents(this)">
                            <option value="">— capteur —</option>
                            <?php foreach ($capteurs as $c): ?>
                                <option value="<?= (int)$c['id_capteur'] ?>" <?= isset($a['id_capteur']) && $c['id_capteur'] == $a['id_capteur'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nom_capteur'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:10px 14px;">
                        <select name="attend_event[]" class="form-input" style="padding:6px 10px;">
                            <option value="">— événement —</option>
                            <?php foreach ($eventTypes as $et): ?>
                                <option value="<?= (int)$et['id_type_evenement'] ?>" <?= isset($a['id_type_evenement']) && $et['id_type_evenement'] == $a['id_type_evenement'] ? 'selected' : '' ?>><?= htmlspecialchars($et['libelle_evenement'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:10px 14px;">
                        <select name="attend_obligatoire[]" class="form-input" style="padding:6px 10px;width:auto;">
                            <option value="1" <?= !isset($a['obligatoire']) || $a['obligatoire'] ? 'selected' : '' ?>>Oui</option>
                            <option value="0" <?= isset($a['obligatoire']) && !$a['obligatoire'] ? 'selected' : '' ?>>Non</option>
                        </select>
                    </td>
                    <td style="padding:10px 14px;">
                        <button type="button" onclick="removeRow(this)" class="btn btn-action btn-del-sm" title="Supprimer">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-top:1px solid #0a0a14;">
                <button type="button" onclick="addAttendRow()" class="btn btn-outline btn-sm">+ Ajouter</button>
                <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
            </div>
        </form>
    </div>

    <!-- Actions déclenchées -->
    <div class="section-label" style="margin-top:20px;">Actions déclenchées par l'étape</div>
    <div class="panel" style="padding:0;">
        <form method="POST">
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_declenche">
            <input type="hidden" name="id_etape" value="<?= (int)$editEtape['id_etape'] ?>">
            <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.78rem;">
                <thead>
                    <tr>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Moment</th>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Actionneur</th>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Action</th>
                        <th style="padding:9px 14px;font-size:.6rem;letter-spacing:.08em;color:#444;text-transform:uppercase;text-align:left;border-bottom:1px solid #0a0a14;font-weight:normal;">Valeur</th>
                        <th style="border-bottom:1px solid #0a0a14;width:40px;"></th>
                    </tr>
                </thead>
                <tbody id="dec-body">
                <?php
                $decRows = !empty($declencheList) ? $declencheList : [['id_actionneur' => '', 'id_type_action' => '', 'moment_declenchement' => 'on_success', 'valeur_action' => '']];
                foreach ($decRows as $d):
                    $mom = $d['moment_declenchement'] ?? 'on_success';
                ?>
                <tr class="dec-row">
                    <td style="padding:10px 14px;">
                        <select name="dec_moment[]" class="form-input" style="padding:6px 10px;width:auto;">
                            <option value="on_enter"  <?= $mom === 'on_enter'  ? 'selected' : '' ?>>On enter</option>
                            <option value="on_success"<?= $mom === 'on_success'? 'selected' : '' ?>>On success</option>
                            <option value="on_failure"<?= $mom === 'on_failure'? 'selected' : '' ?>>On failure</option>
                            <option value="on_hint"   <?= $mom === 'on_hint'   ? 'selected' : '' ?>>On hint</option>
                        </select>
                    </td>
                    <td style="padding:10px 14px;">
                        <select name="dec_actionneur[]" class="form-input" style="padding:6px 10px;">
                            <option value="">— actionneur —</option>
                            <?php foreach ($actionneurs as $act): ?>
                                <option value="<?= (int)$act['id_actionneur'] ?>" <?= isset($d['id_actionneur']) && $act['id_actionneur'] == $d['id_actionneur'] ? 'selected' : '' ?>><?= htmlspecialchars($act['nom_actionneur'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:10px 14px;">
                        <select name="dec_type[]" class="form-input" style="padding:6px 10px;">
                            <option value="">— action —</option>
                            <?php foreach ($actionTypes as $at): ?>
                                <option value="<?= (int)$at['id_type_action'] ?>" <?= isset($d['id_type_action']) && $at['id_type_action'] == $d['id_type_action'] ? 'selected' : '' ?>><?= htmlspecialchars($at['libelle_action'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="padding:10px 14px;">
                        <input type="text" name="dec_valeur[]" class="form-input" placeholder="ex : Niveau 1 OK !"
                               style="padding:6px 10px;" maxlength="100"
                               value="<?= htmlspecialchars($d['valeur_action'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </td>
                    <td style="padding:10px 14px;">
                        <button type="button" onclick="removeRow(this)" class="btn btn-action btn-del-sm" title="Supprimer">×</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 14px;border-top:1px solid #0a0a14;">
                <button type="button" onclick="addDecRow()" class="btn btn-outline btn-sm">+ Ajouter</button>
                <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
            </div>
        </form>
    </div>

    <?php else: ?>
    <!-- Formulaire ajout étape -->
    <div class="section-label">Ajouter une étape</div>
    <div class="panel">
        <form method="POST">
                <?= Csrf::field() ?>
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
                <button type="submit" class="btn btn-primary btn-save">Ajouter l'étape →</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>
lucide.createIcons();

function removeRow(btn) {
    btn.closest('tr').remove();
}

function makeSelect(name, options, selected = '', extra = '') {
    return `<select name="${name}" class="form-input" style="padding:6px 10px;" ${extra}>
        <option value="">—</option>
        ${options.map(o => `<option value="${o.v}"${o.v == selected ? ' selected' : ''}>${o.l}</option>`).join('')}
    </select>`;
}

function filterEvents(capteurSel) {
    const capteur = CAPTEURS.find(c => c.v == capteurSel.value);
    const type = capteur ? capteur.t : null;
    const row = capteurSel.closest('tr');
    const eventSel = row.querySelector('select[name="attend_event[]"]');
    Array.from(eventSel.options).forEach(opt => {
        if (!opt.value) return;
        const evt = EVENTS.find(e => e.v == opt.value);
        const hide = type && evt ? evt.t !== type : false;
        opt.hidden = hide;
        opt.disabled = hide;
    });
    const cur = eventSel.options[eventSel.selectedIndex];
    if (cur && cur.hidden) eventSel.value = '';
}

function addAttendRow() {
    const tr = document.createElement('tr');
    tr.className = 'attend-row';
    tr.innerHTML = `
        <td style="padding:10px 14px;">${makeSelect('attend_capteur[]', CAPTEURS, '', 'onchange="filterEvents(this)"')}</td>
        <td style="padding:10px 14px;">${makeSelect('attend_event[]', EVENTS)}</td>
        <td style="padding:10px 14px;">
            <select name="attend_obligatoire[]" class="form-input" style="padding:6px 10px;width:auto;">
                <option value="1" selected>Oui</option>
                <option value="0">Non</option>
            </select>
        </td>
        <td style="padding:10px 14px;">
            <button type="button" onclick="removeRow(this)" class="btn btn-action btn-del-sm" title="Supprimer">×</button>
        </td>`;
    document.getElementById('attend-body').appendChild(tr);
}

// Filtrer les événements au chargement pour les lignes pré-remplies
document.querySelectorAll('select[name="attend_capteur[]"]').forEach(filterEvents);

function addDecRow() {
    const tr = document.createElement('tr');
    tr.className = 'dec-row';
    tr.innerHTML = `
        <td style="padding:10px 14px;">
            <select name="dec_moment[]" class="form-input" style="padding:6px 10px;width:auto;">
                <option value="on_enter">On enter</option>
                <option value="on_success" selected>On success</option>
                <option value="on_failure">On failure</option>
                <option value="on_hint">On hint</option>
            </select>
        </td>
        <td style="padding:10px 14px;">${makeSelect('dec_actionneur[]', ACTIONNEURS)}</td>
        <td style="padding:10px 14px;">${makeSelect('dec_type[]', ACTION_TYPES)}</td>
        <td style="padding:10px 14px;">
            <input type="text" name="dec_valeur[]" class="form-input" placeholder="ex : Niveau 1 OK !" style="padding:6px 10px;" maxlength="100">
        </td>
        <td style="padding:10px 14px;">
            <button type="button" onclick="removeRow(this)" class="btn btn-action btn-del-sm" title="Supprimer">×</button>
        </td>`;
    document.getElementById('dec-body').appendChild(tr);
}
</script>
</body>
</html>
