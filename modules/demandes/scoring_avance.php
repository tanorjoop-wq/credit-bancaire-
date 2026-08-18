<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AnalyseFinanciere.php';
require_once __DIR__ . '/../../includes/ScoringEngine.php';
require_once __DIR__ . '/../../includes/ScoringAvance.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT d.*, c.* FROM demandes_credit d
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
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Le scoring avancé ne peut plus être recalculé après décision.'));
}

$idClient = (int) $demande['id_client'];

$stmtDonnees = $pdo->prepare('SELECT * FROM donnees_financieres WHERE id_client = :id ORDER BY date_exercice DESC, id_donnee DESC LIMIT 1');
$stmtDonnees->execute(['id' => $idClient]);
$donneesFinancieres = $stmtDonnees->fetch();

if (!$donneesFinancieres) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Saisissez d\'abord les données financières du client (onglet Analyse financière).'));
}

// --- Ratios financiers normalisés ---
$moteurBase = new MoteurScoring();
$echeanceMensuelle = $moteurBase->calculerEcheanceMensuelle(
    (float) $demande['montant_demande'],
    (int) $demande['duree_mois'],
    (float) $demande['taux_interet_propose']
);

$analyseur = new AnalyseFinanciere($pdo);
$estEntreprise = $demande['type_client'] === 'entreprise';
$resultatFinancier = $estEntreprise
    ? $analyseur->calculerEntreprise($donneesFinancieres, $echeanceMensuelle)
    : $analyseur->calculerParticulier($demande, $donneesFinancieres, $echeanceMensuelle);

$ratiosNormalises = [
    'couleur_dscr'        => $resultatFinancier['couleur_dscr'],
    'couleur_endettement' => $resultatFinancier['couleur_endettement'],
    'couleur_liquidite'   => $estEntreprise ? $resultatFinancier['couleur_tresorerie'] : $resultatFinancier['couleur_reste_a_vivre'],
    'dscr'                => $resultatFinancier['dscr'],
    'taux_endettement'    => $estEntreprise ? $resultatFinancier['taux_endettement_net'] : $resultatFinancier['taux_endettement'],
];

// --- Patrimoine ---
$stmtPatrimoine = $pdo->prepare('SELECT COALESCE(SUM(valeur_estimee), 0) FROM patrimoine_client WHERE id_client = :id');
$stmtPatrimoine->execute(['id' => $idClient]);
$patrimoineNet = (float) $stmtPatrimoine->fetchColumn();

// --- Historique de paiement (tous contrats du client) ---
$stmtHistorique = $pdo->prepare(
    "SELECT e.statut FROM echeancier e
     JOIN contrats c ON c.id_contrat = e.id_contrat
     JOIN demandes_credit dc ON dc.id_demande = c.id_demande
     WHERE dc.id_client = :id_client AND e.statut IN ('payee', 'en_retard', 'impayee')"
);
$stmtHistorique->execute(['id_client' => $idClient]);
$echeancesDues = $stmtHistorique->fetchAll(PDO::FETCH_COLUMN);
$totalDues = count($echeancesDues);
$totalPayees = count(array_filter($echeancesDues, fn($s) => $s === 'payee'));
$tauxPaiementATemps = $totalDues > 0 ? $totalPayees / $totalDues : null;

$moteurAvance = new MoteurScoringAvance($pdo);
$resultat = $moteurAvance->evaluer(
    $ratiosNormalises,
    $patrimoineNet,
    (float) $demande['montant_demande'],
    $tauxPaiementATemps,
    (int) $demande['anciennete_bancaire_mois']
);

// Chaque calcul insère une nouvelle ligne (historisation — cf. vue_scoring_avance_actuel
// pour la valeur "courante"), au lieu d'écraser l'évaluation précédente : la matrice de
// migration de risque (Module 10) a besoin de tout l'historique.
$stmtInsert = $pdo->prepare(
    'INSERT INTO scoring_avance (id_demande, note_globale, score_financier, score_patrimonial, score_comportemental,
        score_global, facteur_positif_1, facteur_positif_2, facteur_positif_3,
        facteur_risque_1, facteur_risque_2, facteur_risque_3, calcule_par)
     VALUES (:id_demande, :note_globale, :score_financier, :score_patrimonial, :score_comportemental,
        :score_global, :fp1, :fp2, :fp3, :fr1, :fr2, :fr3, :calcule_par)'
);
$stmtInsert->execute([
    'id_demande'           => $idDemande,
    'note_globale'         => $resultat['note_globale'],
    'score_financier'      => $resultat['score_financier'],
    'score_patrimonial'    => $resultat['score_patrimonial'],
    'score_comportemental' => $resultat['score_comportemental'],
    'score_global'         => $resultat['score_global'],
    'fp1' => $resultat['facteurs_positifs'][0], 'fp2' => $resultat['facteurs_positifs'][1], 'fp3' => $resultat['facteurs_positifs'][2],
    'fr1' => $resultat['facteurs_risque'][0], 'fr2' => $resultat['facteurs_risque'][1], 'fr3' => $resultat['facteurs_risque'][2],
    'calcule_par' => $_SESSION['id_utilisateur'],
]);

enregistrerAudit('CALCUL_SCORING_AVANCE', 'scoring_avance', $idDemande, 'Score avancé ' . $resultat['note_globale'] . ' (' . $resultat['score_global'] . '/100) pour la demande #' . $idDemande);

rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Scoring avancé calculé.'));
