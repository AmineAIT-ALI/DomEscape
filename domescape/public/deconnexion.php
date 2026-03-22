<?php
require_once __DIR__ . '/../core/Auth.php';

Auth::init();
Auth::logout();

header('Location: /domescape/public/connexion.php');
exit;
