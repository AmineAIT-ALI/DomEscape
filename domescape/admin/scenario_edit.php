<?php

require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../config/database.php';

RoleGuard::requireAdmin();

$pdo = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /domescape/admin/scenarios.php'); exit; }

$stmt = $pdo->prepare("SELECT * FROM scenario WHERE id_scenario = ? LIMIT 1");
$stmt->execute([$id]);
$scenario = $stmt->fetch();
if (!$scenario) { header('Location: /domescape/admin/scenarios.php'); exit; }

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_scenario') {
        $nom      = trim($_POST['nom_scenario']      ?? '');
        $desc     = trim($_POST['description']       ?? '');
        $theme    = trim($_POST['theme']             ?? '');
        $actif    = isset($_POST['actif']) ? 1 : 0;
        $dureeMax = ($_POST['duree_max_secondes'] ?? '') !== '' ? (int)$_POST['duree_max_secondes'] : null;
        if ($nom === '') { $error = 'Le nom est requis.'; }
        else {
            $pdo->prepare("UPDATE scenario SET nom_scenario=?,description=?,theme=?,actif=?,duree_max_secondes=? WHERE id_scenario=?")
                ->execute([$nom, $desc ?: null, $theme ?: null, $actif, $dureeMax, $id]);
            $success = 'Scénario mis à jour.';
            $stmt = $pdo->prepare("SELECT * FROM scenario WHERE id_scenario=? LIMIT 1");
            $stmt->execute([$id]); $scenario = $stmt->fetch();
        }
    }

    if ($action === 'add_etape') {
        $titre  = trim($_POST['titre_etape']       ?? '');
        $desc   = trim($_POST['description_etape'] ?? '');
        $msg_ok = trim($_POST['message_succes']    ?? '');
        $msg_ko = trim($_POST['message_echec']     ?? '');
        $indice = trim($_POST['indice']            ?? '');
        $points = max(0, (int)($_POST['points']    ?? 100));
        $finale = isset($_POST['finale']) ? 1 : 0;
        $attendCapteur  = (int)($_POST['attend_capteur']     ?? 0);
        $attendEvent    = (int)($_POST['attend_event']       ?? 0);
        $attendOblig    = (int)($_POST['attend_obligatoire'] ?? 1);
        $decActionneurs = $_POST['dec_actionneur'] ?? [];
        $decTypes       = $_POST['dec_type']       ?? [];
        $decMoments     = $_POST['dec_moment']     ?? [];
        $decValeurs     = $_POST['dec_valeur']     ?? [];
        $allowed = ['on_enter','on_success','on_failure','on_hint'];

        if ($titre === '') { $error = 'Le titre de l\'étape est requis.'; }
        else {
            $pdo->beginTransaction();
            try {
                $maxNum = $pdo->prepare("SELECT COALESCE(MAX(numero_etape),0) FROM etape WHERE id_scenario=?");
                $maxNum->execute([$id]);
                $nextNum = (int)$maxNum->fetchColumn() + 1;
                $pdo->prepare("INSERT INTO etape (id_scenario,numero_etape,titre_etape,description_etape,message_succes,message_echec,indice,points,finale) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$id,$nextNum,$titre,$desc?:null,$msg_ok?:null,$msg_ko?:null,$indice?:null,$points,$finale]);
                $idEtape = (int)$pdo->lastInsertId();
                if ($attendCapteur > 0 && $attendEvent > 0)
                    $pdo->prepare("INSERT IGNORE INTO etape_attend (id_etape,id_capteur,id_type_evenement,obligatoire) VALUES (?,?,?,?)")
                        ->execute([$idEtape,$attendCapteur,$attendEvent,$attendOblig]);
                $ins = $pdo->prepare("INSERT INTO etape_declenche (id_etape,id_actionneur,id_type_action,ordre_action,valeur_action,moment_declenchement) VALUES (?,?,?,?,?,?)");
                $ordre = 1;
                foreach ($decActionneurs as $i => $act) {
                    $act    = (int)$act;
                    $type   = (int)($decTypes[$i]   ?? 0);
                    $moment = in_array($decMoments[$i] ?? '', $allowed) ? $decMoments[$i] : 'on_success';
                    $valeur = trim($decValeurs[$i] ?? '') ?: null;
                    if ($act > 0 && $type > 0) $ins->execute([$idEtape,$act,$type,$ordre++,$valeur,$moment]);
                }
                $pdo->commit();
                $success = "Étape $nextNum « ".htmlspecialchars($titre, ENT_QUOTES, 'UTF-8')." » créée.";
                header("Location: /domescape/admin/scenario_edit.php?id=$id"); exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = 'Erreur : ' . $e->getMessage();
            }
        }
    }

    if ($action === 'update_etape') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $titre   = trim($_POST['titre_etape']       ?? '');
        $desc    = trim($_POST['description_etape'] ?? '');
        $msg_ok  = trim($_POST['message_succes']    ?? '');
        $msg_ko  = trim($_POST['message_echec']     ?? '');
        $indice  = trim($_POST['indice']            ?? '');
        $points  = max(0, (int)($_POST['points']    ?? 100));
        $finale  = isset($_POST['finale']) ? 1 : 0;
        if ($titre === '') { $error = 'Le titre est requis.'; }
        else {
            $pdo->prepare("UPDATE etape SET titre_etape=?,description_etape=?,message_succes=?,message_echec=?,indice=?,points=?,finale=? WHERE id_etape=? AND id_scenario=?")
                ->execute([$titre,$desc?:null,$msg_ok?:null,$msg_ko?:null,$indice?:null,$points,$finale,$idEtape,$id]);
            $success = 'Étape mise à jour.';
            header("Location: /domescape/admin/scenario_edit.php?id=$id"); exit;
        }
    }

    if ($action === 'delete_etape') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $pdo->prepare("DELETE FROM etape WHERE id_etape=? AND id_scenario=?")->execute([$idEtape,$id]);
        $all = $pdo->prepare("SELECT id_etape FROM etape WHERE id_scenario=? ORDER BY numero_etape");
        $all->execute([$id]); $n = 1;
        foreach ($all->fetchAll() as $row)
            $pdo->prepare("UPDATE etape SET numero_etape=? WHERE id_etape=?")->execute([$n++,$row['id_etape']]);
        $success = 'Étape supprimée.';
    }

    if ($action === 'save_attend') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $chk = $pdo->prepare("SELECT id_etape FROM etape WHERE id_etape=? AND id_scenario=?");
        $chk->execute([$idEtape,$id]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM etape_attend WHERE id_etape=?")->execute([$idEtape]);
            $caps=$_POST['attend_capteur']??[]; $evts=$_POST['attend_event']??[]; $obligs=$_POST['attend_obligatoire']??[];
            $ins=$pdo->prepare("INSERT IGNORE INTO etape_attend (id_etape,id_capteur,id_type_evenement,obligatoire) VALUES (?,?,?,?)");
            foreach ($caps as $i=>$cap) {
                $cap=(int)$cap; $evt=(int)($evts[$i]??0); $obli=(int)($obligs[$i]??1);
                if ($cap>0&&$evt>0) $ins->execute([$idEtape,$cap,$evt,$obli]);
            }
            $success='Événements attendus enregistrés.';
            header("Location: /domescape/admin/scenario_edit.php?id=$id&edit_etape=$idEtape"); exit;
        }
    }

    if ($action === 'save_declenche') {
        $idEtape = (int)($_POST['id_etape'] ?? 0);
        $chk = $pdo->prepare("SELECT id_etape FROM etape WHERE id_etape=? AND id_scenario=?");
        $chk->execute([$idEtape,$id]);
        if ($chk->fetch()) {
            $pdo->prepare("DELETE FROM etape_declenche WHERE id_etape=?")->execute([$idEtape]);
            $acts=$_POST['dec_actionneur']??[]; $types=$_POST['dec_type']??[]; $moments=$_POST['dec_moment']??[]; $valeurs=$_POST['dec_valeur']??[];
            $allowed=['on_enter','on_success','on_failure','on_hint'];
            $ins=$pdo->prepare("INSERT INTO etape_declenche (id_etape,id_actionneur,id_type_action,ordre_action,valeur_action,moment_declenchement) VALUES (?,?,?,?,?,?)");
            $ordre=1;
            foreach ($acts as $i=>$act) {
                $act=(int)$act; $type=(int)($types[$i]??0);
                $moment=in_array($moments[$i]??'',$allowed)?$moments[$i]:'on_success';
                $valeur=trim($valeurs[$i]??'')?:null;
                if ($act>0&&$type>0) $ins->execute([$idEtape,$act,$type,$ordre++,$valeur,$moment]);
            }
            $success='Actions déclenchées enregistrées.';
            header("Location: /domescape/admin/scenario_edit.php?id=$id&edit_etape=$idEtape"); exit;
        }
    }
}

