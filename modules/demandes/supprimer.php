<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']); // seule l'administration peut supprimer

global $pdo;

$idDemande = (int) ($_GET['id'] ?? 0);
if ($idDemande <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare('SELECT reference, statut FROM demandes_credit WHERE id_demande = :id');
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    rediriger('liste.php');
}

// On protège l'historique : suppression possible uniquement avant tout traitement
if ($demande['statut'] !== 'en_attente') {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode(
        'Suppression impossible : la demande a déjà été traitée (scoring, workflow ou décision).'
    ));
}

$suppression = $pdo->prepare('DELETE FROM demandes_credit WHERE id_demande = :id');
$suppression->execute(['id' => $idDemande]);

enregistrerAudit('SUPPRESSION_DEMANDE', 'demandes_credit', $idDemande, 'Suppression de la demande ' . $demande['reference']);

rediriger('liste.php?succes=' . urlencode('Demande supprimée avec succès.'));
