<?php
require_once __DIR__ . '/../core/Auth.php';

Auth::init();

// Déjà connecté → tableau de bord
if (Auth::check()) {
    header('Location: ' . AUTH_DASHBOARD_URL);
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? AUTH_DASHBOARD_URL;
if (substr($redirect, 0, 1) !== '/' || substr($redirect, 0, 2) === '//') {
    $redirect = AUTH_DASHBOARD_URL;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        $result = Auth::login($email, $password);
        if ($result === true) {
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Connexion — DomEscape</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
    <link rel="stylesheet" href="/domescape/assets/css/auth.css">
</head>
<body>

<nav class="auth-nav">
  <a href="/domescape/website/index.html" class="auth-nav-brand">
    <img src="/domescape/assets/logo-icon.svg" alt="DomEscape" style="height:26px;width:auto;">
    DomEscape
  </a>
  <a href="/domescape/website/index.html" class="auth-nav-back">← Retour au site</a>
</nav>

<div class="auth-wrap">
  <div class="auth-card">

    <div class="auth-head">
      <div class="auth-head-icon"><i data-lucide="lock-keyhole" style="width:20px;height:20px;"></i></div>
      <h1>Connexion</h1>
      <p>Accédez à votre espace DomEscape.</p>
    </div>

    <form method="post" action="" class="auth-form">

      <?php if ($error !== ''): ?>
        <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="form-field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               autocomplete="email" required>
      </div>

      <div class="form-field">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password"
               autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-submit">Se connecter →</button>
    </form>

    <hr class="auth-divider">

    <div class="auth-hints">
      <div class="auth-hint">
        <span class="auth-hint-icon"><i data-lucide="cpu" style="width:13px;height:13px;"></i></span>
        Jeu d'évasion physique piloté par capteurs Z-Wave
      </div>
      <div class="auth-hint">
        <span class="auth-hint-icon"><i data-lucide="users" style="width:13px;height:13px;"></i></span>
        Deux profils d'accès : joueur et administrateur
      </div>
      <div class="auth-hint">
        <span class="auth-hint-icon"><i data-lucide="activity" style="width:13px;height:13px;"></i></span>
        Suivi en temps réel des sessions
      </div>
    </div>

    <div class="auth-footer" style="margin-top:32px;">
      Pas encore de compte ? <a href="/domescape/public/inscription.php">Créer un compte →</a>
    </div>

  </div>
</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
