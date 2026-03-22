<?php
// ============================================================
// DomEscape — Configuration de l'authentification
// ============================================================

define('AUTH_LOGIN_URL',     '/domescape/public/connexion.php');
define('AUTH_DASHBOARD_URL', '/domescape/public/tableau-de-bord.php');
define('AUTH_SESSION_NAME',  'domescape_auth');

// Durée de la session PHP (secondes) — 4 heures
define('AUTH_SESSION_LIFETIME', 14400);

// Rôles disponibles (dans l'ordre hiérarchique croissant)
define('ROLE_JOUEUR',         'joueur');
define('ROLE_SUPERVISEUR',    'superviseur');
define('ROLE_ADMINISTRATEUR', 'administrateur');

// Hiérarchie : chaque rôle hérite des droits des rôles inférieurs
const ROLE_HIERARCHY = [
    ROLE_ADMINISTRATEUR => [ROLE_SUPERVISEUR, ROLE_JOUEUR],
    ROLE_SUPERVISEUR    => [ROLE_JOUEUR],
    ROLE_JOUEUR         => [],
];
