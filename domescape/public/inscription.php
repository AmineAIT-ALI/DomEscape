<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/UserRepository.php';

Auth::init();

if (Auth::check()) {
    header('Location: ' . AUTH_DASHBOARD_URL);
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom      = trim($_POST['nom']      ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    if ($nom === '' || $email === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Adresse e-mail invalide.';
    } elseif (strlen($password) < 8) {
        $error = 'Le mot de passe doit contenir au moins 8 caractères.';
    } elseif ($password !== $confirm) {
        $error = 'Les mots de passe ne correspondent pas.';
    } else {
        $repo = new UserRepository();

        if ($repo->emailExists($email)) {
            $error = 'Cette adresse e-mail est déjà utilisée.';
        } else {
            $repo->create($nom, $email, $password);

            // Connexion automatique
            Auth::login($email, $password);
            header('Location: ' . AUTH_DASHBOARD_URL);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Créer un compte — DomEscape</title>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
    <link rel="stylesheet" href="/domescape/assets/css/auth.css">
</head>
<body>

<nav class="auth-nav">
  <a href="/domescape/website/index.html" class="auth-nav-brand">
    <img src="/domescape/assets/logo-icon.svg" alt="DomEscape" style="height:26px;width:auto;">
    DomEscape
  </a>
  <a href="/domescape/public/connexion.php" class="auth-nav-back">Déjà inscrit ? Se connecter</a>
</nav>

<div class="auth-wrap">
  <div class="auth-card">

    <div class="auth-head">
      <div class="auth-head-icon"><i data-lucide="user-plus" style="width:20px;height:20px;"></i></div>
      <h1>Créer un compte</h1>
      <p>Rejoignez la plateforme DomEscape.</p>
    </div>

    <div style="text-align:center;">
      <span class="role-chip">
        <span class="role-chip-dot"></span>
        Rôle attribué : joueur
      </span>
    </div>

    <form method="post" action="" class="auth-form">

      <?php if ($error !== ''): ?>
        <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <div class="form-field">
        <label for="nom">Nom complet</label>
        <input type="text" id="nom" name="nom"
               value="<?= htmlspecialchars($_POST['nom'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               autocomplete="name" required>
      </div>

      <div class="form-field">
        <label for="email">Adresse e-mail</label>
        <input type="email" id="email" name="email"
               value="<?= htmlspecialchars($_POST['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               autocomplete="email" required>
      </div>

      <div class="form-field">
        <label for="password">Mot de passe</label>
        <input type="password" id="password" name="password"
               autocomplete="new-password" required oninput="checkStrength(this.value)">
        <div class="pw-strength">
          <div class="pw-bar"><div class="pw-bar-fill" id="pwFill"></div></div>
          <div class="pw-label" id="pwLabel">Entrez un mot de passe</div>
        </div>
      </div>

      <div class="form-field">
        <label for="password_confirm">Confirmer le mot de passe</label>
        <input type="password" id="password_confirm" name="password_confirm"
               autocomplete="new-password" required>
      </div>

      <button type="submit" class="btn btn-primary btn-block btn-submit">Créer mon compte →</button>
    </form>

    <div class="auth-footer">
      Déjà inscrit ? <a href="/domescape/public/connexion.php">Se connecter →</a>
    </div>

  </div>
</div>

<script>
function checkStrength(pw) {
    const fill  = document.getElementById('pwFill');
    const label = document.getElementById('pwLabel');
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const levels = [
        { pct: '0%',   color: '#111',    text: 'Entrez un mot de passe' },
        { pct: '20%',  color: '#ff4444', text: 'Très faible' },
        { pct: '40%',  color: '#ff8800', text: 'Faible' },
        { pct: '60%',  color: '#ffcc00', text: 'Moyen' },
        { pct: '80%',  color: '#88ff44', text: 'Fort' },
        { pct: '100%', color: '#00ff88', text: 'Très fort' },
    ];
    const l = levels[Math.min(score, 5)];
    fill.style.width     = l.pct;
    fill.style.background = l.color;
    label.textContent    = l.text;
    label.style.color    = l.color;
}
</script>
<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
