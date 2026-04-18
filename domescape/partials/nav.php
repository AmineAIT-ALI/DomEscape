<?php
// ============================================================
// DomEscape — Navigation principale partagée
// Deux états : visiteur non connecté / utilisateur connecté
// Requiert Auth::init() appelé en amont (via RoleGuard)
// ============================================================

$_nav_user    = Auth::user();
$_nav_roles   = Auth::buildHierarchy(Auth::roles());
$_nav_isAdmin = in_array(ROLE_ADMINISTRATEUR, $_nav_roles, true);
$_nav_isSuperv= in_array(ROLE_SUPERVISEUR,    $_nav_roles, true);
$_nav_uri     = $_SERVER['REQUEST_URI'] ?? '';

function nav_active(string $path): string {
    global $_nav_uri;
    return (strpos($_nav_uri, $path) !== false) ? 'nav-active' : '';
}
?>
<link rel="stylesheet" href="/domescape/assets/css/components.css">
<style>
:root {
  --accent:       #00ff88;
  --accent-dark:  #00cc6a;
  --bg-base:      #080810;
  --bg-card:      #0f0f18;
  --bg-input:     #0d0d16;
  --border:       #1a1a2e;
  --border-dim:   #111;
  --text:         #e0e0e0;
  --muted:        #555;
  --dim:          #333;
  --blue:         #60a5fa;
  --purple:       #a78bfa;
  --red:          #ff4444;
  --yellow:       #f0c040;
}
.dn-nav {
    background: #0a0a0f;
    border-bottom: 1px solid #1a1a2e;
    height: 56px;
    display: flex;
    align-items: center;
    padding: 0 24px;
    position: sticky;
    top: 0;
    z-index: 1000;
    font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
}
.dn-nav-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    max-width: 1200px;
    margin: 0 auto;
}
.dn-brand {
    color: #00ff88;
    font-weight: 700;
    font-size: .95rem;
    text-decoration: none;
    letter-spacing: .04em;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.dn-brand-dot {
    width: 8px;
    height: 8px;
    background: #00ff88;
    border-radius: 50%;
    box-shadow: 0 0 8px #00ff88;
}
.dn-links {
    display: flex;
    align-items: center;
    gap: 4px;
}
.dn-links a {
    color: #888;
    text-decoration: none;
    font-size: .8rem;
    padding: 6px 12px;
    border-radius: 4px;
    transition: color .15s, background .15s;
    letter-spacing: .02em;
}
.dn-links a:hover, .dn-links a.nav-active {
    color: #e0e0e0;
    background: #111;
}
.dn-links a.nav-active {
    color: #00ff88;
}
.dn-right {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-shrink: 0;
}
/* Badge rôle */
.dn-role {
    font-size: .68rem;
    padding: 2px 8px;
    border-radius: 3px;
    border: 1px solid;
    font-family: 'Courier New', monospace;
    letter-spacing: .05em;
}
.dn-role-participant   { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
.dn-role-superviseur   { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
.dn-role-administrateur{ color: #a78bfa; border-color: rgba(167,139,250,.3); background: rgba(167,139,250,.06); }
/* Nom utilisateur */
.dn-username {
    font-size: .78rem;
    color: #555;
    max-width: 120px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
/* Boutons auth */
.dn-btn-login {
    color: #aaa;
    text-decoration: none;
    font-size: .8rem;
    padding: 6px 14px;
    border: 1px solid #2a2a3a;
    border-radius: 4px;
    transition: border-color .15s, color .15s;
}
.dn-btn-login:hover { border-color: #555; color: #e0e0e0; }
.dn-btn-register {
    color: #0d0d0d;
    background: #00ff88;
    text-decoration: none;
    font-size: .8rem;
    font-weight: 700;
    padding: 6px 14px;
    border-radius: 4px;
    transition: background .15s;
}
.dn-btn-register:hover { background: #00cc6a; }
.dn-btn-logout {
    color: #555;
    text-decoration: none;
    font-size: .78rem;
    padding: 6px 12px;
    border: 1px solid transparent;
    border-radius: 4px;
    transition: all .15s;
}
.dn-btn-logout:hover { color: #ff4444; border-color: rgba(255,68,68,.3); }
/* Séparateur vertical */
.dn-sep {
    width: 1px;
    height: 18px;
    background: #1a1a2e;
    margin: 0 4px;
}
</style>

<nav class="dn-nav">
  <div class="dn-nav-inner">

    <!-- Logo -->
    <a href="/domescape/public/<?= $_nav_user ? 'tableau-de-bord.php' : 'connexion.php' ?>" class="dn-brand">
      <span class="dn-brand-dot"></span>DomEscape
    </a>

    <!-- Liens contextuels -->
    <div class="dn-links">
      <?php if ($_nav_user): ?>

        <a href="/domescape/public/index.php"
           class="<?= nav_active('index.php') ?>">Jouer</a>

        <?php if ($_nav_isSuperv): ?>
          <a href="/domescape/public/gamemaster.php"
             class="<?= nav_active('gamemaster.php') ?>">Supervision</a>
          <a href="/domescape/public/demandes.php"
             class="<?= nav_active('demandes.php') ?>">Demandes</a>
          <a href="/domescape/public/stats.php"
             class="<?= nav_active('stats.php') ?>">Stats</a>
          <a href="/domescape/public/historique.php"
             class="<?= nav_active('historique.php') ?>">Historique</a>
        <?php endif; ?>

        <?php if ($_nav_isAdmin): ?>
          <a href="/domescape/admin/dashboard.php"
             class="<?= nav_active('admin/dashboard') ?>">Administration</a>
          <a href="/domescape/admin/scenarios.php"
             class="<?= nav_active('scenarios') ?>">Scénarios</a>
          <a href="/domescape/admin/utilisateurs.php"
             class="<?= nav_active('utilisateurs') ?>">Utilisateurs</a>
        <?php endif; ?>

      <?php else: ?>

        <a href="/domescape/website/demo.html">Démonstration</a>
        <a href="/domescape/website/platform.html">Plateforme</a>
        <a href="/domescape/website/architecture.html">Architecture</a>
        <a href="/domescape/website/docs.html">Documentation</a>

      <?php endif; ?>
    </div>

    <!-- Partie droite -->
    <div class="dn-right">
      <?php if ($_nav_user): ?>

        <span class="dn-username"><?= htmlspecialchars($_nav_user['nom'], ENT_QUOTES, 'UTF-8') ?></span>

        <?php
        $primaryRole = Auth::roles()[0] ?? null;
        if ($primaryRole): ?>
          <span class="dn-role dn-role-<?= htmlspecialchars($primaryRole, ENT_QUOTES, 'UTF-8') ?>">
            <?= htmlspecialchars($primaryRole, ENT_QUOTES, 'UTF-8') ?>
          </span>
        <?php endif; ?>

        <div class="dn-sep"></div>

        <a href="/domescape/public/profil.php"
           class="dn-btn-login <?= nav_active('profil.php') ?>">Profil</a>
        <a href="/domescape/public/deconnexion.php" class="dn-btn-logout">Déconnexion</a>

      <?php else: ?>

        <a href="/domescape/public/connexion.php" class="dn-btn-login">Connexion</a>
        <a href="/domescape/public/inscription.php" class="dn-btn-register">Créer un compte →</a>

      <?php endif; ?>
    </div>

  </div>
</nav>
