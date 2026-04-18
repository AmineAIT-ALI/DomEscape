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
            $id = $repo->create($nom, $email, $password);

            // Assigner le rôle "participant" par défaut
            $roles = $repo->getAllRoles();
            foreach ($roles as $r) {
                if ($r['nom'] === ROLE_PARTICIPANT) {
                    $repo->assignRole($id, (int) $r['id']);
                    break;
                }
            }

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

    .auth-wrap {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 48px 16px;
    }
    .auth-card { width: 100%; max-width: 420px; }

    .auth-head { text-align: center; margin-bottom: 36px; }
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
    .auth-head p { font-size: .78rem; color: #555; margin: 0; }

    .auth-form { display: flex; flex-direction: column; gap: 14px; }
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
    .form-hint { font-size: .68rem; color: #444; margin-top: 5px; }

    /* Password strength bar */
    .pw-strength { margin-top: 6px; }
    .pw-bar {
        height: 3px;
        background: #111;
        border-radius: 2px;
        overflow: hidden;
    }
    .pw-bar-fill {
        height: 100%;
        border-radius: 2px;
        transition: width .3s, background .3s;
        width: 0%;
    }
    .pw-label { font-size: .65rem; color: #444; margin-top: 4px; }

    .error-box {
        background: rgba(255,68,68,.06);
        border: 1px solid rgba(255,68,68,.25);
        color: #ff6666;
        padding: 10px 14px;
        border-radius: 4px;
        font-size: .78rem;
    }

    .btn-submit {
        background: #00ff88;
        color: #080810;
        border: none;
        font-weight: 700;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: .875rem;
        padding: 11px;
        border-radius: 4px;
        cursor: pointer;
        transition: background .15s;
        margin-top: 6px;
    }
    .btn-submit:hover { background: #00cc6a; }

    /* Role chip */
    .role-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(0,255,136,.06);
        border: 1px solid rgba(0,255,136,.2);
        color: #00ff88;
        font-size: .72rem;
        padding: 5px 12px;
        border-radius: 3px;
        margin-bottom: 20px;
    }
    .role-chip-dot { width: 6px; height: 6px; background: #00ff88; border-radius: 50%; }

    .auth-divider { border: none; border-top: 1px solid #111; margin: 24px 0; }

    .auth-footer {
        text-align: center;
        margin-top: 24px;
        font-size: .75rem;
        color: #444;
    }
    .auth-footer a { color: #00ff88; text-decoration: none; }
    .auth-footer a:hover { text-decoration: underline; }
  </style>
</head>
<body>

<nav class="auth-nav">
  <a href="/domescape/website/index.html" class="auth-nav-brand">
    <span class="auth-nav-dot"></span>DomEscape
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

      <button type="submit" class="btn-submit">Créer mon compte →</button>
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
