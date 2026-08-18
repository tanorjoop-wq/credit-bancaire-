<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Mailer.php';
exigerRole(['administrateur']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idContrat = (int) ($_POST['id_contrat'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, d.id_demande, cl.nom_raison_sociale, cl.prenom, cl.email
     FROM contrats c
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     WHERE c.id_contrat = :id'
);
$stmt->execute(['id' => $idContrat]);
$contrat = $stmt->fetch();

if (!$contrat) {
    http_response_code(404);
    die('Contrat introuvable.');
}

if ($contrat['statut'] !== 'signe') {
    rediriger('voir.php?id=' . $idContrat . '&erreur=' . urlencode('Le contrat doit être signé avant décaissement.'));
}

$pdo->beginTransaction();

$maj = $pdo->prepare("UPDATE contrats SET statut = 'decaisse', date_decaissement = CURDATE() WHERE id_contrat = :id");
$maj->execute(['id' => $idContrat]);

$majDemande = $pdo->prepare("UPDATE demandes_credit SET statut = 'decaisse' WHERE id_demande = :id");
$majDemande->execute(['id' => $contrat['id_demande']]);

$pdo->commit();

enregistrerAudit('DECAISSEMENT', 'contrats', $idContrat, 'Décaissement du contrat ' . $contrat['numero_contrat']);

// Notification automatique par email au client
$messageEmail = '';
if (!empty($contrat['email'])) {
    $mailer = new Mailer();
    $nomClient = trim($contrat['prenom'] . ' ' . $contrat['nom_raison_sociale']);
    $envoye = $mailer->envoyerNotificationDecaissement(
        $contrat['email'],
        $nomClient,
        $contrat['numero_contrat'],
        (float) $contrat['montant_accorde'],
        date('Y-m-d')
    );
    $messageEmail = $envoye
        ? ' Email de confirmation envoyé à ' . $contrat['email'] . '.'
        : ' (Échec de l\'envoi de l\'email : ' . $mailer->getDerniereErreur() . ')';

    enregistrerAudit(
        $envoye ? 'EMAIL_DECAISSEMENT_OK' : 'EMAIL_DECAISSEMENT_ECHEC',
        'contrats',
        $idContrat,
        'Notification de décaissement à ' . $contrat['email'] . ($envoye ? '' : ' — ' . $mailer->getDerniereErreur())
    );
} else {
    $messageEmail = ' (Aucun email renseigné pour ce client, notification non envoyée.)';
}

rediriger('voir.php?id=' . $idContrat . '&succes=' . urlencode('Crédit décaissé avec succès.' . $messageEmail));