$etapesStmt = $pdo->prepare("SELECT * FROM etape WHERE id_scenario=? ORDER BY numero_etape");
$etapesStmt->execute([$id]);
$etapes = $etapesStmt->fetchAll();

// Aperçu condition attendue pour chaque étape (inline dans la liste)
$etapeAttendMap = [];
if (!empty($etapes)) {
    $ids = implode(',', array_map(fn($e) => (int)$e['id_etape'], $etapes));
    $s = $pdo->query("SELECT ea.id_etape, c.nom_capteur, et.code_evenement
                      FROM etape_attend ea
                      JOIN capteur c ON c.id_capteur=ea.id_capteur
                      JOIN evenement_type et ON et.id_type_evenement=ea.id_type_evenement
                      WHERE ea.id_etape IN ($ids) AND ea.obligatoire=1");
    foreach ($s->fetchAll() as $row) $etapeAttendMap[$row['id_etape']] = $row;
}

$editEtape = null;
if (isset($_GET['edit_etape'])) {
    $editId = (int)$_GET['edit_etape'];
    foreach ($etapes as $e) if ((int)$e['id_etape']===$editId) { $editEtape=$e; break; }
}

$capteurs    = $pdo->query("SELECT * FROM capteur    WHERE actif=1 ORDER BY nom_capteur")->fetchAll();
$eventTypes  = $pdo->query("SELECT * FROM evenement_type ORDER BY libelle_evenement")->fetchAll();
$actionneurs = $pdo->query("SELECT * FROM actionneur WHERE actif=1 ORDER BY nom_actionneur")->fetchAll();
$actionTypes = $pdo->query("SELECT * FROM action_type ORDER BY libelle_action")->fetchAll();

$attendList = $declencheList = [];
if ($editEtape) {
    $s=$pdo->prepare("SELECT ea.*,c.nom_capteur,et.libelle_evenement FROM etape_attend ea JOIN capteur c ON c.id_capteur=ea.id_capteur JOIN evenement_type et ON et.id_type_evenement=ea.id_type_evenement WHERE ea.id_etape=?");
    $s->execute([$editEtape['id_etape']]); $attendList=$s->fetchAll();
    $s=$pdo->prepare("SELECT ed.*,a.nom_actionneur,at.libelle_action FROM etape_declenche ed JOIN actionneur a ON a.id_actionneur=ed.id_actionneur JOIN action_type at ON at.id_type_action=ed.id_type_action WHERE ed.id_etape=? ORDER BY ed.moment_declenchement,ed.ordre_action");
    $s->execute([$editEtape['id_etape']]); $declencheList=$s->fetchAll();
}

$flags         = JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT;
$jsCapteurs    = json_encode(array_values(array_map(fn($c)=>['v'=>(int)$c['id_capteur'],'l'=>$c['nom_capteur'],'t'=>$c['type_capteur']],$capteurs)),$flags);
$jsEvents      = json_encode(array_values(array_map(fn($e)=>['v'=>(int)$e['id_type_evenement'],'l'=>$e['libelle_evenement'],'t'=>$e['type_capteur']],$eventTypes)),$flags);
$jsActionneurs = json_encode(array_values(array_map(fn($a)=>['v'=>(int)$a['id_actionneur'],'l'=>$a['nom_actionneur']],$actionneurs)),$flags);
$jsTypes       = json_encode(array_values(array_map(fn($at)=>['v'=>(int)$at['id_type_action'],'l'=>$at['libelle_action']],$actionTypes)),$flags);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($scenario['nom_scenario'], ENT_QUOTES, 'UTF-8') ?> — DomEscape</title>
  <link rel="stylesheet" href="/domescape/assets/css/components.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    .sg { font-family: 'Space Grotesk', system-ui, sans-serif; }
    .mono { font-family: 'JetBrains Mono', monospace; }

    .se-wrap { max-width: 860px; margin: 0 auto; padding: 32px 24px 80px; }

    .se-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; margin-bottom: 40px; }
    .se-header-left { display: flex; flex-direction: column; gap: 6px; }
    .se-breadcrumb { font-size: .68rem; color: var(--muted); letter-spacing: .05em; display: flex; align-items: center; gap: 6px; }
    .se-breadcrumb a { color: var(--muted); text-decoration: none; transition: color .15s; }
    .se-breadcrumb a:hover { color: var(--text); }
    .se-breadcrumb-sep { opacity: .3; }
    .se-title { font-family: 'Space Grotesk', system-ui, sans-serif; font-size: 1.5rem; font-weight: 700; color: var(--text); letter-spacing: -.03em; line-height: 1.1; margin: 0; }
    .se-meta { display: flex; align-items: center; gap: 10px; margin-top: 2px; }
    .se-badge { font-family: 'JetBrains Mono', monospace; font-size: .62rem; padding: 2px 8px; border-radius: 4px; border: 1px solid; letter-spacing: .04em; }
    .se-badge-active { color: var(--accent); border-color: rgba(0,255,136,.2); background: rgba(0,255,136,.05); }
    .se-badge-inactive { color: var(--muted); border-color: var(--border); background: rgba(255,255,255,.02); }
    .se-badge-steps { color: var(--muted); border-color: var(--border); background: transparent; }

    .se-section { font-family: 'JetBrains Mono', monospace; font-size: .62rem; font-weight: 500; letter-spacing: .14em; text-transform: uppercase; color: var(--muted); margin: 32px 0 12px; display: flex; align-items: center; gap: 10px; }
    .se-section::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    .se-panel { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 24px; margin-bottom: 20px; }

    .step-list { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
    .step-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; display: grid; grid-template-columns: 48px 1fr auto; gap: 16px; align-items: center; transition: border-color .2s, background .2s; }
    .step-card:hover { border-color: rgba(255,255,255,0.12); background: rgba(255,255,255,0.015); }
    .step-card-num { font-family: 'Space Grotesk', system-ui, sans-serif; font-size: 1.4rem; font-weight: 700; color: rgba(0,255,136,.25); letter-spacing: -.04em; line-height: 1; text-align: center; }
    .step-card-body { display: flex; flex-direction: column; gap: 5px; }
    .step-card-title { font-family: 'Space Grotesk', system-ui, sans-serif; font-size: .92rem; font-weight: 600; color: var(--text); letter-spacing: -.02em; display: flex; align-items: center; gap: 8px; }
    .step-card-cond { display: flex; align-items: center; gap: 6px; font-size: .7rem; color: var(--muted); }
    .step-card-cond-icon { color: var(--accent); opacity: .5; }
    .step-card-cond-tag { font-family: 'JetBrains Mono', monospace; font-size: .65rem; color: var(--dim); background: rgba(255,255,255,.03); border: 1px solid var(--border); padding: 1px 6px; border-radius: 3px; }
    .step-card-actions { display: flex; flex-direction: column; gap: 5px; align-items: flex-end; }
    .step-pts { font-family: 'JetBrains Mono', monospace; font-size: .65rem; color: var(--muted); white-space: nowrap; }
    .step-finale { font-size: .6rem; color: var(--accent); border: 1px solid rgba(0,255,136,.2); background: rgba(0,255,136,.04); padding: 2px 7px; border-radius: 3px; letter-spacing: .04em; font-family: 'JetBrains Mono', monospace; }
    .step-btns { display: flex; gap: 5px; }
    .step-empty { background: var(--bg-card); border: 1px dashed rgba(255,255,255,.06); border-radius: 12px; padding: 32px; text-align: center; color: var(--muted); font-size: .78rem; }

    .bloc-wrap { display: flex; flex-direction: column; gap: 0; }
    .bloc { background: var(--bg-card); border: 1px solid var(--border); border-radius: 14px; padding: 22px 24px; transition: border-color .2s; }
    .bloc:focus-within { border-color: rgba(255,255,255,.1); }
    .bloc-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
    .bloc-num { width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-family: 'Space Grotesk', system-ui, sans-serif; font-size: .72rem; font-weight: 700; flex-shrink: 0; }
    .bloc-num-1 { background: rgba(0,255,136,.1); color: var(--accent); border: 1px solid rgba(0,255,136,.2); }
    .bloc-num-2 { background: rgba(167,139,250,.1); color: var(--purple); border: 1px solid rgba(167,139,250,.2); }
    .bloc-num-3 { background: rgba(96,165,250,.1); color: var(--blue); border: 1px solid rgba(96,165,250,.2); }
    .bloc-title { font-family: 'JetBrains Mono', monospace; font-size: .65rem; letter-spacing: .12em; text-transform: uppercase; font-weight: 500; }
    .bloc-title-1 { color: var(--accent); }
    .bloc-title-2 { color: var(--purple); }
    .bloc-title-3 { color: var(--blue); }
    .bloc-subtitle { font-size: .72rem; color: var(--muted); margin-left: auto; }
    .bloc-connector { display: flex; align-items: center; justify-content: center; gap: 16px; padding: 10px 0; }
    .bloc-connector-line { flex: 1; max-width: 100px; height: 1px; background: linear-gradient(90deg, transparent, rgba(0,255,136,.14), transparent); }
    .bloc-connector-badge {
      display: inline-flex; align-items: center; gap: 7px;
      font-family: 'JetBrains Mono', monospace; font-size: .62rem; font-weight: 500;
      color: rgba(0,255,136,.6);
      background: rgba(0,255,136,.03);
      border: 1px solid rgba(0,255,136,.1);
      padding: 5px 14px 5px 10px; border-radius: 100px;
      letter-spacing: .05em; white-space: nowrap;
      box-shadow: 0 0 24px rgba(0,255,136,.05);
    }

    .moment-enter   { color: var(--blue);   background: rgba(96,165,250,.08);   border-color: rgba(96,165,250,.2);   }
    .moment-success { color: var(--accent); background: rgba(0,255,136,.07);    border-color: rgba(0,255,136,.18);   }
    .moment-failure { color: var(--red);    background: rgba(248,113,113,.08);  border-color: rgba(248,113,113,.18); }
    .moment-hint    { color: var(--yellow); background: rgba(251,191,36,.07);   border-color: rgba(251,191,36,.18);  }
    .moment-badge { display: inline-flex; align-items: center; font-family: 'JetBrains Mono', monospace; font-size: .6rem; padding: 2px 7px; border-radius: 3px; border: 1px solid; letter-spacing: .04em; white-space: nowrap; }
    select.moment-select { font-family: 'JetBrains Mono', monospace; font-size: .72rem; }

    .edit-bloc { background: rgba(96,165,250,.03); border: 1px solid rgba(96,165,250,.14); border-radius: 14px; padding: 24px; margin-bottom: 16px; }
    .edit-bloc-header { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid rgba(96,165,250,.08); }
    .edit-bloc-icon { color: var(--blue); }
    .edit-bloc-title { font-family: 'JetBrains Mono', monospace; font-size: .65rem; color: var(--blue); letter-spacing: .1em; text-transform: uppercase; font-weight: 500; }
    .edit-bloc-name { font-family: 'Space Grotesk', system-ui, sans-serif; font-size: .85rem; font-weight: 600; color: var(--text); margin-left: auto; }

    .edit-table { width: 100%; border-collapse: collapse; font-size: .78rem; }
    .edit-table th { font-size: .6rem; letter-spacing: .08em; color: var(--muted); text-transform: uppercase; padding: 8px 12px; text-align: left; font-weight: 500; border-bottom: 1px solid rgba(255,255,255,.04); font-family: 'JetBrains Mono', monospace; }
    .edit-table td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,.03); vertical-align: middle; }
    .edit-table tbody tr:last-child td { border-bottom: none; }
    .edit-table-foot { display: flex; align-items: center; justify-content: space-between; padding: 12px 12px 4px; }

    .se-alert { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 8px; font-size: .8rem; margin-bottom: 20px; border: 1px solid; }
    .se-alert-error   { background: rgba(248,113,113,.05); border-color: rgba(248,113,113,.18); color: #fca5a5; }
    .se-alert-success { background: rgba(0,255,136,.04);   border-color: rgba(0,255,136,.16);   color: var(--accent); }
    .se-alert-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; flex-shrink: 0; }

    .form-group label { font-family: 'JetBrains Mono', monospace; font-size: .62rem; color: var(--muted); letter-spacing: .08em; text-transform: uppercase; font-weight: 500; display: block; margin-bottom: 7px; }
    .form-group { margin-bottom: 16px; }
    .form-input,
    .form-group input:not([type=checkbox]):not([type=radio]),
    .form-group textarea,
    .form-group select { width: 100%; background: #0a0a12; border: 1px solid rgba(255,255,255,.07); color: var(--text); font-family: system-ui, sans-serif; font-size: .82rem; padding: 9px 12px; border-radius: 7px; outline: none; transition: border-color .15s, box-shadow .15s; }
    .form-input:focus, .form-group input:focus, .form-group textarea:focus, .form-group select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(0,255,136,.07); }
    textarea.form-input { resize: vertical; min-height: 68px; }
    .form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .cb-row { display: flex; align-items: center; gap: 8px; }
    .cb-row input[type=checkbox] { accent-color: var(--accent); width: 15px; height: 15px; cursor: pointer; }
    .cb-row label { font-size: .8rem; color: var(--muted); cursor: pointer; font-family: system-ui, sans-serif; text-transform: none; letter-spacing: 0; }
    @media (max-width: 600px) { .form-grid-2 { grid-template-columns: 1fr; } }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="se-wrap">

  <div class="se-header">
    <div class="se-header-left">
      <div class="se-breadcrumb">
        <a href="/domescape/admin/scenarios.php">Scénarios</a>
        <span class="se-breadcrumb-sep">/</span>
        <span><?= htmlspecialchars($scenario['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></span>
      </div>
      <h1 class="se-title"><?= htmlspecialchars($scenario['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></h1>
      <div class="se-meta">
        <span class="se-badge <?= $scenario['actif'] ? 'se-badge-active' : 'se-badge-inactive' ?>">
          <?= $scenario['actif'] ? 'ACTIF' : 'INACTIF' ?>
        </span>
        <span class="se-badge se-badge-steps"><?= count($etapes) ?> étape<?= count($etapes) != 1 ? 's' : '' ?></span>
        <?php if ($scenario['duree_max_secondes']): ?>
          <span class="se-badge se-badge-steps"><?= (int)($scenario['duree_max_secondes']/60) ?> min max</span>
        <?php endif; ?>
      </div>
    </div>
    <a href="/domescape/admin/scenarios.php" class="btn btn-outline btn-sm">← Retour</a>
  </div>

  <?php if ($error): ?>
    <div class="se-alert se-alert-error"><div class="se-alert-dot"></div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="se-alert se-alert-success"><div class="se-alert-dot"></div><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="se-section">Informations du scénario</div>
  <div class="se-panel">
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
      <div class="form-group">
        <label>Durée limite (secondes — laisser vide = illimitée)</label>
        <input type="number" name="duree_max_secondes" class="form-input" min="60" max="86400"
               value="<?= $scenario['duree_max_secondes'] !== null ? (int)$scenario['duree_max_secondes'] : '' ?>"
               placeholder="ex : 3600">
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:4px;">
        <div class="cb-row">
          <input type="checkbox" name="actif" id="actif" <?= $scenario['actif'] ? 'checked' : '' ?>>
          <label for="actif">Scénario actif — visible dans la liste des parties</label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
      </div>
    </form>
  </div>

  <div class="se-section">Étapes (<?= count($etapes) ?>)</div>

  <?php if (empty($etapes)): ?>
    <div class="step-empty">
      <div style="font-size:1.4rem;margin-bottom:10px;opacity:.2;">⬡</div>
      Aucune étape. Configurez-en une ci-dessous.
    </div>
  <?php else: ?>
    <div class="step-list">
    <?php foreach ($etapes as $e): ?>
      <?php $cond = $etapeAttendMap[(int)$e['id_etape']] ?? null; ?>
      <div class="step-card">
        <div class="step-card-num"><?= str_pad((int)$e['numero_etape'], 2, '0', STR_PAD_LEFT) ?></div>
        <div class="step-card-body">
          <div class="step-card-title">
            <?= htmlspecialchars($e['titre_etape'], ENT_QUOTES, 'UTF-8') ?>
            <?php if ($e['finale']): ?><span class="step-finale">FINALE</span><?php endif; ?>
          </div>
          <?php if ($cond): ?>
          <div class="step-card-cond">
            <span class="step-card-cond-icon">→</span>
            <span class="step-card-cond-tag"><?= htmlspecialchars($cond['nom_capteur'], ENT_QUOTES, 'UTF-8') ?></span>
            <span style="color:rgba(255,255,255,.12);">·</span>
            <span class="step-card-cond-tag"><?= htmlspecialchars($cond['code_evenement'], ENT_QUOTES, 'UTF-8') ?></span>
          </div>
          <?php elseif ($e['description_etape']): ?>
          <div style="font-size:.72rem;color:var(--muted);">
            <?= htmlspecialchars(mb_substr($e['description_etape'], 0, 70), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($e['description_etape']) > 70 ? '…' : '' ?>
          </div>
          <?php endif; ?>
        </div>
        <div class="step-card-actions">
          <div style="display:flex;align-items:center;gap:8px;">
            <span class="step-pts"><?= (int)$e['points'] ?> pts</span>
            <div class="step-btns">
              <a href="?id=<?= $id ?>&edit_etape=<?= (int)$e['id_etape'] ?>" class="btn btn-sm btn-edit-sm">
                <i data-lucide="pencil" style="width:10px;height:10px;"></i> Éditer
              </a>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Supprimer cette étape ?');">
                <input type="hidden" name="action" value="delete_etape">
                <input type="hidden" name="id_etape" value="<?= (int)$e['id_etape'] ?>">
                <button type="submit" class="btn btn-sm btn-del-sm" title="Supprimer">
                  <i data-lucide="trash-2" style="width:10px;height:10px;"></i>
                </button>
              </form>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($editEtape): ?>
  <div class="se-section">Modifier l'étape <?= (int)$editEtape['numero_etape'] ?></div>

  <div class="edit-bloc">
    <div class="edit-bloc-header">
      <i data-lucide="pencil" class="edit-bloc-icon" style="width:14px;height:14px;"></i>
      <span class="edit-bloc-title">Étape</span>
      <span class="edit-bloc-name"><?= htmlspecialchars($editEtape['titre_etape'], ENT_QUOTES, 'UTF-8') ?></span>
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
          <input type="number" name="points" class="form-input" min="0" max="9999" value="<?= (int)$editEtape['points'] ?>">
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
        <label>Indice</label>
        <textarea name="indice" class="form-input" style="min-height:52px;"><?= htmlspecialchars($editEtape['indice'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
      </div>
      <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:4px;">
        <div class="cb-row">
          <input type="checkbox" name="finale" id="finale_edit" <?= $editEtape['finale'] ? 'checked' : '' ?>>
          <label for="finale_edit">Étape finale — la victoire est déclenchée au succès</label>
        </div>
        <div style="display:flex;gap:8px;">
          <a href="?id=<?= $id ?>" class="btn btn-outline btn-sm">Annuler</a>
          <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
        </div>
      </div>
    </form>
  </div>

  <div class="edit-bloc" style="background:rgba(167,139,250,.02);border-color:rgba(167,139,250,.12);">
    <div class="edit-bloc-header" style="border-color:rgba(167,139,250,.08);">
      <i data-lucide="radio" style="width:14px;height:14px;color:var(--purple);"></i>
      <span style="font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--purple);letter-spacing:.1em;text-transform:uppercase;font-weight:500;">Condition attendue</span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_attend">
      <input type="hidden" name="id_etape" value="<?= (int)$editEtape['id_etape'] ?>">
      <div style="overflow-x:auto;">
      <table class="edit-table">
        <thead><tr>
          <th>Capteur</th><th>Événement</th><th>Obligatoire</th><th style="width:36px;"></th>
        </tr></thead>
        <tbody id="attend-body">
        <?php
        $aRows = !empty($attendList) ? $attendList : [['id_capteur'=>'','id_type_evenement'=>'','obligatoire'=>1]];
        foreach ($aRows as $a): ?>
        <tr class="attend-row">
          <td><select name="attend_capteur[]" class="form-input" style="padding:6px 10px;" onchange="filterEvents(this)">
            <option value="">— capteur —</option>
            <?php foreach ($capteurs as $c): ?>
              <option value="<?= (int)$c['id_capteur'] ?>" <?= isset($a['id_capteur'])&&$c['id_capteur']==$a['id_capteur']?'selected':'' ?>><?= htmlspecialchars($c['nom_capteur'],ENT_QUOTES,'UTF-8') ?></option>
            <?php endforeach; ?>
          </select></td>
          <td><select name="attend_event[]" class="form-input" style="padding:6px 10px;">
            <option value="">— événement —</option>
            <?php foreach ($eventTypes as $et): ?>
              <option value="<?= (int)$et['id_type_evenement'] ?>" <?= isset($a['id_type_evenement'])&&$et['id_type_evenement']==$a['id_type_evenement']?'selected':'' ?>><?= htmlspecialchars($et['libelle_evenement'],ENT_QUOTES,'UTF-8') ?></option>
            <?php endforeach; ?>
          </select></td>
          <td><select name="attend_obligatoire[]" class="form-input" style="padding:6px 10px;width:auto;">
            <option value="1" <?= !isset($a['obligatoire'])||$a['obligatoire']?'selected':'' ?>>Oui</option>
            <option value="0" <?= isset($a['obligatoire'])&&!$a['obligatoire']?'selected':'' ?>>Non</option>
          </select></td>
          <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-del-sm">×</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="edit-table-foot">
        <button type="button" onclick="addAttendRow()" class="btn btn-outline btn-sm">+ Ajouter</button>
        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
      </div>
    </form>
  </div>

  <div class="edit-bloc" style="background:rgba(96,165,250,.02);border-color:rgba(96,165,250,.12);">
    <div class="edit-bloc-header" style="border-color:rgba(96,165,250,.08);">
      <i data-lucide="zap" style="width:14px;height:14px;color:var(--blue);"></i>
      <span style="font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--blue);letter-spacing:.1em;text-transform:uppercase;font-weight:500;">Actions déclenchées</span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="save_declenche">
      <input type="hidden" name="id_etape" value="<?= (int)$editEtape['id_etape'] ?>">
      <div style="overflow-x:auto;">
      <table class="edit-table">
        <thead><tr>
          <th>Moment</th><th>Actionneur</th><th>Action</th><th>Valeur</th><th style="width:36px;"></th>
        </tr></thead>
        <tbody id="dec-body">
        <?php
        $dRows = !empty($declencheList) ? $declencheList : [['id_actionneur'=>'','id_type_action'=>'','moment_declenchement'=>'on_success','valeur_action'=>'']];
        foreach ($dRows as $d): $mom=$d['moment_declenchement']??'on_success'; ?>
        <tr class="dec-row">
          <td><?= momentSelect('dec_moment[]', $mom) ?></td>
          <td><select name="dec_actionneur[]" class="form-input" style="padding:6px 10px;">
            <option value="">—</option>
            <?php foreach ($actionneurs as $act): ?>
              <option value="<?= (int)$act['id_actionneur'] ?>" <?= isset($d['id_actionneur'])&&$act['id_actionneur']==$d['id_actionneur']?'selected':'' ?>><?= htmlspecialchars($act['nom_actionneur'],ENT_QUOTES,'UTF-8') ?></option>
            <?php endforeach; ?>
          </select></td>
          <td><select name="dec_type[]" class="form-input" style="padding:6px 10px;">
            <option value="">—</option>
            <?php foreach ($actionTypes as $at): ?>
              <option value="<?= (int)$at['id_type_action'] ?>" <?= isset($d['id_type_action'])&&$at['id_type_action']==$d['id_type_action']?'selected':'' ?>><?= htmlspecialchars($at['libelle_action'],ENT_QUOTES,'UTF-8') ?></option>
            <?php endforeach; ?>
          </select></td>
          <td><input type="text" name="dec_valeur[]" class="form-input" placeholder="ex : ACCESS GRANTED" style="padding:6px 10px;" maxlength="100" value="<?= htmlspecialchars($d['valeur_action']??'',ENT_QUOTES,'UTF-8') ?>"></td>
          <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-del-sm">×</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <div class="edit-table-foot">
        <button type="button" onclick="addDecRow()" class="btn btn-outline btn-sm">+ Ajouter</button>
        <button type="submit" class="btn btn-primary btn-sm">Enregistrer</button>
      </div>
    </form>
  </div>

  <?php else: ?>
  <div class="se-section">Nouvelle étape</div>

  <form method="POST">
    <input type="hidden" name="action" value="add_etape">
    <div class="bloc-wrap">

      <div class="bloc">
        <div class="bloc-header">
          <div class="bloc-num bloc-num-1">1</div>
          <span class="bloc-title bloc-title-1">Étape</span>
          <span class="bloc-subtitle">Informations générales</span>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label>Titre *</label>
            <input type="text" name="titre_etape" class="form-input" placeholder="ex : AI Core Initialization" maxlength="150" required>
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
            <input type="text" name="message_succes" class="form-input" maxlength="300" placeholder="Bravo ! Étape réussie.">
          </div>
          <div class="form-group">
            <label>Message échec</label>
            <input type="text" name="message_echec" class="form-input" maxlength="300" placeholder="Mauvaise action. Réessayez.">
          </div>
        </div>
        <div class="form-group">
          <label>Indice (envoyé sur demande du superviseur)</label>
          <textarea name="indice" class="form-input" style="min-height:48px;" placeholder="Regardez derrière le tableau…"></textarea>
        </div>
        <div class="cb-row">
          <input type="checkbox" name="finale" id="finale_add">
          <label for="finale_add">Étape finale — la victoire est déclenchée au succès</label>
        </div>
      </div>

      <div class="bloc-connector">
        <div class="bloc-connector-line"></div>
        <div class="bloc-connector-badge">
          <i data-lucide="arrow-down" style="width:10px;height:10px;"></i>
          Si le capteur reçoit cet événement
        </div>
        <div class="bloc-connector-line"></div>
      </div>

      <div class="bloc">
        <div class="bloc-header">
          <div class="bloc-num bloc-num-2">2</div>
          <span class="bloc-title bloc-title-2">Condition attendue</span>
          <span class="bloc-subtitle">Optionnelle</span>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label>Capteur</label>
            <select name="attend_capteur" id="add_capteur" class="form-input" onchange="filterAddEvents()">
              <option value="">— aucun —</option>
              <?php foreach ($capteurs as $c): ?>
                <option value="<?= (int)$c['id_capteur'] ?>" data-type="<?= htmlspecialchars($c['type_capteur'],ENT_QUOTES,'UTF-8') ?>">
                  <?= htmlspecialchars($c['nom_capteur'],ENT_QUOTES,'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Événement attendu</label>
            <select name="attend_event" id="add_event" class="form-input">
              <option value="">— sélectionner un capteur —</option>
              <?php foreach ($eventTypes as $et): ?>
                <option value="<?= (int)$et['id_type_evenement'] ?>" data-type="<?= htmlspecialchars($et['type_capteur'],ENT_QUOTES,'UTF-8') ?>">
                  <?= htmlspecialchars($et['libelle_evenement'],ENT_QUOTES,'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="cb-row">
          <input type="checkbox" name="attend_obligatoire" id="add_oblig" value="1" checked>
          <label for="add_oblig">Obligatoire — doit correspondre exactement pour valider l'étape</label>
        </div>
      </div>

      <div class="bloc-connector">
        <div class="bloc-connector-line"></div>
        <div class="bloc-connector-badge">
          <i data-lucide="arrow-down" style="width:10px;height:10px;"></i>
          Le moteur déclenche ces actions
        </div>
        <div class="bloc-connector-line"></div>
      </div>

      <div class="bloc">
        <div class="bloc-header">
          <div class="bloc-num bloc-num-3">3</div>
          <span class="bloc-title bloc-title-3">Actions déclenchées</span>
          <span class="bloc-subtitle">Au moins une recommandée</span>
        </div>
        <div style="overflow-x:auto;">
        <table class="edit-table">
          <thead><tr>
            <th>Moment</th><th>Actionneur</th><th>Action</th><th>Valeur / Message</th><th style="width:32px;"></th>
          </tr></thead>
          <tbody id="dec-body">
            <tr class="dec-row">
              <td><?= momentSelect('dec_moment[]', 'on_success') ?></td>
              <td><select name="dec_actionneur[]" class="form-input" style="padding:6px 10px;">
                <option value="">—</option>
                <?php foreach ($actionneurs as $act): ?>
                  <option value="<?= (int)$act['id_actionneur'] ?>"><?= htmlspecialchars($act['nom_actionneur'],ENT_QUOTES,'UTF-8') ?></option>
                <?php endforeach; ?>
              </select></td>
              <td><select name="dec_type[]" class="form-input" style="padding:6px 10px;">
                <option value="">—</option>
                <?php foreach ($actionTypes as $at): ?>
                  <option value="<?= (int)$at['id_type_action'] ?>"><?= htmlspecialchars($at['libelle_action'],ENT_QUOTES,'UTF-8') ?></option>
                <?php endforeach; ?>
              </select></td>
              <td><input type="text" name="dec_valeur[]" class="form-input" placeholder="ex : ACCESS GRANTED" style="padding:6px 10px;" maxlength="100"></td>
              <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-del-sm">×</button></td>
            </tr>
          </tbody>
        </table>
        </div>
        <div style="margin-top:10px;">
          <button type="button" onclick="addDecRow()" class="btn btn-outline btn-sm">+ Ajouter une action</button>
        </div>
      </div>

    </div>

    <div style="display:flex;justify-content:flex-end;margin-top:16px;">
      <button type="submit" class="btn btn-primary">Créer l'étape →</button>
    </div>
  </form>
  <?php endif; ?>

</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>
lucide.createIcons();

const CAPTEURS     = <?= $jsCapteurs ?>;
const EVENTS       = <?= $jsEvents ?>;
const ACTIONNEURS  = <?= $jsActionneurs ?>;
const ACTION_TYPES = <?= $jsTypes ?>;

const MOMENT_CLASSES = {
  on_enter:   'moment-enter',
  on_success: 'moment-success',
  on_failure: 'moment-failure',
  on_hint:    'moment-hint',
};

function removeRow(btn) { btn.closest('tr').remove(); }

function makeSelect(name, options, selected='', extra='') {
  return `<select name="${name}" class="form-input" style="padding:6px 10px;" ${extra}>
    <option value="">—</option>
    ${options.map(o=>`<option value="${o.v}"${o.v==selected?' selected':''}>${o.l}</option>`).join('')}
  </select>`;
}

function makeMomentSelect(name, selected='on_success') {
  const opts=[
    {v:'on_enter',  l:"À l'entrée"},
    {v:'on_success',l:'Au succès'},
    {v:'on_failure',l:"En cas d'échec"},
    {v:'on_hint',   l:'Sur indice'},
  ];
  return `<select name="${name}" class="form-input moment-select" style="padding:6px 10px;width:auto;" onchange="updateMomentColor(this)">
    ${opts.map(o=>`<option value="${o.v}"${o.v===selected?' selected':''}>${o.l}</option>`).join('')}
  </select>`;
}

function updateMomentColor(sel) {
  Object.values(MOMENT_CLASSES).forEach(c=>sel.classList.remove(c));
  const cls = MOMENT_CLASSES[sel.value];
  if (cls) sel.classList.add(cls);
}

// Appliquer les couleurs au chargement
document.querySelectorAll('select.moment-select').forEach(updateMomentColor);

function filterEvents(capteurSel) {
  const capteur = CAPTEURS.find(c=>c.v==capteurSel.value);
  const type = capteur ? capteur.t : null;
  const row = capteurSel.closest('tr');
  const eventSel = row.querySelector('select[name="attend_event[]"]');
  Array.from(eventSel.options).forEach(opt=>{
    if (!opt.value) return;
    const evt=EVENTS.find(e=>e.v==opt.value);
    const hide=type&&evt?evt.t!==type:false;
    opt.hidden=hide; opt.disabled=hide;
  });
  if (eventSel.options[eventSel.selectedIndex]?.hidden) eventSel.value='';
}

function filterAddEvents() {
  const capteurSel=document.getElementById('add_capteur');
  const eventSel=document.getElementById('add_event');
  if (!capteurSel||!eventSel) return;
  const type=capteurSel.options[capteurSel.selectedIndex]?.dataset.type||null;
  Array.from(eventSel.options).forEach(opt=>{
    if (!opt.value){opt.hidden=false;opt.disabled=false;return;}
    const hide=type?opt.dataset.type!==type:false;
    opt.hidden=hide; opt.disabled=hide;
  });
  if (eventSel.options[eventSel.selectedIndex]?.hidden) eventSel.value='';
  eventSel.options[0].text=type?'— événement —':'— sélectionner un capteur —';
}

function addAttendRow() {
  const tr=document.createElement('tr'); tr.className='attend-row';
  tr.innerHTML=`
    <td>${makeSelect('attend_capteur[]',CAPTEURS,'','onchange="filterEvents(this)"')}</td>
    <td>${makeSelect('attend_event[]',EVENTS)}</td>
    <td><select name="attend_obligatoire[]" class="form-input" style="padding:6px 10px;width:auto;">
      <option value="1" selected>Oui</option><option value="0">Non</option>
    </select></td>
    <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-del-sm">×</button></td>`;
  document.getElementById('attend-body').appendChild(tr);
}

function addDecRow() {
  const tr=document.createElement('tr'); tr.className='dec-row';
  tr.innerHTML=`
    <td>${makeMomentSelect('dec_moment[]','on_success')}</td>
    <td>${makeSelect('dec_actionneur[]',ACTIONNEURS)}</td>
    <td>${makeSelect('dec_type[]',ACTION_TYPES)}</td>
    <td><input type="text" name="dec_valeur[]" class="form-input" placeholder="ex : ACCESS GRANTED" style="padding:6px 10px;" maxlength="100"></td>
    <td><button type="button" onclick="removeRow(this)" class="btn btn-sm btn-del-sm">×</button></td>`;
  document.getElementById('dec-body').appendChild(tr);
  // Appliquer la couleur sur la nouvelle sélect
  tr.querySelectorAll('select.moment-select').forEach(updateMomentColor);
}

document.querySelectorAll('select[name="attend_capteur[]"]').forEach(filterEvents);
</script>
</body>
</html>
<?php
function momentSelect(string $name, string $selected): string {
    $opts=[
        ['v'=>'on_enter',  'l'=>"À l'entrée"],
        ['v'=>'on_success','l'=>'Au succès'],
        ['v'=>'on_failure','l'=>"En cas d'échec"],
        ['v'=>'on_hint',   'l'=>'Sur indice'],
    ];
    $html="<select name=\"$name\" class=\"form-input moment-select\" style=\"padding:6px 10px;width:auto;\" onchange=\"updateMomentColor(this)\">";
    foreach ($opts as $o)
        $html.="<option value=\"{$o['v']}\"".($o['v']===$selected?' selected':'').">{$o['l']}</option>";
    return $html."</select>";
}
?>
