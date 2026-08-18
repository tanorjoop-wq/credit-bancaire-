<?php
/**
 * Génère un contrat + son échéancier d'amortissement pour une demande approuvée.
 * Appelé automatiquement depuis modules/demandes/decision.php après une décision
 * favorable du comité, et disponible manuellement pour les demandes déjà
 * approuvées qui n'ont pas encore de contrat.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/EcheancierGenerator.php';

/**
 * @return int|null id_contrat créé, ou null si la demande n'est pas éligible.
 */
function genererContratPourDemande(PDO $pdo, int $idDemande): ?int
{
    $stmt = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
    $stmt->execute(['id' => $idDemande]);
    $demande = $stmt->fetch();

    if (!$demande || $demande['statut'] !== 'approuve') {
        return null;
    }

    $verifContrat = $pdo->prepare('SELECT id_contrat FROM contrats WHERE id_demande = :id');
    $verifContrat->execute(['id' => $idDemande]);
    if ($verifContrat->fetchColumn()) {
        return null; // contrat déjà existant
    }

    $annee = date('Y');
    $stmtRef = $pdo->prepare("SELECT numero_contrat FROM contrats WHERE numero_contrat LIKE :motif ORDER BY id_contrat DESC LIMIT 1");
    $stmtRef->execute(['motif' => "CTR-{$annee}-%"]);
    $dernier = $stmtRef->fetchColumn();
    $prochainNumero = $dernier ? ((int) substr($dernier, -4) + 1) : 1;
    $numeroContrat = sprintf('CTR-%s-%04d', $annee, $prochainNumero);

    $pdo->beginTransaction();

    $insertContrat = $pdo->prepare(
        'INSERT INTO contrats (id_demande, numero_contrat, montant_accorde, taux_final, duree_mois, statut)
         VALUES (:id_demande, :numero_contrat, :montant, :taux, :duree, :statut)'
    );
    $insertContrat->execute([
        'id_demande'    => $idDemande,
        'numero_contrat'=> $numeroContrat,
        'montant'       => $demande['montant_demande'],
        'taux'          => $demande['taux_interet_propose'],
        'duree'         => $demande['duree_mois'],
        'statut'        => 'en_preparation',
    ]);
    $idContrat = (int) $pdo->lastInsertId();

    $generateur = new GenerateurEcheancier();
    $tableau = $generateur->genererTableau(
        (float) $demande['montant_demande'],
        (int) $demande['duree_mois'],
        (float) $demande['taux_interet_propose'],
        date('Y-m-d')
    );

    $insertEcheance = $pdo->prepare(
        'INSERT INTO echeancier (id_contrat, numero_echeance, date_echeance, capital, interet, montant_echeance, capital_restant_du, statut)
         VALUES (:id_contrat, :numero_echeance, :date_echeance, :capital, :interet, :montant_echeance, :capital_restant_du, :statut)'
    );
    foreach ($tableau as $echeance) {
        $insertEcheance->execute([
            'id_contrat'          => $idContrat,
            'numero_echeance'     => $echeance['numero_echeance'],
            'date_echeance'       => $echeance['date_echeance'],
            'capital'             => $echeance['capital'],
            'interet'             => $echeance['interet'],
            'montant_echeance'    => $echeance['montant_echeance'],
            'capital_restant_du'  => $echeance['capital_restant_du'],
            'statut'              => 'a_venir',
        ]);
    }

    $pdo->commit();

    enregistrerAudit('GENERATION_CONTRAT', 'contrats', $idContrat, 'Génération du contrat ' . $numeroContrat . ' (' . count($tableau) . ' échéances)');

    return $idContrat;
}

// --- Point d'entrée HTTP (déclenchement manuel) ---
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    exigerRole(['administrateur', 'charge_clientele']);

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        rediriger(BASE_URL . '/modules/demandes/liste.php');
    }
    verifierJetonCSRF();

    global $pdo;
    $idDemande = (int) ($_POST['id_demande'] ?? 0);
    $idContrat = genererContratPourDemande($pdo, $idDemande);

    if ($idContrat === null) {
        rediriger(BASE_URL . '/modules/demandes/voir.php?id=' . $idDemande . '&erreur=' . urlencode('Contrat déjà existant ou demande non approuvée.'));
    }

    rediriger(BASE_URL . '/modules/contrats/voir.php?id=' . $idContrat . '&succes=' . urlencode('Contrat généré avec échéancier.'));
}
