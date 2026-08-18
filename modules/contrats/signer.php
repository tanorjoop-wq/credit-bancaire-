<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idContrat = (int) ($_POST['id_contrat'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM contrats WHERE id_contrat = :id');
$stmt->execute(['id' => $idContrat]);
$contrat = $stmt->fetch();

if (!$contrat) {
    http_response_code(404);
    die('Contrat introuvable.');
}

if ($contrat['statut'] !== 'en_preparation') {
    rediriger('voir.php?id=' . $idContrat . '&erreur=' . urlencode('Ce contrat n\'est pas en attente de signature.'));
}

$maj = $pdo->prepare("UPDATE contrats SET statut = 'signe', date_signature = CURDATE() WHERE id_contrat = :id");
$maj->execute(['id' => $idContrat]);

enregistrerAudit('SIGNATURE_CONTRAT', 'contrats', $idContrat, 'Signature du contrat ' . $contrat['numero_contrat']);

rediriger('voir.php?id=' . $idContrat . '&succes=' . urlencode('Contrat signé avec succès.'));
