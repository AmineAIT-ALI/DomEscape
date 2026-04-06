<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/UserRepository.php';
require_once __DIR__ . '/../core/GameEngine.php';
require_once __DIR__ . '/../partials/flash.php';

RoleGuard::requireLogin();

$user     = Auth::user();
$roles    = Auth::buildHierarchy(Auth::roles());
$isAdmin  = in_array(ROLE_ADMINISTRATEUR, $roles, true);
$isSuperv = in_array(ROLE_SUPERVISEUR,    $roles, true);

$pdo      = getDB();
$repo     = new UserRepository();
$userInfo = $repo->findById($user['id']);

// --- Données communes ---
$activeSession = GameEngine::getActiveSession();

// --- Données joueur ---
$derniersSessions = [];
$nbSessions       = 0;
$meilleurScore    = 0;

if (!$isAdmin && !$isSuperv) {
    // Sessions liées à cet utilisateur via joueur.id_utilisateur
    $stmt = $pdo->prepare("
        SELECT s.id_session, s.statut_session, s.score, s.nb_erreurs,
               s.date_debut, s.duree_secondes, sc.nom_scenario
        FROM session s
        JOIN equipe e   ON e.id_equipe   = s.id_equipe
        JOIN scenario sc ON sc.id_scenario = s.id_scenario
        WHERE e.id_utilisateur = ?
        ORDER BY s.date_debut DESC
        LIMIT 5
    ");
    $stmt->execute([$user['id']]);
    $derniersSessions = $stmt->fetchAll();

    $nbSessions   = count($derniersSessions);
    $meilleurScore = 0;
    foreach ($derniersSessions as $ds) {
        if ((int)$ds['score'] > $meilleurScore) $meilleurScore = (int)$ds['score'];
    }
}

// --- Données admin ---
$statsAdmin = [];
if ($isAdmin) {
    $statsAdmin['nb_utilisateurs'] = (int)$pdo->query("SELECT COUNT(*) FROM utilisateur")->fetchColumn();
    $statsAdmin['nb_scenarios']    = (int)$pdo->query("SELECT COUNT(*) FROM scenario WHERE actif = 1")->fetchColumn();
    $statsAdmin['nb_sessions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM session")->fetchColumn();
    $statsAdmin['nb_sessions_today'] = (int)$pdo->query("SELECT COUNT(*) FROM session WHERE DATE(date_debut) = CURDATE()")->fetchColumn();

    $stmt = $pdo->query("
        SELECT s.id_session, s.statut_session, s.score, s.date_debut,
               e.nom_equipe, sc.nom_scenario
        FROM session s
        JOIN equipe e    ON e.id_equipe    = s.id_equipe
        JOIN scenario sc ON sc.id_scenario = s.id_scenario
        ORDER BY s.date_debut DESC
        LIMIT 6
    ");
    $statsAdmin['sessions_recentes'] = $stmt->fetchAll();
}

$statutColor = [
    'en_cours'   => '#00ff88',
    'gagnee'     => '#60a5fa',
    'abandonnee' => '#ff4444',
    'perdue'     => '#ff4444',
];
$statutLabel = [
    'en_cours'   => 'En cours',
    'gagnee'     => 'Gagnée',
    'abandonnee' => 'Abandonnée',
    'perdue'     => 'Perdue',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tableau de bord — DomEscape</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { background: #080810; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; }
    a { color: #00ff88; text-decoration: none; }

    .db-wrap    { max-width: 1100px; margin: 0 auto; padding: 40px 24px 80px; }

    /* Grid helpers (remplacement Bootstrap) */
    .db-grid-4   { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
    .db-grid-3   { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 32px; }
    .db-grid-2-1 { display: grid; grid-template-columns: 2fr 1fr;        gap: 12px; margin-bottom: 24px; }
    @media (max-width: 768px) {
      .db-grid-4   { grid-template-columns: repeat(2, 1fr); }
      .db-grid-3, .db-grid-2-1 { grid-template-columns: 1fr; }
    }

    /* Header page */
    .db-header  { margin-bottom: 40px; padding-bottom: 28px; border-bottom: 1px solid #111; }
    .db-greeting{ font-size: 1.5rem; font-weight: 700; color: #e0e0e0; margin-bottom: 6px; letter-spacing: -.01em; }
    .db-sub     { font-size: .82rem; color: #444; }
    .db-badges  { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
    .db-badge   { font-size: .68rem; padding: 3px 10px; border-radius: 3px; border: 1px solid; }
    .db-badge-participant   { color: #00ff88; border-color: rgba(0,255,136,.3); background: rgba(0,255,136,.06); }
    .db-badge-superviseur   { color: #60a5fa; border-color: rgba(96,165,250,.3); background: rgba(96,165,250,.06); }
    .db-badge-administrateur{ color: #a78bfa; border-color: rgba(167,139,250,.3); background: rgba(167,139,250,.06); }

    /* Sections */
    .db-section-label { font-size: .68rem; letter-spacing: .12em; color: #444; text-transform: uppercase; margin-bottom: 16px; }

    /* Cartes d'action */
    .db-card { background: #0f0f18; border: 1px solid #1a1a2e; border-radius: 6px; padding: 24px; transition: border-color .2s; }
    .db-card:hover { border-color: #2a2a3e; }
    .db-card-icon { font-size: 1.6rem; margin-bottom: 14px; }
    .db-card-icon svg { width: 28px; height: 28px; stroke: currentColor; }
    .db-card-title { font-size: .9rem; font-weight: 600; color: #e0e0e0; margin-bottom: 6px; }
    .db-card-text  { font-size: .78rem; color: #555; line-height: 1.6; margin-bottom: 0; }

    /* Carte session active */
    .db-active-session { background: rgba(0,255,136,.04); border: 1px solid rgba(0,255,136,.2); border-radius: 6px; padding: 20px 24px; margin-bottom: 32px; display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .db-active-pulse { width: 8px; height: 8px; background: #00ff88; border-radius: 50%; animation: pulse 1.5s infinite; flex-shrink: 0; }
    @keyframes pulse { 0%,100%{box-shadow:0 0 0 0 rgba(0,255,136,.4)} 50%{box-shadow:0 0 0 6px rgba(0,255,136,0)} }
    .db-active-label { font-size: .72rem; color: #00ff88; letter-spacing: .08em; text-transform: uppercase; margin-bottom: 2px; }
    .db-active-title { font-size: 1rem; font-weight: 600; color: #e0e0e0; }
    .db-active-meta  { font-size: .78rem; color: #555; margin-top: 2px; }

    /* Bouton principal */
    .db-btn-primary { display: inline-block; background: #00ff88; color: #080810; font-weight: 700; font-size: .85rem; padding: 11px 22px; border-radius: 4px; transition: background .15s; border: none; cursor: pointer; }
    .db-btn-primary:hover { background: #00cc6a; color: #080810; }
    .db-btn-outline { display: inline-block; background: transparent; color: #aaa; font-size: .82rem; padding: 10px 18px; border-radius: 4px; border: 1px solid #222; transition: border-color .15s, color .15s; }
    .db-btn-outline:hover { border-color: #444; color: #e0e0e0; }
    .db-btn-blue    { display: inline-block; background: rgba(96,165,250,.1); color: #60a5fa; font-size: .82rem; font-weight: 600; padding: 10px 18px; border-radius: 4px; border: 1px solid rgba(96,165,250,.25); transition: background .15s; }
    .db-btn-blue:hover { background: rgba(96,165,250,.18); }
    .db-btn-purple  { display: inline-block; background: rgba(167,139,250,.1); color: #a78bfa; font-size: .82rem; font-weight: 600; padding: 10px 18px; border-radius: 4px; border: 1px solid rgba(167,139,250,.25); transition: background .15s; }
    .db-btn-purple:hover { background: rgba(167,139,250,.18); }

    /* Stats */
    .db-stat { background: #0f0f18; border: 1px solid #1a1a2e; border-radius: 6px; padding: 20px; }
    .db-stat-val { font-size: 2rem; font-weight: 700; line-height: 1; color: #00ff88; }
    .db-stat-lab { font-size: .72rem; color: #444; margin-top: 6px; letter-spacing: .05em; }

    /* Table sessions */
    .db-table { width: 100%; border-collapse: collapse; font-size: .79rem; }
    .db-table th { font-size: .68rem; letter-spacing: .08em; color: #444; text-transform: uppercase; padding: 8px 12px; border-bottom: 1px solid #111; text-align: left; font-weight: 400; }
    .db-table td { padding: 11px 12px; border-bottom: 1px solid #0d0d16; vertical-align: middle; }
    .db-table tr:last-child td { border-bottom: none; }
    .db-table tr:hover td { background: rgba(255,255,255,.02); }

    /* Supervision panel */
    .db-supervision { background: #0a0a15; border: 1px solid #1a1a2e; border-radius: 6px; padding: 28px; }
    .db-supervision-label { font-size: .68rem; color: #444; letter-spacing: .1em; text-transform: uppercase; margin-bottom: 6px; }
    .db-supervision-val   { font-size: 1.3rem; font-weight: 700; color: #00ff88; }
    .db-no-session { font-size: .82rem; color: #333; }
  </style>
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="db-wrap">
  <?php flashRender(); ?>

  <!-- En-tête -->
  <div class="db-header">
    <div class="db-greeting">
      <?php
        $heure = (int)date('H');
        if ($heure < 12) echo 'Bonjour,';
        elseif ($heure < 18) echo 'Bon après-midi,';
        else echo 'Bonsoir,';
      ?>
      <?= htmlspecialchars($user['nom'], ENT_QUOTES, 'UTF-8') ?>.
    </div>
    <div class="db-sub">
      Dernière connexion :
      <?= $userInfo['derniere_connexion']
          ? htmlspecialchars($userInfo['derniere_connexion'], ENT_QUOTES, 'UTF-8')
          : 'première connexion' ?>
    </div>
    <div class="db-badges">
      <?php foreach (Auth::roles() as $r): ?>
        <span class="db-badge db-badge-<?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>">
          <?= htmlspecialchars($r, ENT_QUOTES, 'UTF-8') ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>

  <?php /* =========================================================
         ADMINISTRATEUR
         ========================================================= */ ?>
  <?php if ($isAdmin): ?>

    <!-- Session active (si admin) -->
    <?php if ($activeSession): ?>
      <div class="db-active-session">
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="db-active-pulse"></div>
          <div>
            <div class="db-active-label">Session en cours</div>
            <div class="db-active-title">Session #<?= (int)$activeSession['id_session'] ?></div>
            <div class="db-active-meta">Démarrée le <?= htmlspecialchars($activeSession['date_debut'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <a href="/domescape/admin/dashboard.php" class="db-btn-outline">Voir dans l'admin →</a>
      </div>
    <?php endif; ?>

    <!-- Stats plateforme -->
    <div class="db-section-label">Vue plateforme</div>
    <div class="db-grid-4">
      <div class="db-stat">
        <div class="db-stat-val"><?= $statsAdmin['nb_utilisateurs'] ?></div>
        <div class="db-stat-lab">Utilisateurs</div>
      </div>
      <div class="db-stat">
        <div class="db-stat-val"><?= $statsAdmin['nb_scenarios'] ?></div>
        <div class="db-stat-lab">Scénarios actifs</div>
      </div>
      <div class="db-stat">
        <div class="db-stat-val"><?= $statsAdmin['nb_sessions_today'] ?></div>
        <div class="db-stat-lab">Sessions aujourd'hui</div>
      </div>
      <div class="db-stat">
        <div class="db-stat-val"><?= $statsAdmin['nb_sessions_total'] ?></div>
        <div class="db-stat-lab">Sessions totales</div>
      </div>
    </div>

    <!-- Accès rapides admin -->
    <div class="db-section-label">Administration</div>
    <div class="db-grid-3">
      <div class="db-card">
        <div class="db-card-icon" style="color:#a78bfa;"><i data-lucide="settings"></i></div>
        <div class="db-card-title"><a href="/domescape/admin/dashboard.php" style="color:#a78bfa;">Dashboard admin</a></div>
        <p class="db-card-text">Scénarios configurés, historique des sessions, état global de la plateforme.</p>
      </div>
      <div class="db-card">
        <div class="db-card-icon" style="color:#a78bfa;"><i data-lucide="users"></i></div>
        <div class="db-card-title"><a href="/domescape/admin/utilisateurs.php" style="color:#a78bfa;">Utilisateurs</a></div>
        <p class="db-card-text">Gérer les comptes, modifier les rôles, activer ou désactiver des accès.</p>
      </div>
      <div class="db-card">
        <div class="db-card-icon" style="color:#60a5fa;"><i data-lucide="monitor"></i></div>
        <div class="db-card-title"><a href="/domescape/public/gamemaster.php" style="color:#60a5fa;">Supervision</a></div>
        <p class="db-card-text">Centre de contrôle temps réel des sessions en cours.</p>
      </div>
    </div>

    <!-- Sessions récentes -->
    <div class="db-section-label">Sessions récentes</div>
    <div class="db-card" style="padding:0;">
      <table class="db-table">
        <thead>
          <tr>
            <th>#</th><th>Joueur</th><th>Scénario</th>
            <th>Statut</th><th>Score</th><th>Début</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($statsAdmin['sessions_recentes'] as $s):
            $sc = $statutColor[$s['statut_session']] ?? '#555';
            $sl = $statutLabel[$s['statut_session']] ?? $s['statut_session'];
          ?>
          <tr>
            <td style="color:#333;"><?= (int)$s['id_session'] ?></td>
            <td><?= htmlspecialchars($s['nom_equipe'],   ENT_QUOTES, 'UTF-8') ?></td>
            <td style="color:#888;"><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><span style="color:<?= $sc ?>;font-size:.75rem;"><?= $sl ?></span></td>
            <td style="color:#00ff88;"><?= (int)$s['score'] ?></td>
            <td style="color:#333;font-size:.75rem;"><?= htmlspecialchars(substr($s['date_debut'],0,16), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($statsAdmin['sessions_recentes'])): ?>
            <tr><td colspan="6" style="text-align:center;color:#333;padding:24px;">Aucune session encore jouée.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  <?php /* =========================================================
         SUPERVISEUR (non admin)
         ========================================================= */ ?>
  <?php elseif ($isSuperv): ?>

    <!-- Session active -->
    <?php if ($activeSession): ?>
      <div class="db-active-session">
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="db-active-pulse"></div>
          <div>
            <div class="db-active-label">Session en cours</div>
            <div class="db-active-title">Session #<?= (int)$activeSession['id_session'] ?></div>
            <div class="db-active-meta">Depuis le <?= htmlspecialchars($activeSession['date_debut'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <a href="/domescape/public/gamemaster.php" class="db-btn-blue">Ouvrir la supervision →</a>
      </div>
    <?php else: ?>
      <div style="background:#0f0f18; border:1px solid #1a1a2e; border-radius:6px; padding:20px 24px; margin-bottom:32px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; justify-content:space-between;">
        <div>
          <div style="font-size:.72rem; color:#333; letter-spacing:.08em; text-transform:uppercase; margin-bottom:4px;">Aucune session active</div>
          <div style="font-size:.85rem; color:#555;">En attente d'une partie lancée depuis l'accueil.</div>
        </div>
        <a href="/domescape/public/gamemaster.php" class="db-btn-outline">Ouvrir la supervision</a>
      </div>
    <?php endif; ?>

    <!-- Centre de supervision -->
    <div class="db-section-label">Centre de pilotage</div>
    <div class="db-grid-2-1">
      <div class="db-supervision">
        <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px;">
          <div>
            <div class="db-supervision-label">Supervision temps réel</div>
            <div style="font-size:1rem; font-weight:600; color:#e0e0e0; margin-top:4px;">Centre de contrôle Game Master</div>
          </div>
          <a href="/domescape/public/gamemaster.php" class="db-btn-blue">Accéder →</a>
        </div>
        <p style="font-size:.82rem; color:#444; line-height:1.7; margin:0;">
          Suivez la progression des équipes en temps réel, consultez les événements capteurs, visualisez l'étape courante et réinitialisez la session si nécessaire.
        </p>
      </div>
      <div class="db-card" style="display:flex; flex-direction:column; justify-content:center;">
        <div class="db-card-icon" style="color:#60a5fa;"><i data-lucide="play"></i></div>
        <div class="db-card-title"><a href="/domescape/public/index.php">Lancer une partie</a></div>
        <p class="db-card-text">Démarrez une nouvelle session depuis la sélection de scénario.</p>
      </div>
    </div>

  <?php /* =========================================================
         JOUEUR
         ========================================================= */ ?>
  <?php else: ?>

    <!-- Session active du joueur -->
    <?php if ($activeSession): ?>
      <div class="db-active-session">
        <div style="display:flex; align-items:center; gap:12px;">
          <div class="db-active-pulse"></div>
          <div>
            <div class="db-active-label">Partie en cours</div>
            <div class="db-active-title">Session #<?= (int)$activeSession['id_session'] ?></div>
            <div class="db-active-meta">Démarrée le <?= htmlspecialchars($activeSession['date_debut'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>
        <a href="/domescape/public/player.php" class="db-btn-primary">Reprendre la partie →</a>
      </div>
    <?php endif; ?>

    <!-- Stats joueur -->
    <div class="db-grid-4">
      <div class="db-stat">
        <div class="db-stat-val"><?= $nbSessions ?></div>
        <div class="db-stat-lab">Sessions jouées</div>
      </div>
      <div class="db-stat">
        <div class="db-stat-val"><?= $meilleurScore ?></div>
        <div class="db-stat-lab">Meilleur score</div>
      </div>
      <div class="db-stat">
        <?php $nbGagnees = count(array_filter($derniersSessions, fn($s) => $s['statut_session'] === 'gagnee')); ?>
        <div class="db-stat-val"><?= $nbGagnees ?></div>
        <div class="db-stat-lab">Parties gagnées</div>
      </div>
      <div class="db-stat">
        <?php $totalErreurs = array_sum(array_column($derniersSessions, 'nb_erreurs')); ?>
        <div class="db-stat-val"><?= $totalErreurs ?></div>
        <div class="db-stat-lab">Erreurs totales</div>
      </div>
    </div>

    <!-- Actions principales -->
    <div class="db-section-label">Actions</div>
    <div class="db-grid-3">
      <div class="db-card" style="border-color:rgba(0,255,136,.15);">
        <div class="db-card-icon" style="color:#00ff88;"><i data-lucide="play"></i></div>
        <div class="db-card-title" style="margin-bottom:12px;">Lancer une session</div>
        <p class="db-card-text" style="margin-bottom:20px;">Choisissez un scénario disponible et commencez une nouvelle partie.</p>
        <a href="/domescape/public/index.php" class="db-btn-primary">Voir les scénarios →</a>
      </div>
      <div class="db-card">
        <div class="db-card-icon" style="color:#888;"><i data-lucide="clipboard-list"></i></div>
        <div class="db-card-title" style="margin-bottom:12px;">Mes sessions</div>
        <p class="db-card-text" style="margin-bottom:20px;">Consultez l'historique complet de vos parties passées.</p>
        <a href="/domescape/public/mes-sessions.php" class="db-btn-outline">Voir l'historique</a>
      </div>
      <div class="db-card">
        <div class="db-card-icon" style="color:#888;"><i data-lucide="user"></i></div>
        <div class="db-card-title" style="margin-bottom:12px;">Mon profil</div>
        <p class="db-card-text" style="margin-bottom:20px;">Vos informations, vos rôles et vos accès.</p>
        <a href="/domescape/public/profil.php" class="db-btn-outline">Voir le profil</a>
      </div>
    </div>

    <!-- Dernières sessions -->
    <?php if (!empty($derniersSessions)): ?>
    <div class="db-section-label">Dernières sessions</div>
    <div class="db-card" style="padding:0; margin-bottom:8px;">
      <table class="db-table">
        <thead>
          <tr>
            <th>Scénario</th><th>Statut</th>
            <th>Score</th><th>Erreurs</th><th>Durée</th><th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($derniersSessions as $s):
            $sc = $statutColor[$s['statut_session']] ?? '#555';
            $sl = $statutLabel[$s['statut_session']] ?? $s['statut_session'];
            $duree = $s['duree_secondes']
                ? floor($s['duree_secondes']/60) . 'm ' . ($s['duree_secondes']%60) . 's'
                : '—';
          ?>
          <tr>
            <td><?= htmlspecialchars($s['nom_scenario'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><span style="color:<?= $sc ?>;font-size:.75rem;"><?= $sl ?></span></td>
            <td style="color:#00ff88;"><?= (int)$s['score'] ?></td>
            <td style="color:<?= $s['nb_erreurs'] > 0 ? '#ff4444' : '#555' ?>;"><?= (int)$s['nb_erreurs'] ?></td>
            <td style="color:#555;"><?= $duree ?></td>
            <td style="color:#333;font-size:.75rem;"><?= htmlspecialchars(substr($s['date_debut'],0,10), ENT_QUOTES, 'UTF-8') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="text-align:right;">
      <a href="/domescape/public/mes-sessions.php" style="font-size:.78rem; color:#444;">Voir toutes les sessions →</a>
    </div>
    <?php endif; ?>

  <?php endif; ?>

</div>
<script src="/domescape/assets/vendor/lucide.min.js"></script>
<script>lucide.createIcons();</script>
</body>
</html>
