<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idEcheance = (int) ($_POST['id_echeance'] ?? 0);
$montantPaye = $_POST['montant_paye'] ?? '';
$modePaiement = $_POST['mode_paiement'] ?? '';
$modesValides = ['virement', 'especes', 'mobile_money', 'prelevement'];

$stmt = $pdo->prepare(
    'SELECT e.*, c.id_contrat, c.numero_contrat, c.statut AS statut_contrat
     FROM echeancier e
     JOIN contrats c ON c.id_contrat = e.id_contrat
     WHERE e.id_echeance = :id'
);
$stmt->execute(['id' => $idEcheance]);
$echeance = $stmt->fetch();

if (!$echeance) {
    http_response_code(404);
    die('Échéance introuvable.');
}

if ($echeance['statut'] === 'payee') {
    rediriger('voir.php?id=' . $echeance['id_contrat'] . '&erreur=' . urlencode('Cette échéance est déjà payée.'));
}
if ($echeance['statut'] === 'annulee') {
    rediriger('voir.php?id=' . $echeance['id_contrat'] . '&erreur=' . urlencode('Cette échéance a été annulée par une restructuration.'));
}
if ($echeance['statut_contrat'] !== 'decaisse') {
    rediriger('voir.php?id=' . $echeance['id_contrat'] . '&erreur=' . urlencode('Le contrat doit être décaissé avant tout remboursement.'));
}
if (!is_numeric($montantPaye) || (float) $montantPaye <= 0 || !in_array($modePaiement, $modesValides, true)) {
    rediriger('voir.php?id=' . $echeance['id_contrat'] . '&erreur=' . urlencode('Montant ou mode de paiement invalide.'));
}

$pdo->beginTransaction();

$insert = $pdo->prepare(
    'INSERT INTO remboursements (id_echeance, date_paiement, montant_paye, mode_paiement, enregistre_par)
     VALUES (:id_echeance, CURDATE(), :montant_paye, :mode_paiement, :enregistre_par)'
);
$insert->execute([
    'id_echeance'    => $idEcheance,
    'montant_paye'   => $montantPaye,
    'mode_paiement'  => $modePaiement,
    'enregistre_par' => $_SESSION['id_utilisateur'],
]);

$majEcheance = $pdo->prepare("UPDATE echeancier SET statut = 'payee' WHERE id_echeance = :id");
$majEcheance->execute(['id' => $idEcheance]);

// Si toutes les échéances du contrat sont payées, le contrat (et la demande) passent à 'soldé'
$stmtRestantes = $pdo->prepare(
    "SELECT COUNT(*) FROM echeancier WHERE id_contrat = :id_contrat AND statut != 'payee'"
);
$stmtRestantes->execute(['id_contrat' => $echeance['id_contrat']]);
$echeancesRestantes = (int) $stmtRestantes->fetchColumn();

if ($echeancesRestantes === 0) {
    $pdo->prepare("UPDATE contrats SET statut = 'solde' WHERE id_contrat = :id")
        ->execute(['id' => $echeance['id_contrat']]);

    $stmtDemande = $pdo->prepare('SELECT id_demande FROM contrats WHERE id_contrat = :id');
    $stmtDemande->execute(['id' => $echeance['id_contrat']]);
    $idDemande = $stmtDemande->fetchColumn();
    $pdo->prepare("UPDATE demandes_credit SET statut = 'solde' WHERE id_demande = :id")
        ->execute(['id' => $idDemande]);
}

$pdo->commit();

enregistrerAudit(
    'ENREGISTREMENT_REMBOURSEMENT',
    'remboursements',
    $idEcheance,
    'Paiement échéance n°' . $echeance['numero_echeance'] . ' du contrat ' . $echeance['numero_contrat']
);

rediriger('voir.php?id=' . $echeance['id_contrat'] . '&succes=' . urlencode('Remboursement enregistré avec succès.'));
