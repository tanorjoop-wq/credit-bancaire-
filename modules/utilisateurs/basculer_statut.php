<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idUtilisateur = (int) ($_POST['id_utilisateur'] ?? 0);

if ($idUtilisateur === (int) $_SESSION['id_utilisateur']) {
    rediriger('liste.php?erreur=' . urlencode('Vous ne pouvez pas désactiver votre propre compte.'));
}

$stmt = $pdo->prepare('SELECT email, actif FROM utilisateurs WHERE id_utilisateur = :id');
$stmt->execute(['id' => $idUtilisateur]);
$utilisateur = $stmt->fetch();

if (!$utilisateur) {
    rediriger('liste.php');
}

$nouveauStatut = $utilisateur['actif'] ? 0 : 1;
$maj = $pdo->prepare('UPDATE utilisateurs SET actif = :actif WHERE id_utilisateur = :id');
$maj->execute(['actif' => $nouveauStatut, 'id' => $idUtilisateur]);

enregistrerAudit(
    $nouveauStatut ? 'REACTIVATION_UTILISATEUR' : 'DESACTIVATION_UTILISATEUR',
    'utilisateurs',
    $idUtilisateur,
    ($nouveauStatut ? 'Réactivation' : 'Désactivation') . ' du compte ' . $utilisateur['email']
);

rediriger('liste.php?succes=' . urlencode('Statut mis à jour avec succès.'));
