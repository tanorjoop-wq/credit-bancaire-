<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idClient = (int) ($_POST['id_client'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare('SELECT id_client FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Client introuvable.');
}

if ($action === 'ajouter') {
    $typeActif = $_POST['type_actif'] ?? '';
    $description = trim($_POST['description'] ?? '');
    $valeurEstimee = $_POST['valeur_estimee'] ?? '';
    $typesValides = ['immobilier', 'vehicule', 'epargne', 'autre'];

    if (!in_array($typeActif, $typesValides, true) || !is_numeric($valeurEstimee) || (float) $valeurEstimee <= 0) {
        rediriger('voir.php?id=' . $idClient . '&erreur=' . urlencode('Actif invalide : vérifiez le type et la valeur.'));
    }

    $insert = $pdo->prepare(
        'INSERT INTO patrimoine_client (id_client, type_actif, description, valeur_estimee, date_evaluation, cree_par)
         VALUES (:id_client, :type_actif, :description, :valeur_estimee, CURDATE(), :cree_par)'
    );
    $insert->execute([
        'id_client'      => $idClient,
        'type_actif'     => $typeActif,
        'description'    => $description ?: null,
        'valeur_estimee' => $valeurEstimee,
        'cree_par'       => $_SESSION['id_utilisateur'],
    ]);

    enregistrerAudit('AJOUT_PATRIMOINE', 'patrimoine_client', (int) $pdo->lastInsertId(), 'Ajout d\'un actif (' . $typeActif . ') pour le client #' . $idClient);
    rediriger('voir.php?id=' . $idClient . '&succes=' . urlencode('Actif ajouté avec succès.'));
} elseif ($action === 'supprimer') {
    $idPatrimoine = (int) ($_POST['id_patrimoine'] ?? 0);

    $verif = $pdo->prepare('SELECT id_patrimoine FROM patrimoine_client WHERE id_patrimoine = :id AND id_client = :id_client');
    $verif->execute(['id' => $idPatrimoine, 'id_client' => $idClient]);
    if ($verif->fetch()) {
        $pdo->prepare('DELETE FROM patrimoine_client WHERE id_patrimoine = :id')->execute(['id' => $idPatrimoine]);
        enregistrerAudit('SUPPRESSION_PATRIMOINE', 'patrimoine_client', $idPatrimoine, 'Suppression d\'un actif du client #' . $idClient);
    }

    rediriger('voir.php?id=' . $idClient . '&succes=' . urlencode('Actif supprimé.'));
} else {
    rediriger('voir.php?id=' . $idClient);
}
