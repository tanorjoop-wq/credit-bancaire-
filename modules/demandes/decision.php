<?php
/**
 * Actions du workflow d'approbation : transmission au comité, puis décision du comité.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../contrats/generer.php';
exigerConnexion();

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

if ($action === 'transmettre') {
    exigerRole(['administrateur', 'charge_clientele']);

    if ($demande['statut'] !== 'scoring_effectue') {
        rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Le scoring doit être effectué avant transmission au comité.'));
    }

    $commentaire = trim($_POST['commentaire'] ?? '');

    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        "INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision)
         VALUES (:id_demande, 'charge_clientele', :decideur_id, 'favorable', :commentaire, NOW())"
    );
    $insert->execute([
        'id_demande'  => $idDemande,
        'decideur_id' => $_SESSION['id_utilisateur'],
        'commentaire' => $commentaire ?: 'Dossier transmis au comité pour décision.',
    ]);

    $maj = $pdo->prepare("UPDATE demandes_credit SET statut = 'en_comite' WHERE id_demande = :id");
    $maj->execute(['id' => $idDemande]);

    $pdo->commit();

    enregistrerAudit('TRANSMISSION_COMITE', 'demandes_credit', $idDemande, 'Transmission au comité de la demande ' . $demande['reference']);

    rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Demande transmise au comité.'));
} elseif ($action === 'decision_comite') {
    exigerRole(['administrateur', 'comite_direction']);

    if ($demande['statut'] !== 'en_comite') {
        rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Cette demande n\'est pas en attente de décision du comité.'));
    }

    $decision = $_POST['decision'] ?? '';
    $commentaire = trim($_POST['commentaire'] ?? '');

    if (!in_array($decision, ['favorable', 'defavorable'], true)) {
        rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Décision invalide.'));
    }

    $pdo->beginTransaction();

    $insert = $pdo->prepare(
        "INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision)
         VALUES (:id_demande, 'comite', :decideur_id, :decision, :commentaire, NOW())"
    );
    $insert->execute([
        'id_demande'  => $idDemande,
        'decideur_id' => $_SESSION['id_utilisateur'],
        'decision'    => $decision,
        'commentaire' => $commentaire ?: null,
    ]);

    $nouveauStatut = $decision === 'favorable' ? 'approuve' : 'refuse';
    $maj = $pdo->prepare('UPDATE demandes_credit SET statut = :statut, date_decision = NOW() WHERE id_demande = :id');
    $maj->execute(['statut' => $nouveauStatut, 'id' => $idDemande]);

    $pdo->commit();

    enregistrerAudit(
        'DECISION_COMITE',
        'demandes_credit',
        $idDemande,
        'Décision ' . $decision . ' du comité sur la demande ' . $demande['reference']
    );

    // Génération automatique du contrat + échéancier dès l'approbation
    if ($decision === 'favorable') {
        genererContratPourDemande($pdo, $idDemande);
    }

    rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Décision du comité enregistrée.'));
} else {
    rediriger('voir.php?id=' . $idDemande);
}
