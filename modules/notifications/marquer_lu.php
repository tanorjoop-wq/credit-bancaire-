<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$idNotification = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM notifications WHERE id_notification = :id AND id_utilisateur_destinataire = :u');
$stmt->execute(['id' => $idNotification, 'u' => $_SESSION['id_utilisateur']]);
$notification = $stmt->fetch();

if (!$notification) {
    rediriger('liste.php');
}

$pdo->prepare('UPDATE notifications SET lu = 1 WHERE id_notification = :id')->execute(['id' => $idNotification]);

rediriger($notification['lien_cible'] ?: BASE_URL . '/modules/notifications/liste.php');
