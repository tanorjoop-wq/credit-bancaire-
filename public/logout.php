<?php
require_once __DIR__ . '/../includes/auth.php';

deconnecterUtilisateur();
rediriger(BASE_URL . '/public/login.php');
