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
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST, DB_NAME, DB_CHARSET
            );
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            _renderDbError();
        }
    }

    return $pdo;
}

/**
 * Affiche une erreur DB propre (HTML ou JSON selon le contexte) et exit.
 */
function _renderDbError(): void
{
    $isApi = strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;

    if ($isApi) {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['status' => 'error', 'message' => 'Base de données indisponible.']);
    } else {
        http_response_code(503);
        echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Erreur — DomEscape</title>
  <style>
    body { background:#080810; color:#e0e0e0; font-family:'Courier New',monospace;
           display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; }
    .box { text-align:center; max-width:420px; padding:40px; }
    .title { font-size:1.1rem; color:#ff4444; margin-bottom:16px; font-weight:700; }
    .sub { font-size:.82rem; color:#444; line-height:1.8; }
    .dot { color:#ff4444; margin-right:8px; }
    .back { display:inline-block; margin-top:24px; font-size:.78rem;
            color:#555; border:1px solid #1a1a2e; padding:8px 18px; border-radius:4px;
            text-decoration:none; transition:color .15s, border-color .15s; }
    .back:hover { color:#e0e0e0; border-color:#555; }
  </style>
</head>
<body>
  <div class="box">
    <div class="title"><span class="dot">■</span>Service indisponible</div>
    <div class="sub">La base de données est inaccessible.<br>Veuillez réessayer dans quelques instants.</div>
    <a href="javascript:history.back()" class="back">← Retour</a>
  </div>
</body>
</html>
HTML;
    }
    exit;
}
