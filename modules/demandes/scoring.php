<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ScoringEngine.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT d.*, c.type_client, c.revenu_mensuel, c.chiffre_affaires, c.anciennete_bancaire_mois
     FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     WHERE d.id_demande = :id'
);
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

if (in_array($demande['statut'], ['approuve', 'refuse', 'decaisse', 'solde'], true)) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Le scoring ne peut plus être recalculé après décision.'));
}

// Garanties validées ou proposées associées à la demande
$stmtGaranties = $pdo->prepare(
    "SELECT COALESCE(SUM(valeur_estimee), 0) FROM garanties WHERE id_demande = :id AND statut != 'rejetee'"
);
$stmtGaranties->execute(['id' => $idDemande]);
$valeurGaranties = (float) $stmtGaranties->fetchColumn();

$moteur = new MoteurScoring();
$resultat = $moteur->evaluer($demande, $demande, $valeurGaranties);

// INSERT ... ON DUPLICATE KEY UPDATE : id_demande est UNIQUE dans la table scoring
$stmtScoring = $pdo->prepare(
    'INSERT INTO scoring (id_demande, capacite_remboursement, taux_endettement, valeur_garanties,
        score_total, grade, probabilite_defaut, calcule_par)
     VALUES (:id_demande, :capacite_remboursement, :taux_endettement, :valeur_garanties,
        :score_total, :grade, :probabilite_defaut, :calcule_par)
     ON DUPLICATE KEY UPDATE
        capacite_remboursement = VALUES(capacite_remboursement),
        taux_endettement = VALUES(taux_endettement),
        valeur_garanties = VALUES(valeur_garanties),
        score_total = VALUES(score_total),
        grade = VALUES(grade),
        probabilite_defaut = VALUES(probabilite_defaut),
        calcule_par = VALUES(calcule_par),
        date_calcul = CURRENT_TIMESTAMP'
);
$stmtScoring->execute([
    'id_demande'             => $idDemande,
    'capacite_remboursement' => $resultat['capacite_remboursement'],
    'taux_endettement'       => $resultat['taux_endettement'],
    'valeur_garanties'       => $valeurGaranties,
    'score_total'            => $resultat['score_total'],
    'grade'                  => $resultat['grade'],
    'probabilite_defaut'     => $resultat['probabilite_defaut'],
    'calcule_par'            => $_SESSION['id_utilisateur'],
]);

$stmtStatut = $pdo->prepare("UPDATE demandes_credit SET statut = 'scoring_effectue' WHERE id_demande = :id");
$stmtStatut->execute(['id' => $idDemande]);

enregistrerAudit(
    'CALCUL_SCORING',
    'scoring',
    $idDemande,
    "Score {$resultat['score_total']}/100 (grade {$resultat['grade']}) pour la demande " . $demande['reference']
);

rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Scoring calculé avec succès.'));
