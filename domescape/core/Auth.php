<?php
// ============================================================
// DomEscape — Couche d'authentification (Core Edition)
// Auth simplifiée : is_admin remplace le RBAC role/utilisateur_role
// ============================================================

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/UserRepository.php';

class Auth
{
    private static bool $started = false;

    // ----------------------------------------------------------
    // Initialisation — à appeler en tête de chaque page protégée
    // ----------------------------------------------------------
    public static function init(): void
    {
        if (self::$started) return;

        ini_set('session.cookie_httponly', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_samesite', 'Lax');

        session_name(AUTH_SESSION_NAME);

        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => AUTH_SESSION_LIFETIME,
                'path'     => '/',
                'secure'   => false,          // passer à true en HTTPS
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }

        self::$started = true;

        // Expiration de session inactive
        if (isset($_SESSION['_last_activity'])) {
            if ((time() - $_SESSION['_last_activity']) > AUTH_SESSION_LIFETIME) {
                self::logout();
                return;
            }
        }
        $_SESSION['_last_activity'] = time();
    }

    // ----------------------------------------------------------
    // Connexion
    // ----------------------------------------------------------
    public static function login(string $email, string $password)
    {
        $repo = new UserRepository();
        $user = $repo->findByEmail(trim($email));

        if ($user === null)                                          return 'Identifiants invalides.';
        if (!$user['actif'])                                         return 'Ce compte est désactivé.';
        if (!password_verify($password, $user['mot_de_passe']))      return 'Identifiants invalides.';

        session_regenerate_id(true);

        $_SESSION['user_id']       = $user['id'];
        $_SESSION['user_nom']      = $user['nom'];
        $_SESSION['user_email']    = $user['email'];
        $_SESSION['user_is_admin'] = (bool) $user['is_admin'];

        $repo->updateLastLogin($user['id']);

        return true;
    }

    // ----------------------------------------------------------
    // Déconnexion
    // ----------------------------------------------------------
    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '',
                time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
    }

    // ----------------------------------------------------------
    // Vérifications
    // ----------------------------------------------------------
    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function user(): ?array
    {
        if (!self::check()) return null;

        return [
            'id'       => $_SESSION['user_id'],
            'nom'      => $_SESSION['user_nom'],
            'email'    => $_SESSION['user_email'],
            'is_admin' => $_SESSION['user_is_admin'] ?? false,
        ];
    }

    public static function isAdmin(): bool
    {
        return (bool) ($_SESSION['user_is_admin'] ?? false);
    }
}
