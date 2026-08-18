<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']); // seule l'administration peut supprimer

global $pdo;

$idClient = (int) ($_GET['id'] ?? 0);
if ($idClient <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare('SELECT nom_raison_sociale FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
$client = $stmt->fetch();

if (!$client) {
    rediriger('liste.php');
}

// Empêche la suppression d'un client ayant des demandes de crédit liées
// (intégrité référentielle : on protège l'historique plutôt que de le perdre)
$stmtVerif = $pdo->prepare('SELECT COUNT(*) FROM demandes_credit WHERE id_client = :id');
$stmtVerif->execute(['id' => $idClient]);
$nbDemandesLiees = (int) $stmtVerif->fetchColumn();

if ($nbDemandesLiees > 0) {
    rediriger('liste.php?succes=' . urlencode(
        'Impossible de supprimer ce client : ' . $nbDemandesLiees . ' demande(s) de crédit lui sont rattachées.'
    ));
}

$suppression = $pdo->prepare('DELETE FROM clients WHERE id_client = :id');
$suppression->execute(['id' => $idClient]);

enregistrerAudit('SUPPRESSION_CLIENT', 'clients', $idClient, 'Suppression du client ' . $client['nom_raison_sociale']);

rediriger('liste.php?succes=' . urlencode('Client supprimé avec succès.'));
