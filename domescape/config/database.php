<?php

// =============================================================
// DomEscape — Configuration base de données
// Les credentials DB_USER et DB_PASS viennent de config/secrets.php
// =============================================================

require_once __DIR__ . '/secrets.php';

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'domescape');
define('DB_CHARSET', 'utf8mb4');

/**
 * Retourne une connexion PDO partagée (singleton).
 */
function getDB(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            DB_HOST, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    return $pdo;
}
