<?php
/**
 * Calcul de rentabilité ajustée du risque (RAROC) pour une demande de crédit.
 *
 * Hypothèses paramétrables (documentées pour la soutenance) :
 *   - Taux de refinancement de la banque : 5,5% / an (approximé sur le capital initial)
 *   - Charges opératoires forfaitaires : 1,5% de l'exposition (EAD)
 *   - Facteur de capital économique réglementaire : 8% de l'EAD (type ratio Bâle)
 *   - LGD (Loss Given Default) : 1 − taux de couverture par les garanties, plancher 15%
 *   - Seuil cible de RAROC pour juger un dossier "rentable" : 15%
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ScoringEngine.php';
require_once __DIR__ . '/../../includes/EcheancierGenerator.php';
exigerRole(['administrateur', 'comite_direction']);

const TAUX_REFINANCEMENT = 5.5;
const CHARGES_OPERATOIRES_POURCENT = 1.5;
const FACTEUR_CAPITAL_ECONOMIQUE = 8.0;
const LGD_PLANCHER = 15.0;
const SEUIL_RAROC_CIBLE = 15.0;

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

// --- EAD (Exposition au Défaut) : montant du contrat s'il existe, sinon montant demandé ---
$stmtContrat = $pdo->prepare('SELECT * FROM contrats WHERE id_demande = :id');
$stmtContrat->execute(['id' => $idDemande]);
$contrat = $stmtContrat->fetch();

$ead = $contrat ? (float) $contrat['montant_accorde'] : (float) $demande['montant_demande'];
$duree = $contrat ? (int) $contrat['duree_mois'] : (int) $demande['duree_mois'];
$taux = $contrat ? (float) $contrat['taux_final'] : (float) $demande['taux_interet_propose'];

// --- Intérêts bruts projetés sur la durée du prêt ---
if ($contrat) {
    $stmtEcheances = $pdo->prepare('SELECT COALESCE(SUM(interet), 0) FROM echeancier WHERE id_contrat = :id');
    $stmtEcheances->execute(['id' => $contrat['id_contrat']]);
    $interetsBruts = (float) $stmtEcheances->fetchColumn();
} else {
    $generateur = new GenerateurEcheancier();
    $tableau = $generateur->genererTableau($ead, $duree, $taux, date('Y-m-d'));
    $interetsBruts = array_sum(array_column($tableau, 'interet'));
}

// Coût de refinancement calculé sur l'encours MOYEN (≈ EAD/2 pour un prêt amortissable
// à annuités constantes), cohérent avec les intérêts perçus qui sont eux aussi calculés
// sur un capital restant dû dégressif — sinon la comparaison serait faussée (capital
// initial complet vs capital dégressif).
$coutRefinancement = ($ead / 2) * (TAUX_REFINANCEMENT / 100) * ($duree / 12);
$margeNetteInteret = $interetsBruts - $coutRefinancement;

// --- PD : scoring avancé en priorité, sinon scoring de base, sinon estimation prudente ---
$stmtScoringAvance = $pdo->prepare('SELECT score_global FROM vue_scoring_avance_actuel WHERE id_demande = :id');
$stmtScoringAvance->execute(['id' => $idDemande]);
$scoreAvance = $stmtScoringAvance->fetchColumn();

$stmtScoringBase = $pdo->prepare('SELECT probabilite_defaut FROM scoring WHERE id_demande = :id');
$stmtScoringBase->execute(['id' => $idDemande]);
$pdBase = $stmtScoringBase->fetchColumn();

if ($scoreAvance !== false) {
    // PD dérivée du score global avancé (même logique non linéaire que MoteurScoring)
    $probabiliteDefaut = round(50 * (1 - (float) $scoreAvance / 100) ** 2, 2);
    $probabiliteDefaut = max(1.0, min(50.0, $probabiliteDefaut));
} elseif ($pdBase !== false) {
    $probabiliteDefaut = (float) $pdBase;
} else {
    $probabiliteDefaut = 15.0; // hypothèse prudente si aucun scoring n'a encore été calculé
}

// --- LGD : dérivée du taux de couverture par les garanties ---
$stmtGaranties = $pdo->prepare("SELECT COALESCE(SUM(valeur_estimee), 0) FROM garanties WHERE id_demande = :id AND statut != 'rejetee'");
$stmtGaranties->execute(['id' => $idDemande]);
$valeurGaranties = (float) $stmtGaranties->fetchColumn();

$tauxCouverture = $ead > 0 ? min(1, $valeurGaranties / $ead) : 0;
$lgd = max(LGD_PLANCHER, (1 - $tauxCouverture) * 100);

$coutDuRisque = ($probabiliteDefaut / 100) * ($lgd / 100) * $ead;
$chargesOperatoires = $ead * (CHARGES_OPERATOIRES_POURCENT / 100);
$capitalEconomique = $ead * (FACTEUR_CAPITAL_ECONOMIQUE / 100);

$gainNetAjuste = $margeNetteInteret - $coutDuRisque - $chargesOperatoires;
$raroc = $capitalEconomique > 0 ? round(($gainNetAjuste / $capitalEconomique) * 100, 2) : 0;
$verdict = $raroc >= SEUIL_RAROC_CIBLE ? 'rentable' : 'marge_insuffisante';

$stmtInsert = $pdo->prepare(
    'INSERT INTO rentabilite_demande (id_demande, interets_bruts, cout_refinancement, marge_nette_interet,
        probabilite_defaut, perte_en_cas_defaut, exposition_defaut, cout_du_risque, charges_operatoires,
        capital_economique, gain_net_ajuste, raroc, seuil_cible, verdict, calcule_par)
     VALUES (:id_demande, :interets_bruts, :cout_refinancement, :mni, :pd, :lgd, :ead, :cout_risque,
        :charges_op, :capital_eco, :gain_net, :raroc, :seuil, :verdict, :calcule_par)
     ON DUPLICATE KEY UPDATE
        interets_bruts = VALUES(interets_bruts), cout_refinancement = VALUES(cout_refinancement),
        marge_nette_interet = VALUES(marge_nette_interet), probabilite_defaut = VALUES(probabilite_defaut),
        perte_en_cas_defaut = VALUES(perte_en_cas_defaut), exposition_defaut = VALUES(exposition_defaut),
        cout_du_risque = VALUES(cout_du_risque), charges_operatoires = VALUES(charges_operatoires),
        capital_economique = VALUES(capital_economique), gain_net_ajuste = VALUES(gain_net_ajuste),
        raroc = VALUES(raroc), verdict = VALUES(verdict), calcule_par = VALUES(calcule_par),
        date_calcul = CURRENT_TIMESTAMP'
);
$stmtInsert->execute([
    'id_demande' => $idDemande, 'interets_bruts' => $interetsBruts, 'cout_refinancement' => $coutRefinancement,
    'mni' => $margeNetteInteret, 'pd' => $probabiliteDefaut, 'lgd' => $lgd, 'ead' => $ead,
    'cout_risque' => $coutDuRisque, 'charges_op' => $chargesOperatoires, 'capital_eco' => $capitalEconomique,
    'gain_net' => $gainNetAjuste, 'raroc' => $raroc, 'seuil' => SEUIL_RAROC_CIBLE, 'verdict' => $verdict,
    'calcule_par' => $_SESSION['id_utilisateur'],
]);

enregistrerAudit('CALCUL_RENTABILITE', 'rentabilite_demande', $idDemande, 'RAROC ' . $raroc . '% (' . $verdict . ') pour la demande #' . $idDemande);

rediriger('voir.php?id_demande=' . $idDemande . '&succes=' . urlencode('Rentabilité calculée.'));
