<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);
$typeGarantie = $_POST['type_garantie'] ?? '';
$description = trim($_POST['description'] ?? '');
$valeurEstimee = $_POST['valeur_estimee'] ?? '';

$typesValides = ['hypotheque', 'caution', 'nantissement', 'gage', 'domiciliation_salaire'];

$stmt = $pdo->prepare('SELECT id_demande, reference FROM demandes_credit WHERE id_demande = :id');
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

if (!in_array($typeGarantie, $typesValides, true) || !is_numeric($valeurEstimee) || (float) $valeurEstimee <= 0) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Garantie invalide : vérifiez le type et la valeur estimée.'));
}

$insert = $pdo->prepare(
    'INSERT INTO garanties (id_demande, type_garantie, description, valeur_estimee, statut)
     VALUES (:id_demande, :type_garantie, :description, :valeur_estimee, :statut)'
);
$insert->execute([
    'id_demande'     => $idDemande,
    'type_garantie'  => $typeGarantie,
    'description'    => $description ?: null,
    'valeur_estimee' => $valeurEstimee,
    'statut'         => 'proposee',
]);

enregistrerAudit('AJOUT_GARANTIE', 'garanties', (int) $pdo->lastInsertId(), 'Garantie ajoutée à la demande ' . $demande['reference']);

rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Garantie ajoutée. Recalculez le scoring pour la prendre en compte.'));
