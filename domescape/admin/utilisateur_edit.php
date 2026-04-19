<?php
require_once __DIR__ . '/../core/RoleGuard.php';
require_once __DIR__ . '/../core/Csrf.php';
require_once __DIR__ . '/../core/UserRepository.php';
require_once __DIR__ . '/../partials/flash.php';

RoleGuard::requireRole(ROLE_ADMINISTRATEUR);

$repo = new UserRepository();
$id   = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: /domescape/admin/utilisateurs.php');
    exit;
}

$target = $repo->findById($id);
if ($target === null) {
    flashSet('error', 'Utilisateur introuvable.');
    header('Location: /domescape/admin/utilisateurs.php');
    exit;
}

$targetRoles = $repo->getRoles($id);
$allRoles    = $repo->getAllRoles();
$error       = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify();
    $nom   = trim($_POST['nom']   ?? '');
    $actif = isset($_POST['actif']) ? 1 : 0;
    $newRoles = array_map('intval', $_POST['roles'] ?? []);

    if ($nom === '') {
        $error = 'Le nom ne peut pas être vide.';
    } else {
        $repo->update($id, ['nom' => $nom, 'actif' => $actif]);

        // Remplacer les rôles
        $repo->clearRoles($id);
        foreach ($newRoles as $roleId) {
            $repo->assignRole($id, $roleId);
        }

        flashSet('success', 'Utilisateur mis à jour.');
        header('Location: /domescape/admin/utilisateurs.php');
        exit;
    }

    // Rechargement partiel pour réafficher le formulaire
    $target      = $repo->findById($id);
    $targetRoles = $repo->getRoles($id);
}

$currentAdminId = Auth::user()['id'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Modifier l'utilisateur — DomEscape Admin</title>
  <style>
    body        { background:#0d0d0d; color:#e0e0e0; font-family:system-ui,-apple-system,'Segoe UI',sans-serif; min-height:100vh; }
    a           { color:#00ff88; }
    .page-wrap  { max-width:580px; margin:0 auto; padding:40px 24px; }
    .page-title { font-size:1.3rem; font-weight:700; margin-bottom:4px; }
    .page-sub   { font-size:.8rem; color:#555; margin-bottom:36px; }
    .form-card  { background:#111; border:1px solid #222; border-radius:6px; padding:32px; }
    .form-label { font-size:.8rem; color:#888; margin-bottom:4px; display:block; }
    .form-control{ background:#0d0d0d; border:1px solid #333; color:#e0e0e0; font-family:system-ui,-apple-system,'Segoe UI',sans-serif; font-size:.875rem; border-radius:4px; width:100%; padding:8px 12px; }
    .form-control:focus { outline:none; border-color:#00ff88; }
    .role-item  { display:flex; align-items:center; gap:10px; padding:10px 0; border-bottom:1px solid #1a1a1a; }
    .role-item:last-child { border-bottom:none; }
    .role-check { accent-color:#00ff88; width:16px; height:16px; cursor:pointer; }
    .role-name  { font-size:.85rem; }
    .btn-save   { font-size:.875rem; padding:10px 24px; }
    .btn-cancel { font-size:.875rem; padding:10px 24px; }
    .error-box  { background:rgba(255,68,68,.08); border:1px solid #ff4444; color:#ff4444; padding:10px 14px; border-radius:4px; font-size:.8rem; margin-bottom:20px; }
    .section-label{ font-size:.7rem; letter-spacing:.12em; color:#555; text-transform:uppercase; margin-bottom:14px; margin-top:24px; }
  </style>
    <link rel="stylesheet" href="/domescape/assets/css/components.css">
</head>
<body>

<?php require_once __DIR__ . '/../partials/nav.php'; ?>

<div class="page-wrap">
  <?php flashRender(); ?>

  <div class="page-title">Modifier l'utilisateur</div>
  <div class="page-sub"><a href="/domescape/admin/utilisateurs.php">← Liste des utilisateurs</a></div>

  <?php if ($error !== ''): ?>
    <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
  <?php endif; ?>

  <div class="form-card">
    <form method="post" action="">
                <?= Csrf::field() ?>
      <div style="margin-bottom:20px;">
        <label class="form-label" for="nom">Nom</label>
        <input type="text" id="nom" name="nom" class="form-control"
               value="<?= htmlspecialchars($target['nom'], ENT_QUOTES, 'UTF-8') ?>" required>
      </div>

      <div style="margin-bottom:20px;">
        <label class="form-label">Adresse e-mail</label>
        <div style="font-size:.875rem;color:#555;padding:8px 0;">
          <?= htmlspecialchars($target['email'], ENT_QUOTES, 'UTF-8') ?>
          <span style="font-size:.72rem;color:#333;margin-left:8px;">(non modifiable ici)</span>
        </div>
      </div>

      <div class="mb-4" style="display:flex;align-items:center;gap:12px;">
        <input type="checkbox" id="actif" name="actif" value="1"
               <?= $target['actif'] ? 'checked' : '' ?>
               <?= ($id === $currentAdminId) ? 'disabled title="Vous ne pouvez pas désactiver votre propre compte."' : '' ?>
               style="accent-color:#00ff88;width:16px;height:16px;cursor:pointer;">
        <label for="actif" class="form-label" style="margin:0;cursor:pointer;">Compte actif</label>
      </div>

      <div class="section-label">Rôles</div>
      <div style="margin-bottom:24px;">
        <?php foreach ($allRoles as $r): ?>
          <div class="role-item">
            <input type="checkbox"
                   class="role-check"
                   name="roles[]"
                   value="<?= (int)$r['id'] ?>"
                   id="role_<?= (int)$r['id'] ?>"
                   <?= in_array($r['nom'], $targetRoles, true) ? 'checked' : '' ?>
                   <?= ($id === $currentAdminId && $r['nom'] === ROLE_ADMINISTRATEUR) ? 'disabled checked title="Vous ne pouvez pas vous retirer le rôle administrateur."' : '' ?>>
            <label for="role_<?= (int)$r['id'] ?>" class="role-name" style="cursor:pointer;">
              <?= htmlspecialchars($r['nom'], ENT_QUOTES, 'UTF-8') ?>
            </label>
          </div>
        <?php endforeach; ?>
        <?php if ($id === $currentAdminId): ?>
          <!-- Valeur cachée pour garantir que le rôle admin est conservé même si la checkbox est désactivée -->
          <?php foreach ($allRoles as $r): if ($r['nom'] !== ROLE_ADMINISTRATEUR) continue; ?>
            <input type="hidden" name="roles[]" value="<?= (int)$r['id'] ?>">
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:12px;margin-top:8px;">
        <button type="submit" class="btn btn-primary btn-save">Enregistrer</button>
        <a href="/domescape/admin/utilisateurs.php" class="btn btn-outline btn-cancel">Annuler</a>
      </div>
    </form>
  </div>
</div>

</body>
</html>
