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
    JOIN equipe e ON se.id_equipe = e.id_equipe
    WHERE e.id_utilisateur = ?
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
  <style>
    body { background: #080810; color: #e0e0e0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; min-height: 100vh; }
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
    .badge-participant   { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
    .badge-superviseur   { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
    .badge-administrateur{ color: #a78bfa; border-color: rgba(167,139,250,.3); background: rgba(167,139,250,.06); }

    /* Password form */
    .pw-card {
        background: #0f0f18;
        border: 1px solid #111;
        border-radius: 6px;
        overflow: hidden;
        margin-bottom: 20px;
    }
    .pw-card-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px 20px;
        border-bottom: 1px solid #0a0a14;
    }
    .pw-card-head-icon {
        width: 30px; height: 30px;
        background: rgba(0,255,136,.06);
        border: 1px solid rgba(0,255,136,.15);
        border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .pw-card-head-title { font-size: .82rem; font-weight: 600; color: #ccc; }
    .pw-card-head-sub   { font-size: .7rem; color: #444; margin-top: 1px; }
    .pw-card-body { padding: 24px 20px; }
    .pw-field { margin-bottom: 16px; }
    .pw-field label {
        font-size: .65rem;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #555;
        display: block;
        margin-bottom: 7px;
    }
    .pw-input-wrap { position: relative; }
    .pw-input {
        width: 100%;
        background: #080810;
        border: 1px solid #1a1a2e;
        color: #e0e0e0;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: .85rem;
        padding: 9px 38px 9px 13px;
        border-radius: 4px;
        outline: none;
        transition: border-color .15s;
        box-sizing: border-box;
    }
    .pw-input:focus { border-color: #00ff88; }
    .pw-input.input-ok    { border-color: rgba(0,255,136,.5); }
    .pw-input.input-error { border-color: rgba(255,68,68,.5); }
    .pw-toggle {
        position: absolute; right: 10px; top: 50%;
        transform: translateY(-50%);
        background: none; border: none; padding: 0;
        color: #333; cursor: pointer;
        display: flex; align-items: center;
        transition: color .15s;
    }
    .pw-toggle:hover { color: #888; }
    /* Barre de force */
    .pw-strength { margin-top: 8px; }
    .pw-strength-bar {
        height: 3px;
        background: #111;
        border-radius: 2px;
        overflow: hidden;
        margin-bottom: 5px;
    }
    .pw-strength-fill {
        height: 100%;
        width: 0%;
        border-radius: 2px;
        transition: width .25s, background .25s;
    }
    .pw-strength-label { font-size: .62rem; color: #444; letter-spacing: .06em; }
    /* Indicateur confirmation */
    .pw-match { font-size: .62rem; margin-top: 6px; display: none; }
    .pw-match.ok  { color: #00ff88; display: block; }
    .pw-match.err { color: #ff4444; display: block; }
    .pw-divider { height: 1px; background: #0a0a14; margin: 4px 0 20px; }
    .btn-save {
        background: transparent;
        border: 1px solid #00ff88;
        color: #00ff88;
        font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
        font-size: .8rem;
        padding: 8px 20px;
        border-radius: 4px;
        transition: background .15s, color .15s;
        cursor: pointer;
    }
    .btn-save:hover { background: #00ff88; color: #080810; }

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
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
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
    <div class="info-row" style="align-items:flex-start; padding-top:14px; padding-bottom:14px;">
      <span class="info-label">Rôles</span>
      <span style="display:flex; gap:6px; flex-wrap:wrap;">
        <?php if (empty($roles)): ?>
          <span style="color:#333; font-size:.78rem;">—</span>
        <?php else: ?>
          <?php foreach ($roles as $r): ?>
            <span class="badge-role badge-<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>
            </span>
          <?php endforeach; ?>
        <?php endif; ?>
      </span>
    </div>
  </div>

  <!-- Mot de passe -->
  <div class="section-label" style="margin-top:32px;">Sécurité</div>
  <div class="pw-card">
    <div class="pw-card-head">
      <div class="pw-card-head-icon">
        <i data-lucide="lock" style="width:13px;height:13px;color:#00ff88;"></i>
      </div>
      <div>
        <div class="pw-card-head-title">Modifier le mot de passe</div>
        <div class="pw-card-head-sub">Minimum 8 caractères</div>
      </div>
    </div>
    <div class="pw-card-body">

      <?php if ($pwSuccess): ?>
        <div class="alert-success" style="margin-bottom:20px;"><?= htmlspecialchars($pwSuccess, ENT_QUOTES, 'UTF-8') ?></div>
      <?php elseif ($pwError): ?>
        <div class="alert-error" style="margin-bottom:20px;"><?= htmlspecialchars($pwError, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" action="">
        <input type="hidden" name="_action" value="change_password">

        <div class="pw-field">
          <label for="current_password">Mot de passe actuel</label>
          <div class="pw-input-wrap">
            <input type="password" id="current_password" name="current_password" class="pw-input" required autocomplete="current-password">
            <button type="button" class="pw-toggle" onclick="togglePw('current_password', this)" tabindex="-1">
              <i data-lucide="eye" style="width:14px;height:14px;"></i>
            </button>
          </div>
        </div>

        <div class="pw-divider"></div>

        <div class="pw-field">
          <label for="new_password">Nouveau mot de passe</label>
          <div class="pw-input-wrap">
            <input type="password" id="new_password" name="new_password" class="pw-input" required autocomplete="new-password" oninput="checkStrength(this.value); checkMatch();">
            <button type="button" class="pw-toggle" onclick="togglePw('new_password', this)" tabindex="-1">
              <i data-lucide="eye" style="width:14px;height:14px;"></i>
            </button>
          </div>
          <div class="pw-strength" id="pw-strength" style="display:none;">
            <div class="pw-strength-bar"><div class="pw-strength-fill" id="pw-fill"></div></div>
            <span class="pw-strength-label" id="pw-label"></span>
          </div>
        </div>

        <div class="pw-field" style="margin-bottom:20px;">
          <label for="confirm_password">Confirmer le nouveau mot de passe</label>
          <div class="pw-input-wrap">
            <input type="password" id="confirm_password" name="confirm_password" class="pw-input" required autocomplete="new-password" oninput="checkMatch();">
            <button type="button" class="pw-toggle" onclick="togglePw('confirm_password', this)" tabindex="-1">
              <i data-lucide="eye" style="width:14px;height:14px;"></i>
            </button>
          </div>
          <div class="pw-match" id="pw-match"></div>
        </div>

        <button type="submit" class="btn-save">Mettre à jour →</button>
      </form>
    </div>
  </div>

  <!-- Quick links -->
  <div class="section-label" style="margin-top:32px;">Raccourcis</div>
  <div class="quick-links">
    <a href="/domescape/public/mes-sessions.php" class="quick-link">Mes sessions →</a>
    <a href="/domescape/public/index.php" class="quick-link">Jouer →</a>
    <a href="/domescape/public/tableau-de-bord.php" class="quick-link">Tableau de bord</a>
  </div>

</div>

<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>
lucide.createIcons();

function togglePw(id, btn) {
    const input = document.getElementById(id);
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.innerHTML = isHidden
        ? '<i data-lucide="eye-off" style="width:14px;height:14px;"></i>'
        : '<i data-lucide="eye"     style="width:14px;height:14px;"></i>';
    lucide.createIcons();
}

function checkStrength(val) {
    const fill  = document.getElementById('pw-fill');
    const label = document.getElementById('pw-label');
    const wrap  = document.getElementById('pw-strength');
    if (!val) { wrap.style.display = 'none'; return; }
    wrap.style.display = 'block';
    let score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { pct: '20%', color: '#ff4444', text: 'Très faible' },
        { pct: '40%', color: '#f97316', text: 'Faible'      },
        { pct: '60%', color: '#f0c040', text: 'Moyen'       },
        { pct: '80%', color: '#00cc6a', text: 'Fort'        },
        { pct: '100%',color: '#00ff88', text: 'Très fort'   },
    ];
    const l = levels[Math.min(score, 4)];
    fill.style.width      = l.pct;
    fill.style.background = l.color;
    label.textContent     = l.text;
    label.style.color     = l.color;
}

function checkMatch() {
    const np  = document.getElementById('new_password').value;
    const cp  = document.getElementById('confirm_password').value;
    const el  = document.getElementById('pw-match');
    const cin = document.getElementById('confirm_password');
    if (!cp) { el.className = 'pw-match'; cin.classList.remove('input-ok','input-error'); return; }
    if (np === cp) {
        el.textContent = '✓ Les mots de passe correspondent';
        el.className   = 'pw-match ok';
        cin.classList.add('input-ok'); cin.classList.remove('input-error');
    } else {
        el.textContent = '✗ Les mots de passe ne correspondent pas';
        el.className   = 'pw-match err';
        cin.classList.add('input-error'); cin.classList.remove('input-ok');
    }
}
</script>
</body>
</html>
