<?php
/**
 * Utilitaire ponctuel : calcule la rentabilité (RAROC) pour tous les contrats
 * décaissés qui n'en ont pas encore, afin que les rollups du Module 11
 * (Rentabilité par produit/agence) soient représentatifs sur tout le portefeuille
 * seedé. Réutilise exactement la même formule que modules/rentabilite/calculer.php
 * (constantes identiques) — ce script ne fait pas partie du runtime applicatif.
 */
require __DIR__ . '/../config/database.php';

const TAUX_REFINANCEMENT = 5.5;
const CHARGES_OPERATOIRES_POURCENT = 1.5;
const FACTEUR_CAPITAL_ECONOMIQUE = 8.0;
const LGD_PLANCHER = 15.0;
const SEUIL_RAROC_CIBLE = 15.0;

$adminId = 1;

$demandes = $pdo->query(
    "SELECT d.id_demande FROM demandes_credit d
     JOIN contrats c ON c.id_demande = d.id_demande
     LEFT JOIN rentabilite_demande r ON r.id_demande = d.id_demande
     WHERE c.statut IN ('decaisse', 'en_defaut') AND r.id_rentabilite IS NULL"
)->fetchAll(PDO::FETCH_COLUMN);

$compteur = 0;
foreach ($demandes as $idDemande) {
    $stmtContrat = $pdo->prepare('SELECT * FROM contrats WHERE id_demande = :id');
    $stmtContrat->execute(['id' => $idDemande]);
    $contrat = $stmtContrat->fetch();
    if (!$contrat) {
        continue;
    }

    $ead = (float) $contrat['montant_accorde'];
    $duree = (int) $contrat['duree_mois'];

    $stmtEcheances = $pdo->prepare('SELECT COALESCE(SUM(interet), 0) FROM echeancier WHERE id_contrat = :id');
    $stmtEcheances->execute(['id' => $contrat['id_contrat']]);
    $interetsBruts = (float) $stmtEcheances->fetchColumn();

    $coutRefinancement = ($ead / 2) * (TAUX_REFINANCEMENT / 100) * ($duree / 12);
    $margeNetteInteret = $interetsBruts - $coutRefinancement;

    $stmtScoringBase = $pdo->prepare('SELECT probabilite_defaut FROM scoring WHERE id_demande = :id');
    $stmtScoringBase->execute(['id' => $idDemande]);
    $pdBase = $stmtScoringBase->fetchColumn();
    $probabiliteDefaut = $pdBase !== false ? (float) $pdBase : 15.0;

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

    $pdo->prepare(
        'INSERT INTO rentabilite_demande (id_demande, interets_bruts, cout_refinancement, marge_nette_interet,
            probabilite_defaut, perte_en_cas_defaut, exposition_defaut, cout_du_risque, charges_operatoires,
            capital_economique, gain_net_ajuste, raroc, seuil_cible, verdict, calcule_par)
         VALUES (:id_demande, :interets_bruts, :cout_refinancement, :mni, :pd, :lgd, :ead, :cout_risque,
            :charges_op, :capital_eco, :gain_net, :raroc, :seuil, :verdict, :calcule_par)'
    )->execute([
        'id_demande' => $idDemande, 'interets_bruts' => $interetsBruts, 'cout_refinancement' => $coutRefinancement,
        'mni' => $margeNetteInteret, 'pd' => $probabiliteDefaut, 'lgd' => $lgd, 'ead' => $ead,
        'cout_risque' => $coutDuRisque, 'charges_op' => $chargesOperatoires, 'capital_eco' => $capitalEconomique,
        'gain_net' => $gainNetAjuste, 'raroc' => $raroc, 'seuil' => SEUIL_RAROC_CIBLE, 'verdict' => $verdict,
        'calcule_par' => $adminId,
    ]);
    $compteur++;
}

echo "Rentabilité calculée pour $compteur demande(s).\n";
