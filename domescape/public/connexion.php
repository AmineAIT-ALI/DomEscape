<?php
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../core/Csrf.php';

Auth::init();

// Déjà connecté → tableau de bord
if (Auth::check()) {
    header('Location: ' . AUTH_DASHBOARD_URL);
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? AUTH_DASHBOARD_URL;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

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
  <style>
    :root {
      --accent: #00ff88; --accent-dark: #00cc6a;
      --bg-base: #080810; --bg-card: #0f0f18; --bg-input: #0d0d16;
      --border: #1a1a2e; --border-dim: #111;
      --text: #e0e0e0; --muted: #555; --dim: #333;
    }
    *, *::before, *::after { box-sizing: border-box; }
    body {
        margin: 0;
        background: #080810;
        color: #e0e0e0;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }

    /* Minimal branded header */
    .auth-nav {
        height: 52px;
        display: flex;
        align-items: center;
        padding: 0 24px;
        border-bottom: 1px solid #111;
        flex-shrink: 0;
    }
    .auth-nav-brand {
        color: #00ff88;
        font-weight: 700;
        font-size: .88rem;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 7px;
    }
    .auth-nav-dot {
        width: 7px; height: 7px;
        background: #00ff88;
        border-radius: 50%;
        box-shadow: 0 0 6px #00ff88;
    }
    .auth-nav-back {
        margin-left: auto;
        color: #555;
        font-size: .75rem;
        text-decoration: none;
        transition: color .15s;
    }
    .auth-nav-back:hover { color: #e0e0e0; }

    /* Layout */
    .auth-wrap {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 48px 16px;
    }
    .auth-card {
        width: 100%;
        max-width: 400px;
    }

    /* Header block */
    .auth-head { text-align: center; margin-bottom: 40px; }
    .auth-head-icon {
        width: 44px; height: 44px;
        border: 1px solid #1a1a2e;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
        font-size: 1.1rem;
        color: #00ff88;
    }
    .auth-head h1 {
        font-size: 1.1rem;
        font-weight: 700;
        margin: 0 0 6px;
        color: #e0e0e0;
    }
    .auth-head p {
        font-size: .78rem;
        color: #555;
        margin: 0;
    }

    /* Form */
    .auth-form { display: flex; flex-direction: column; gap: 16px; }
    .form-field {}
    .form-field label {
        display: block;
        font-size: .72rem;
        color: #666;
        margin-bottom: 6px;
        letter-spacing: .03em;
    }
    .form-field input {
        width: 100%;
        padding: 10px 12px;
        background: #0d0d16;
        border: 1px solid #1a1a2e;
        border-radius: 4px;
        color: #e0e0e0;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: .875rem;
        outline: none;
        transition: border-color .15s;
    }
    .form-field input:focus { border-color: #00ff88; }

    /* Error */
    .error-box {
        background: rgba(255,68,68,.06);
        border: 1px solid rgba(255,68,68,.25);
        color: #ff6666;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: .78rem;
    }

    /* Submit */
    .btn-submit { font-size: .875rem; padding: 11px; margin-top: 4px; }

    /* Footer */
    .auth-footer {
        text-align: center;
        margin-top: 28px;
        font-size: .75rem;
        color: #444;
    }
    .auth-footer a { color: #00ff88; text-decoration: none; }
    .auth-footer a:hover { text-decoration: underline; }

    /* Divider */
    .auth-divider {
        border: none;
        border-top: 1px solid #111;
        margin: 28px 0;
    }

    /* Feature hints */
    .auth-hints {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    .auth-hint {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: .73rem;
        color: #555;
        padding: 7px 10px;
        border-radius: 4px;
        border: 1px solid transparent;
        transition: border-color .15s, color .15s;
    }
    .auth-hint:hover { border-color: #1a1a2e; color: #888; }
    .auth-hint-icon { color: #00ff88; opacity: .7; display: flex; align-items: center; }
  </style>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<nav class="auth-nav">
  <a href="/domescape/website/index.html" class="auth-nav-brand">
    <span class="auth-nav-dot"></span>DomEscape
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
                <?= Csrf::field() ?>
      <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8') ?>">

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
        Escape game physique piloté par capteurs Z-Wave
      </div>
      <div class="auth-hint">
        <span class="auth-hint-icon"><i data-lucide="users" style="width:13px;height:13px;"></i></span>
        Gestion multi-rôles (joueur, superviseur, administrateur)
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
