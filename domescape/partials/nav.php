<?php
// DomEscape — Navigation principale partagée
// Deux états : visiteur non connecté / utilisateur connecté
// Requiert Auth::init() appelé en amont (via RoleGuard)

$_nav_user    = Auth::user();
$_nav_isAdmin = Auth::isAdmin();
$_nav_uri     = $_SERVER['REQUEST_URI'] ?? '';

function nav_active(string $path): string {
    global $_nav_uri;
    return (strpos($_nav_uri, $path) !== false) ? 'nav-active' : '';
}
?>
<nav class="dn-nav">
  <div class="dn-nav-inner">

    <a href="/domescape/public/<?= $_nav_user ? 'index.php' : 'connexion.php' ?>" class="dn-brand">
      <img src="/domescape/assets/logo-icon.svg" alt="DomEscape">
      DomEscape
    </a>

    <div class="dn-links">
      <?php if ($_nav_user): ?>

        <a href="/domescape/public/index.php"
           class="<?= nav_active('index.php') ?>">Jouer</a>

        <a href="/domescape/public/mes-parties.php"
           class="<?= nav_active('mes-parties') ?>">Historique</a>

        <?php if ($_nav_isAdmin): ?>
          <a href="/domescape/public/gamemaster.php"
             class="<?= nav_active('gamemaster.php') ?>">Supervision</a>
          <a href="/domescape/admin/scenarios.php"
             class="<?= nav_active('scenarios') ?>">Scénarios</a>
          <a href="/domescape/admin/dashboard.php"
             class="<?= nav_active('admin/dashboard') ?>">Administration</a>
        <?php endif; ?>

      <?php endif; ?>
    </div>

    <div class="dn-right">
      <?php if ($_nav_user): ?>

        <span class="dn-username"><?= htmlspecialchars($_nav_user['nom'], ENT_QUOTES, 'UTF-8') ?></span>

        <span class="dn-role <?= $_nav_isAdmin ? 'dn-role-administrateur' : 'dn-role-participant' ?>">
          <?= $_nav_isAdmin ? 'admin' : 'joueur' ?>
        </span>

        <div class="dn-sep"></div>

        <a href="/domescape/public/deconnexion.php" class="dn-btn-logout">Déconnexion</a>

      <?php else: ?>

        <a href="/domescape/public/connexion.php" class="dn-btn-login">Connexion</a>

      <?php endif; ?>
    </div>

  </div>
</nav>
