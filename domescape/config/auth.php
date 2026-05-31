<?php
// ============================================================
// DomEscape — Configuration de l'authentification (Core Edition)
// Auth simplifiée : is_admin sur utilisateur (plus de RBAC)
// ============================================================

define('AUTH_LOGIN_URL',        '/domescape/public/connexion.php');
define('AUTH_DASHBOARD_URL',    '/domescape/public/index.php');
define('AUTH_SESSION_NAME',     'domescape_auth');
define('AUTH_SESSION_LIFETIME', 14400); // 4 heures
