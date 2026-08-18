<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idEcheance = (int) ($_POST['id_echeance'] ?? 0);
$typeRelance = $_POST['type_relance'] ?? '';
$commentaire = trim($_POST['commentaire'] ?? '');

$typesValides = ['appel', 'sms', 'mise_en_demeure'];
if (!in_array($typeRelance, $typesValides, true)) {
    rediriger('dossier.php?id=' . $idEcheance . '&erreur=' . urlencode('Type de relance invalide.'));
}

$stmt = $pdo->prepare('SELECT id_echeance FROM echeancier WHERE id_echeance = :id');
$stmt->execute(['id' => $idEcheance]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Échéance introuvable.');
}

$pdo->prepare(
    'INSERT INTO relances_recouvrement (id_echeance, type_relance, commentaire, effectue_par) VALUES (:id, :type, :com, :par)'
)->execute(['id' => $idEcheance, 'type' => $typeRelance, 'com' => $commentaire ?: null, 'par' => $_SESSION['id_utilisateur']]);

enregistrerAudit('RELANCE_RECOUVREMENT', 'relances_recouvrement', $idEcheance, 'Relance (' . $typeRelance . ') sur échéance #' . $idEcheance);

rediriger('dossier.php?id=' . $idEcheance . '&succes=' . urlencode('Relance enregistrée.'));
