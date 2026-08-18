<?php
require_once __DIR__ . '/includes/auth.php';

if (!empty($_SESSION['id_utilisateur'])) {
    rediriger('/public/dashboard.php');
}
rediriger('/public/login.php');
