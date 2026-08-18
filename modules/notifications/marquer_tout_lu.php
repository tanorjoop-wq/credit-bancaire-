<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$pdo->prepare('UPDATE notifications SET lu = 1 WHERE id_utilisateur_destinataire = :id')
    ->execute(['id' => $_SESSION['id_utilisateur']]);

rediriger('liste.php');
