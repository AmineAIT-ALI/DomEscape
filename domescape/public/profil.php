<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/UserRepository.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../partials/flash.php';

RoleGuard::requireLogin();

$user     = Auth::user();
$repo     = new UserRepository();
$userInfo = $repo->findById($user['id']);
$roles    = $repo->getRoles($user['id']);
$pdo      = getDB();

// Stats joueur
$stats = $pdo->prepare("
    SELECT
        COUNT(*)                               AS total,
        SUM(statut_session = 'gagnee')         AS victoires,
        MAX(score)                             AS meilleur_score,
        COALESCE(SUM(nb_erreurs), 0)           AS total_erreurs,
        COALESCE(MIN(duree_secondes), 0)       AS meilleur_temps
    FROM session se
    JOIN joueur j ON se.id_joueur = j.id_joueur
    WHERE j.id_utilisateur = ?
");
$stats->execute([$user['id']]);
$st = $stats->fetch();

// Changement de mot de passe
$pwError   = '';
$pwSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_action']) && $_POST['_action'] === 'change_password') {
    $current  = $_POST['current_password']  ?? '';
    $newpw    = $_POST['new_password']       ?? '';
    $confirm  = $_POST['confirm_password']   ?? '';

    if ($current === '' || $newpw === '' || $confirm === '') {
        $pwError = 'Veuillez remplir tous les champs.';
    } elseif (strlen($newpw) < 8) {
        $pwError = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    } elseif ($newpw !== $confirm) {
        $pwError = 'Les mots de passe ne correspondent pas.';
    } else {
        // Vérifier le mot de passe actuel
        $fresh = $repo->findById($user['id']);
        if (!password_verify($current, $fresh['mot_de_passe'])) {
            $pwError = 'Mot de passe actuel incorrect.';
        } else {
            $repo->update($user['id'], ['mot_de_passe' => password_hash($newpw, PASSWORD_DEFAULT)]);
            $pwSuccess = 'Mot de passe mis à jour avec succès.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mon profil — DomEscape</title>
  <link href="/domescape/assets/vendor/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
    a { color: #00ff88; }

    .page-wrap { max-width: 760px; margin: 0 auto; padding: 40px 24px 80px; }

    /* Breadcrumb */
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

    /* Profile header */
    .profile-head {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 36px;
    }
    .profile-avatar {
        width: 56px; height: 56px;
        background: #0f0f18;
        border: 1px solid #1a1a2e;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        color: #333;
        flex-shrink: 0;
    }
    .profile-head-info {}
    .profile-head-name {
        font-size: 1.1rem;
        font-weight: 700;
        color: #e0e0e0;
        margin-bottom: 4px;
    }
    .profile-head-email { font-size: .78rem; color: #555; }

    /* Stats row */
    .stats-row {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 32px;
    }
    .stat-box {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        padding: 16px;
        text-align: center;
    }
    .stat-value {
        font-size: 1.3rem;
        font-weight: 700;
        color: #00ff88;
        margin-bottom: 4px;
    }
    .stat-label { font-size: .65rem; color: #444; letter-spacing: .06em; text-transform: uppercase; }

    /* Section */
    .section-label {
        font-size: .68rem;
        letter-spacing: .12em;
        color: #444;
        text-transform: uppercase;
        margin-bottom: 14px;
    }
    .info-card {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        margin-bottom: 20px;
    }
    .info-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 13px 20px;
        border-bottom: 1px solid #0a0a14;
    }
    .info-row:last-child { border-bottom: none; }
    .info-label { font-size: .72rem; color: #555; }
    .info-value { font-size: .82rem; color: #ccc; }

    /* Role badges */
    .badge-role {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 3px;
        font-size: .68rem;
        border: 1px solid;
        margin-right: 6px;
    }
    .badge-joueur        { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
    .badge-superviseur   { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
    .badge-administrateur{ color: #a78bfa; border-color: rgba(167,139,250,.3); background: rgba(167,139,250,.06); }

    /* Password form */
    .pw-card {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        padding: 24px;
        margin-bottom: 20px;
    }
    .pw-card h6 { color: #888; font-size: .8rem; margin-bottom: 20px; }
    .form-label { font-size: .72rem; color: #666; margin-bottom: 5px; }
    .form-control {
        background: #080810;
        border: 1px solid #1a1a2e;
        color: #e0e0e0;
        font-family: 'Courier New', monospace;
        font-size: .85rem;
    }
    .form-control:focus {
        background: #080810;
        border-color: #00ff88;
        color: #e0e0e0;
        box-shadow: none;
    }
    .btn-save {
        background: transparent;
        border: 1px solid #00ff88;
        color: #00ff88;
        font-family: 'Courier New', monospace;
        font-size: .8rem;
        padding: 8px 20px;
        border-radius: 4px;
        transition: background .15s, color .15s;
    }
    .btn-save:hover { background: #00ff88; color: #080810; }
    .alert-success-custom {
        background: rgba(0,255,136,.06);
        border: 1px solid rgba(0,255,136,.2);
        color: #00ff88;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: .78rem;
    }
    .alert-error-custom {
        background: rgba(255,68,68,.06);
        border: 1px solid rgba(255,68,68,.25);
        color: #ff6666;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: .78rem;
    }

    /* Quick links */
    .quick-links { display: flex; gap: 10px; flex-wrap: wrap; }
    .quick-link {
        padding: 8px 16px;
        border: 1px solid #1a1a2e;
        border-radius: 4px;
        font-size: .78rem;
        color: #888;
        text-decoration: none;
        transition: border-color .15s, color .15s;
    }
    .quick-link:hover { border-color: #555; color: #e0e0e0; }

    @media (max-width: 600px) {
        .stats-row { grid-template-columns: repeat(2, 1fr); }
        .profile-head { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">
  <?php flashRender(); ?>

  <!-- Breadcrumb -->
  <div class="breadcrumb-row">
    <a href="/domescape/public/tableau-de-bord.php">Tableau de bord</a>
    <span class="breadcrumb-sep">/</span>
    <span>Profil</span>
  </div>

  <!-- Profile header -->
  <div class="profile-head">
    <div class="profile-avatar"><i data-lucide="user" style="width:28px;height:28px;"></i></div>
    <div class="profile-head-info">
      <div class="profile-head-name"><?= htmlspecialchars($userInfo['nom'], ENT_QUOTES, 'UTF-8') ?></div>
      <div class="profile-head-email"><?= htmlspecialchars($userInfo['email'], ENT_QUOTES, 'UTF-8') ?></div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-row">
    <div class="stat-box">
      <div class="stat-value"><?= (int)$st['total'] ?></div>
      <div class="stat-label">Sessions</div>
    </div>
    <div class="stat-box">
      <div class="stat-value"><?= (int)$st['victoires'] ?></div>
      <div class="stat-label">Victoires</div>
    </div>
    <div class="stat-box">
      <div class="stat-value"><?= $st['meilleur_score'] ?? 0 ?></div>
      <div class="stat-label">Meilleur score</div>
    </div>
    <div class="stat-box">
      <div class="stat-value"><?= (int)$st['total_erreurs'] ?></div>
      <div class="stat-label">Erreurs totales</div>
    </div>
  </div>

  <!-- Informations compte -->
  <div class="section-label">Informations du compte</div>
  <div class="info-card">
    <div class="info-row">
      <span class="info-label">Nom</span>
      <span class="info-value"><?= htmlspecialchars($userInfo['nom'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Adresse e-mail</span>
      <span class="info-value"><?= htmlspecialchars($userInfo['email'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Membre depuis</span>
      <span class="info-value"><?= htmlspecialchars($userInfo['cree_le'], ENT_QUOTES, 'UTF-8') ?></span>
    </div>
    <div class="info-row">
      <span class="info-label">Dernière connexion</span>
      <span class="info-value">
        <?= $userInfo['derniere_connexion']
            ? htmlspecialchars($userInfo['derniere_connexion'], ENT_QUOTES, 'UTF-8')
            : '—' ?>
      </span>
    </div>
    <div class="info-row">
      <span class="info-label">Statut</span>
      <span class="info-value" style="color:<?= $userInfo['actif'] ? '#00ff88' : '#ff4444' ?>">
        <?= $userInfo['actif'] ? '● Actif' : '● Désactivé' ?>
      </span>
    </div>
  </div>

  <!-- Rôles -->
  <div class="section-label">Rôles et permissions</div>
  <div class="info-card">
    <div class="info-row" style="flex-wrap:wrap; gap:8px;">
      <?php if (empty($roles)): ?>
        <span style="color:#444;font-size:.78rem;">Aucun rôle assigné.</span>
      <?php else: ?>
        <?php foreach ($roles as $r): ?>
          <span class="badge-role badge-<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>
          </span>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Mot de passe -->
  <div class="section-label" style="margin-top:32px;">Modifier le mot de passe</div>
  <div class="pw-card">

    <?php if ($pwSuccess): ?>
      <div class="alert-success-custom mb-3"><?= htmlspecialchars($pwSuccess, ENT_QUOTES, 'UTF-8') ?></div>
    <?php elseif ($pwError): ?>
      <div class="alert-error-custom mb-3"><?= htmlspecialchars($pwError, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="_action" value="change_password">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label" for="current_password">Mot de passe actuel</label>
          <input type="password" id="current_password" name="current_password" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="new_password">Nouveau mot de passe</label>
          <input type="password" id="new_password" name="new_password" class="form-control" required>
        </div>
        <div class="col-md-4">
          <label class="form-label" for="confirm_password">Confirmer</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
        </div>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn-save">Mettre à jour le mot de passe →</button>
      </div>
    </form>
  </div>

  <!-- Quick links -->
  <div class="section-label" style="margin-top:32px;">Raccourcis</div>
  <div class="quick-links">
    <a href="/domescape/public/mes-sessions.php" class="quick-link">Mes sessions →</a>
    <a href="/domescape/public/index.php" class="quick-link">Jouer →</a>
    <a href="/domescape/public/tableau-de-bord.php" class="quick-link">Tableau de bord</a>
  </div>

</div>

<script src="/domescape/assets/vendor/bootstrap.bundle.min.js"></script>
<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
