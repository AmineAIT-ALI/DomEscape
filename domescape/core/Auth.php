<?php
// ============================================================
// DomEscape — Couche d'authentification
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
        if (self::$started) {
            return;
        }

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
    // Connexion — retourne true ou un message d'erreur string
    // ----------------------------------------------------------
    public static function login(string $email, string $password): bool|string
    {
        $repo = new UserRepository();
        $user = $repo->findByEmail(trim($email));

        if ($user === null) {
            return 'Identifiants invalides.';
        }

        if (!$user['actif']) {
            return 'Ce compte est désactivé.';
        }

        if (!password_verify($password, $user['mot_de_passe'])) {
            return 'Identifiants invalides.';
        }

        // Regénérer l'ID de session pour prévenir la fixation
        session_regenerate_id(true);

        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_nom']   = $user['nom'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_roles'] = $repo->getRoles($user['id']);

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
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
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
        if (!self::check()) {
            return null;
        }

        return [
            'id'    => $_SESSION['user_id'],
            'nom'   => $_SESSION['user_nom'],
            'email' => $_SESSION['user_email'],
        ];
    }

    public static function roles(): array
    {
        return $_SESSION['user_roles'] ?? [];
    }

    // ----------------------------------------------------------
    // Contrôle de rôle avec héritage hiérarchique
    // ----------------------------------------------------------
    public static function hasRole(string $role): bool
    {
        $effectiveRoles = self::buildHierarchy(self::roles());
        return in_array($role, $effectiveRoles, true);
    }

    /**
     * À partir d'une liste de rôles assignés, retourne l'ensemble
     * des rôles effectifs en appliquant la hiérarchie.
     */
    public static function buildHierarchy(array $assignedRoles): array
    {
        $all = $assignedRoles;

        foreach ($assignedRoles as $role) {
            if (isset(ROLE_HIERARCHY[$role])) {
                foreach (ROLE_HIERARCHY[$role] as $inherited) {
                    if (!in_array($inherited, $all, true)) {
                        $all[] = $inherited;
                    }
                }
            }
        }

        return $all;
    }
}
