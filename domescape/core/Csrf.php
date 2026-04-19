<?php

class Csrf
{
    private const TOKEN_KEY = '_csrf_token';
    private const TOKEN_LEN = 32;

    public static function token(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(self::TOKEN_LEN));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf_token" value="'
            . htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8')
            . '">';
    }

    public static function verify(): void
    {
        $submitted = $_POST['_csrf_token'] ?? '';
        if (!hash_equals(self::token(), $submitted)) {
            http_response_code(403);
            exit('Requête invalide (token CSRF manquant ou expiré).');
        }
    }
}
