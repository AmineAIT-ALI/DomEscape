<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/UserRepository.php';
require_once __DIR__ . '/../partials/flash.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$repo  = new UserRepository();
$users = $repo->listAll();

// Compteurs par rôle
$countByRole = ['participant' => 0, 'superviseur' => 0, 'administrateur' => 0];
$countActive = 0;
foreach ($users as $u) {
    if ($u['actif']) $countActive++;
    if ($u['roles']) {
        foreach (explode(',', $u['roles']) as $r) {
            $r = trim($r);
            if (isset($countByRole[$r])) $countByRole[$r]++;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Utilisateurs — DomEscape Admin</title>
  <style>
    body { background: #080810; color: #e0e0e0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; min-height: 100vh; }
    a { color: #00ff88; }

    .page-wrap { max-width: 1100px; margin: 0 auto; padding: 40px 24px 80px; }

    /* Breadcrumb */
    .breadcrumb-row {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: .72rem;
        color: #444;
        margin-bottom: 28px;
    }
    .breadcrumb-row a { color: #555; text-decoration: none; }
    .breadcrumb-row a:hover { color: #e0e0e0; }
    .breadcrumb-sep { color: #333; }

    /* Header */
    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 28px;
    }
    .page-head h1 { font-size: 1.1rem; font-weight: 700; margin: 0; }
    .page-head p { font-size: .72rem; color: #444; margin: 4px 0 0; }

    /* Summary stats */
    .summary-stats {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 10px;
        margin-bottom: 28px;
    }
    .sum-card {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        padding: 14px 18px;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    .sum-val {
        font-size: 1.4rem;
        font-weight: 700;
        line-height: 1;
        letter-spacing: -.02em;
    }
    .sum-lbl {
        font-size: .65rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #444;
    }
    .sum-card-total  { border-top: 2px solid #333; }
    .sum-card-total  .sum-val { color: #e0e0e0; }
    .sum-card-active { border-top: 2px solid rgba(0,255,136,.5); }
    .sum-card-active .sum-val { color: #00ff88; }
    .sum-card-part   { border-top: 2px solid rgba(0,255,136,.25); }
    .sum-card-part   .sum-val { color: rgba(0,255,136,.7); }
    .sum-card-superv { border-top: 2px solid rgba(96,165,250,.5); }
    .sum-card-superv .sum-val { color: #60a5fa; }
    .sum-card-admin  { border-top: 2px solid rgba(167,139,250,.5); }
    .sum-card-admin  .sum-val { color: #a78bfa; }
    @media (max-width: 768px) { .summary-stats { grid-template-columns: repeat(3, 1fr); } }

    /* Table panel */
    .panel {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        overflow: hidden;
    }

    /* Search */
    .panel-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 16px;
        border-bottom: 1px solid #0a0a14;
        gap: 12px;
    }
    .search-input {
        background: #080810;
        border: 1px solid #1a1a2e;
        color: #e0e0e0;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: .78rem;
        padding: 7px 12px;
        border-radius: 4px;
        outline: none;
        width: 240px;
        transition: border-color .15s;
    }
    .search-input:focus { border-color: #00ff88; }
    .search-input::placeholder { color: #333; }

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

    /* Role badges */
    .badge-role {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: .65rem;
        border: 1px solid;
        margin-right: 3px;
    }
    .badge-participant   { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
    .badge-superviseur   { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
    .badge-administrateur{ color: #a78bfa; border-color: rgba(167,139,250,.3); background: rgba(167,139,250,.06); }

    /* Status dot */
    .status-dot {
        display: inline-block;
        width: 6px; height: 6px;
        border-radius: 50%;
        margin-right: 5px;
        vertical-align: middle;
    }

    /* Edit button */
    .btn-edit {
        color: #666;
        border-color: #1a1a2e;
        font-size: .72rem;
        padding: 5px 12px;
    }
    .btn-edit:hover { border-color: #00ff88; color: #00ff88; }

    @media (max-width: 768px) {
        th:nth-child(6), td:nth-child(6),
        th:nth-child(7), td:nth-child(7) { display: none; }
    }
  </style>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">
  <?php flashRender(); ?>

  <div class="breadcrumb-row">
    <a href="/domescape/admin/dashboard.php">Administration</a>
    <span class="breadcrumb-sep">/</span>
    <span>Utilisateurs</span>
  </div>

  <div class="page-head">
    <div>
      <h1>Utilisateurs</h1>
      <p><?= count($users) ?> compte<?= count($users) !== 1 ? 's' : '' ?> enregistré<?= count($users) !== 1 ? 's' : '' ?></p>
    </div>
  </div>

  <div class="summary-stats">
    <div class="sum-card sum-card-total">
      <span class="sum-val"><?= count($users) ?></span>
      <span class="sum-lbl">Total</span>
    </div>
    <div class="sum-card sum-card-active">
      <span class="sum-val"><?= $countActive ?></span>
      <span class="sum-lbl">Actifs</span>
    </div>
    <div class="sum-card sum-card-part">
      <span class="sum-val"><?= $countByRole['participant'] ?></span>
      <span class="sum-lbl">Participants</span>
    </div>
    <div class="sum-card sum-card-superv">
      <span class="sum-val"><?= $countByRole['superviseur'] ?></span>
      <span class="sum-lbl">Superviseurs</span>
    </div>
    <div class="sum-card sum-card-admin">
      <span class="sum-val"><?= $countByRole['administrateur'] ?></span>
      <span class="sum-lbl">Admins</span>
    </div>
  </div>

  <div class="panel">
    <div class="panel-toolbar">
      <input type="text" class="search-input" id="searchInput"
             placeholder="Filtrer par nom ou e-mail…" oninput="filterTable(this.value)">
      <span style="font-size:.68rem;color:#444;" id="countLabel"><?= count($users) ?> utilisateur<?= count($users) > 1 ? 's' : '' ?></span>
    </div>

    <div style="overflow-x:auto;">
    <table id="usersTable">
      <thead>
        <tr>
          <th>#</th>
          <th>Nom</th>
          <th>E-mail</th>
          <th>Rôles</th>
          <th>Statut</th>
          <th>Créé le</th>
          <th>Dernière cnx</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <?php $roleList = $u['roles'] ? explode(',', $u['roles']) : []; ?>
          <tr data-search="<?= strtolower(htmlspecialchars($u['nom'] . ' ' . $u['email'], ENT_QUOTES, 'UTF-8')) ?>">
            <td style="color:#333;"><?= (int)$u['id'] ?></td>
            <td style="color:#ccc;font-weight:500;"><?= htmlspecialchars($u['nom'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:#666;"><?= htmlspecialchars($u['email'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>
              <?php if (empty($roleList)): ?>
                <span style="color:#333;font-size:.68rem;">—</span>
              <?php else: ?>
                <?php foreach ($roleList as $r): $r = trim($r); ?>
                  <span class="badge-role badge-<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>
                  </span>
                <?php endforeach; ?>
              <?php endif; ?>
            </td>
            <td>
              <span class="status-dot" style="background:<?= $u['actif'] ? '#00ff88' : '#ff4444' ?>;"></span>
              <span style="font-size:.72rem;color:<?= $u['actif'] ? '#00ff88' : '#ff4444' ?>;">
                <?= $u['actif'] ? 'Actif' : 'Désactivé' ?>
              </span>
            </td>
            <td style="color:#444;font-size:.72rem;"><?= htmlspecialchars($u['cree_le'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:#444;font-size:.72rem;">
              <?= $u['derniere_connexion'] ? htmlspecialchars($u['derniere_connexion'], ENT_QUOTES, 'UTF-8') : '—' ?>
            </td>
            <td>
              <a href="/domescape/admin/utilisateur_edit.php?id=<?= (int)$u['id'] ?>" class="btn btn-edit">Modifier →</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

</div>

<script>
function filterTable(query) {
    const q = query.toLowerCase();
    const rows = document.querySelectorAll('#usersTable tbody tr');
    let visible = 0;
    rows.forEach(row => {
        const match = row.dataset.search.includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    document.getElementById('countLabel').textContent =
        visible + ' utilisateur' + (visible > 1 ? 's' : '');
}
</script>
</body>
</html>
