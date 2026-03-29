<?php
// ============================================================
// DomEscape — Guards d'accès
// ============================================================

require_once __DIR__ . '/Auth.php';

class RoleGuard
{
    /**
     * Redirige vers la page de connexion si l'utilisateur
     * n'est pas authentifié. À appeler en tête de page.
     */
    public static function requireLogin(): void
    {
        Auth::init();

        if (!Auth::check()) {
            $current = urlencode($_SERVER['REQUEST_URI'] ?? '');
            header('Location: ' . AUTH_LOGIN_URL . '?redirect=' . $current);
            exit;
        }
    }

    /**
     * Exige un rôle minimum (avec héritage hiérarchique).
     * Appelle requireLogin() en premier.
     */
    public static function requireRole(string $role): void
    {
        self::requireLogin();

        if (!Auth::hasRole($role)) {
            self::denyAccess();
        }
    }

    /**
     * Affiche une page 403 et arrête l'exécution.
     */
    public static function denyAccess(): void
    {
        http_response_code(403);
        ?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Accès refusé — DomEscape</title>
  <link href="/domescape/assets/vendor/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #0d0d0d; color: #e0e0e0; font-family: 'Courier New', monospace; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
    .deny-box { text-align: center; max-width: 480px; }
    .deny-code { font-size: 5rem; font-weight: 700; color: #ff4444; line-height: 1; }
    .deny-title { font-size: 1.3rem; color: #e0e0e0; margin: 16px 0 8px; }
    .deny-sub { color: #888; font-size: 0.9rem; margin-bottom: 32px; }
    .btn-back { background: #1a1a2e; border: 1px solid #333; color: #00ff88; padding: 10px 24px; text-decoration: none; border-radius: 4px; font-size: 0.85rem; transition: border-color .2s; }
    .btn-back:hover { border-color: #00ff88; color: #00ff88; }
  </style>
</head>
<body>
  <div class="deny-box">
    <div class="deny-code">403</div>
    <div class="deny-title">Accès refusé</div>
    <p class="deny-sub">Vous n'avez pas les droits nécessaires pour accéder à cette page.</p>
    <a href="/domescape/public/tableau-de-bord.php" class="btn-back">← Retour au tableau de bord</a>
  </div>
</body>
</html>
        <?php
        exit;
    }
}

// ----------------------------------------------------------
// Fonctions globales de commodité (compatibles avec l'existant)
// ----------------------------------------------------------

function requireLogin(): void
{
    RoleGuard::requireLogin();
}

function requireRole(string $role): void
{
    RoleGuard::requireRole($role);
}
