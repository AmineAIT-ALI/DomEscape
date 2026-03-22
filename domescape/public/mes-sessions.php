<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/UserRepository.php';
require_once __DIR__ . '/../partials/flash.php';

RoleGuard::requireLogin();

$user     = Auth::user();
$repo     = new UserRepository();
$sessions = $repo->getSessionsForUser($user['id']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mes sessions — DomEscape</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
    a { color: #00ff88; }

    .page-wrap { max-width: 1000px; margin: 0 auto; padding: 40px 24px 80px; }

    .breadcrumb-row {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: .72rem;
        color: #444;
        margin-bottom: 32px;
    }
    .breadcrumb-row a { color: #555; text-decoration: none; }
    .breadcrumb-row a:hover { color: #e0e0e0; }
    .breadcrumb-sep { color: #333; }

    .page-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 28px;
    }
    .page-head h1 { font-size: 1.1rem; font-weight: 700; margin: 0; }
    .session-count { font-size: .72rem; color: #444; }

    /* Summary stats */
    .summary-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 28px;
    }
    .sum-box {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        padding: 14px 16px;
        text-align: center;
    }
    .sum-value { font-size: 1.2rem; font-weight: 700; color: #00ff88; margin-bottom: 3px; }
    .sum-value.red   { color: #ff4444; }
    .sum-value.blue  { color: #60a5fa; }
    .sum-value.white { color: #e0e0e0; }
    .sum-label { font-size: .62rem; color: #444; letter-spacing: .06em; text-transform: uppercase; }

    /* Table */
    .sessions-table-wrap {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        overflow: hidden;
    }
    table { width: 100%; border-collapse: collapse; font-size: .8rem; }
    thead tr { border-bottom: 1px solid #111; }
    th {
        font-size: .65rem;
        letter-spacing: .1em;
        color: #444;
        text-transform: uppercase;
        padding: 12px 16px;
        text-align: left;
        font-weight: normal;
    }
    td { padding: 13px 16px; border-bottom: 1px solid #0a0a14; vertical-align: middle; }
    tbody tr:last-child td { border-bottom: none; }
    tbody tr:hover td { background: rgba(255,255,255,.02); }

    /* Status badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: .68rem;
        padding: 3px 9px;
        border-radius: 3px;
        border: 1px solid;
    }
    .status-gagnee   { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
    .status-terminee { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
    .status-en_cours { color: #ffcc00; border-color: rgba(255,204,0,.3); background: rgba(255,204,0,.06); }
    .status-abandonnee { color: #ff6666; border-color: rgba(255,68,68,.3); background: rgba(255,68,68,.06); }
    .status-dot { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }

    /* Empty state */
    .empty-state {
        text-align: center;
        padding: 80px 24px;
    }
    .empty-icon { font-size: 2.5rem; opacity: .15; margin-bottom: 16px; }
    .empty-state p { font-size: .82rem; color: #444; margin-bottom: 20px; }
    .btn-play {
        display: inline-block;
        background: #00ff88;
        color: #080810;
        font-weight: 700;
        font-size: .8rem;
        padding: 10px 22px;
        border-radius: 4px;
        text-decoration: none;
    }
    .btn-play:hover { background: #00cc6a; color: #080810; }

    @media (max-width: 600px) {
        .summary-row { grid-template-columns: repeat(2, 1fr); }
        th:nth-child(4), td:nth-child(4),
        th:nth-child(5), td:nth-child(5) { display: none; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">
  <?php flashRender(); ?>

  <div class="breadcrumb-row">
    <a href="/domescape/public/tableau-de-bord.php">Tableau de bord</a>
    <span class="breadcrumb-sep">/</span>
    <a href="/domescape/public/profil.php">Profil</a>
    <span class="breadcrumb-sep">/</span>
    <span>Sessions</span>
  </div>

  <div class="page-head">
    <h1>Mes sessions</h1>
    <span class="session-count"><?= count($sessions) ?> session<?= count($sessions) !== 1 ? 's' : '' ?></span>
  </div>

  <?php if (empty($sessions)): ?>

    <div class="empty-state">
      <div class="empty-icon">&#9632;</div>
      <p>Vous n'avez pas encore joué.<br>Lancez votre première session dès maintenant.</p>
      <a href="/domescape/public/index.php" class="btn-play">Démarrer une partie →</a>
    </div>

  <?php else:
    // Calcul des stats
    $total    = count($sessions);
    $victoires = 0;
    $meilleurScore = 0;
    $totalErreurs  = 0;
    foreach ($sessions as $s) {
        if (($s['statut'] ?? '') === 'gagnee') $victoires++;
        $meilleurScore = max($meilleurScore, (int)($s['score'] ?? 0));
        $totalErreurs += (int)($s['nb_erreurs'] ?? 0);
    }
  ?>

    <div class="summary-row">
      <div class="sum-box">
        <div class="sum-value white"><?= $total ?></div>
        <div class="sum-label">Sessions</div>
      </div>
      <div class="sum-box">
        <div class="sum-value"><?= $victoires ?></div>
        <div class="sum-label">Victoires</div>
      </div>
      <div class="sum-box">
        <div class="sum-value blue"><?= $meilleurScore ?></div>
        <div class="sum-label">Meilleur score</div>
      </div>
      <div class="sum-box">
        <div class="sum-value red"><?= $totalErreurs ?></div>
        <div class="sum-label">Erreurs totales</div>
      </div>
    </div>

    <div class="sessions-table-wrap">
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Scénario</th>
            <th>Profil joueur</th>
            <th>Score</th>
            <th>Erreurs</th>
            <th>Statut</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sessions as $s):
            $statut = $s['statut'] ?? 'terminee';
            $statusLabels = [
                'gagnee'    => 'Victoire',
                'terminee'  => 'Terminée',
                'en_cours'  => 'En cours',
                'abandonnee'=> 'Abandonnée',
            ];
            $statusLabel = $statusLabels[$statut] ?? ucfirst($statut);
          ?>
          <tr>
            <td style="color:#333;"><?= (int)$s['id'] ?></td>
            <td style="color:#ccc;"><?= htmlspecialchars($s['scenario_nom'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:#666;"><?= htmlspecialchars($s['joueur_nom'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:<?= (int)$s['score'] > 0 ? '#00ff88' : '#555' ?>; font-weight:<?= (int)$s['score'] > 0 ? '700' : 'normal' ?>;">
              <?= (int)$s['score'] ?>
            </td>
            <td style="color:<?= (int)$s['nb_erreurs'] > 0 ? '#ff6666' : '#555' ?>;">
              <?= (int)$s['nb_erreurs'] ?>
            </td>
            <td>
              <span class="status-badge status-<?= htmlspecialchars($statut, ENT_QUOTES, 'UTF-8') ?>">
                <span class="status-dot"></span>
                <?= $statusLabel ?>
              </span>
            </td>
            <td style="color:#444;font-size:.72rem;">
              <?= htmlspecialchars($s['debut'], ENT_QUOTES, 'UTF-8') ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="text-align:center; margin-top:24px;">
      <a href="/domescape/public/index.php" style="font-size:.78rem; color:#555;">Jouer une nouvelle partie →</a>
    </div>

  <?php endif; ?>

</div>

</body>
</html>
