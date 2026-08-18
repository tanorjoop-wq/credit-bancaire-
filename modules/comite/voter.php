<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../contrats/generer.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('file_attente.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);
$decision = $_POST['decision'] ?? '';
$commentaire = trim($_POST['commentaire'] ?? '');
$conditions = trim($_POST['conditions'] ?? '');

$decisionsValides = ['favorable', 'favorable_conditionnel', 'defavorable'];
if (!in_array($decision, $decisionsValides, true)) {
    rediriger('synthese.php?id=' . $idDemande . '&erreur=' . urlencode('Décision invalide.'));
}
if ($decision === 'defavorable' && $commentaire === '') {
    rediriger('synthese.php?id=' . $idDemande . '&erreur=' . urlencode('Un motif est obligatoire pour un vote défavorable.'));
}
if ($decision === 'favorable_conditionnel' && $conditions === '') {
    rediriger('synthese.php?id=' . $idDemande . '&erreur=' . urlencode('Précisez les conditions/garanties exigées pour un vote conditionnel.'));
}

$stmt = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande || $demande['statut'] !== 'en_comite') {
    rediriger('file_attente.php?erreur=' . urlencode('Ce dossier n\'est plus en attente de décision comité.'));
}

$dateTransmission = $pdo->prepare("SELECT COALESCE(MAX(date_decision), '1970-01-01') FROM workflow_approbation WHERE id_demande = :id AND niveau = 'charge_clientele'");
$dateTransmission->execute(['id' => $idDemande]);
$dateTransmission = $dateTransmission->fetchColumn();

$stmtDejaVote = $pdo->prepare(
    "SELECT COUNT(*) FROM workflow_approbation WHERE id_demande = :id AND niveau = 'comite' AND decideur_id = :u AND date_decision > :date"
);
$stmtDejaVote->execute(['id' => $idDemande, 'u' => $_SESSION['id_utilisateur'], 'date' => $dateTransmission]);
if ((int) $stmtDejaVote->fetchColumn() > 0) {
    rediriger('synthese.php?id=' . $idDemande . '&erreur=' . urlencode('Vous avez déjà voté sur ce dossier pour ce cycle.'));
}

$pdo->prepare(
    'INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, conditions, date_decision)
     VALUES (:id, "comite", :u, :decision, :commentaire, :conditions, NOW())'
)->execute([
    'id' => $idDemande, 'u' => $_SESSION['id_utilisateur'], 'decision' => $decision,
    'commentaire' => $commentaire ?: null, 'conditions' => $conditions ?: null,
]);

enregistrerAudit('VOTE_COMITE', 'workflow_approbation', $idDemande, 'Vote ' . $decision . ' sur la demande ' . $demande['reference']);

// --- Vérification du quorum ---
$nbComiteActifs = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'comite_direction' AND actif = 1")->fetchColumn();
$quorum = intdiv($nbComiteActifs, 2) + 1;

$stmtVotes = $pdo->prepare(
    "SELECT decision, conditions FROM workflow_approbation WHERE id_demande = :id AND niveau = 'comite' AND date_decision > :date"
);
$stmtVotes->execute(['id' => $idDemande, 'date' => $dateTransmission]);
$votes = $stmtVotes->fetchAll();

$favorables = array_filter($votes, fn($v) => in_array($v['decision'], ['favorable', 'favorable_conditionnel'], true));
$defavorables = array_filter($votes, fn($v) => $v['decision'] === 'defavorable');
$conditionsPosees = array_filter(array_column($favorables, 'conditions'));

$message = 'Vote enregistré.';

if (count($favorables) >= $quorum) {
    $ancienStatut = $demande['statut'];
    $pdo->prepare("UPDATE demandes_credit SET statut = 'approuve', date_decision = NOW() WHERE id_demande = :id")->execute(['id' => $idDemande]);
    audit_avant_apres($pdo, 'RESOLUTION_COMITE_FAVORABLE', 'demandes_credit', $idDemande, $ancienStatut, 'approuve');

    $idContrat = genererContratPourDemande($pdo, $idDemande);
    if ($idContrat && !empty($conditionsPosees)) {
        $pdo->prepare('UPDATE contrats SET conditions_remplies = 0 WHERE id_contrat = :id')->execute(['id' => $idContrat]);
    }

    creerNotification(
        $pdo, (int) $demande['charge_id'], 'important', 'Dossier approuvé par le comité',
        'La demande ' . $demande['reference'] . ' a été approuvée (quorum atteint).',
        BASE_URL . '/modules/demandes/voir.php?id=' . $idDemande
    );
    $message = 'Quorum atteint : dossier approuvé' . (!empty($conditionsPosees) ? ' sous conditions' : '') . '.';
} elseif (count($defavorables) >= $quorum) {
    $ancienStatut = $demande['statut'];
    $pdo->prepare("UPDATE demandes_credit SET statut = 'refuse', date_decision = NOW() WHERE id_demande = :id")->execute(['id' => $idDemande]);
    audit_avant_apres($pdo, 'RESOLUTION_COMITE_DEFAVORABLE', 'demandes_credit', $idDemande, $ancienStatut, 'refuse');

    creerNotification(
        $pdo, (int) $demande['charge_id'], 'important', 'Dossier refusé par le comité',
        'La demande ' . $demande['reference'] . ' a été refusée (quorum atteint).',
        BASE_URL . '/modules/demandes/voir.php?id=' . $idDemande
    );
    $message = 'Quorum atteint : dossier refusé.';
}

rediriger('synthese.php?id=' . $idDemande . '&succes=' . urlencode($message));
