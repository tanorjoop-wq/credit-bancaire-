<?php
/**
 * Seeder Core Banking v3 — volumétrie bancaire réaliste.
 * Exécution : php sql/seed_v3_core_banking.php
 *
 * Réutilise les classes de production (MoteurScoring, GenerateurEcheancier,
 * AnalyseFinanciere, MoteurScoringAvance) pour générer des données cohérentes
 * avec les mêmes formules que l'application — pas de chiffres inventés à la main.
 *
 * Cible : ~50 clients, ~50 demandes/contrats, encours actif ≈ 5 Mds FCFA,
 * distribution 65% actif propre / 10% actif avec impayé / 15% en cours / 10% refusé.
 */

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/ScoringEngine.php';
require __DIR__ . '/../includes/EcheancierGenerator.php';
require __DIR__ . '/../includes/AnalyseFinanciere.php';
require __DIR__ . '/../includes/ScoringAvance.php';

/** @var PDO $pdo */

function audit(PDO $pdo, int $utilisateur, string $action, string $table, ?int $id, string $details): void
{
    $pdo->prepare(
        'INSERT INTO journal_audit (id_utilisateur, action, table_concernee, id_enregistrement, details) VALUES (:u, :a, :t, :id, :d)'
    )->execute(['u' => $utilisateur, 'a' => $action, 't' => $table, 'id' => $id, 'd' => $details]);
}

function prochainNumero(PDO $pdo, string $table, string $colonne, string $prefixe, string $annee): int
{
    $stmt = $pdo->prepare("SELECT $colonne FROM $table WHERE $colonne LIKE :motif ORDER BY $colonne DESC LIMIT 1");
    $stmt->execute(['motif' => "{$prefixe}-{$annee}-%"]);
    $dernier = $stmt->fetchColumn();
    return $dernier ? ((int) substr($dernier, -4) + 1) : 1;
}

echo "=== Seeder Core Banking v3 ===\n";
$annee = date('Y');
$adminId = 1; // acteur système pour l'audit du seed

// ---------------------------------------------------------------------
// 1. AGENCES
// ---------------------------------------------------------------------
$agences = [['Agence Plateau', 'Dakar'], ['Agence Almadies', 'Dakar'], ['Agence Thiès', 'Thiès']];
$idsAgences = [];
foreach ($agences as [$nom, $ville]) {
    $pdo->prepare('INSERT INTO agences (nom, ville) VALUES (:n, :v)')->execute(['n' => $nom, 'v' => $ville]);
    $idsAgences[] = (int) $pdo->lastInsertId();
}
echo "Agences créées : " . count($idsAgences) . "\n";

$pdo->prepare('UPDATE utilisateurs SET id_agence = :a WHERE id_utilisateur = 1')->execute(['a' => $idsAgences[0]]);
$pdo->prepare('UPDATE utilisateurs SET id_agence = :a WHERE id_utilisateur = 2')->execute(['a' => $idsAgences[0]]);
$pdo->prepare('UPDATE utilisateurs SET id_agence = :a WHERE id_utilisateur = 3')->execute(['a' => $idsAgences[1]]);
$pdo->prepare('UPDATE utilisateurs SET id_agence = :a WHERE id_utilisateur = 4')->execute(['a' => $idsAgences[2]]);

// ---------------------------------------------------------------------
// 2. UTILISATEURS SUPPLÉMENTAIRES (comité multi-membres)
// ---------------------------------------------------------------------
$nouveauxComite = [
    ['Diallo', 'Mamadou', 'mamadou.diallo@creditbanque.sn', $idsAgences[0]],
    ['Sarr', 'Aminata', 'aminata.sarr@creditbanque.sn', $idsAgences[1]],
];
$idsComite = [3]; // le compte comité déjà existant
foreach ($nouveauxComite as [$nom, $prenom, $email, $idAgence]) {
    $pdo->prepare(
        'INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe_hash, role, id_agence, actif) VALUES (:n, :p, :e, :h, :r, :a, 1)'
    )->execute([
        'n' => $nom, 'p' => $prenom, 'e' => $email,
        'h' => password_hash('Comite@2026', PASSWORD_DEFAULT),
        'r' => 'comite_direction', 'a' => $idAgence,
    ]);
    $idsComite[] = (int) $pdo->lastInsertId();
}
echo "Comptes comité (total) : " . count($idsComite) . "\n";

$idsCharge = [2, 4]; // chargés de clientèle existants

// ---------------------------------------------------------------------
// 3. CLIENTS SUPPLÉMENTAIRES (24 nouveaux -> total ~50)
// ---------------------------------------------------------------------
$prenoms = ['Cheikh', 'Aissatou', 'Ousmane', 'Ndeye', 'Babacar', 'Mariétou', 'Alioune', 'Rokhaya', 'Elhadji', 'Coumba', 'Pape', 'Adama', 'Moustapha', 'Yacine'];
$noms = ['Sall', 'Mbaye', 'Kane', 'Ndao', 'Diagne', 'Wade', 'Cissé', 'Toure', 'Ndiaye', 'Sarr', 'Faye', 'Gaye'];
$villes = ['Dakar', 'Thiès', 'Rufisque', 'Saint-Louis', 'Kaolack', 'Mbour', 'Ziguinchor'];

$entreprises = [
    ['GIE Artisans du Bois de Rufisque', 'gie', 15000000, 45000000],
    ['GIE Mareyeuses de Mbour', 'gie', 12000000, 35000000],
    ['PME Sénégal Fruits & Légumes', 'pme', 55000000, 120000000],
    ['PME Digital Services Dakar', 'pme', 40000000, 90000000],
    ['PME Kane Matériaux BTP', 'pme', 70000000, 160000000],
    ['SARL Sahel Textile Industries', 'pme', 60000000, 140000000],
    ['SA Groupe Océan Atlantique', 'grande', 400000000, 900000000],
    ['SA Ciments du Sénégal Nord', 'grande', 500000000, 1200000000],
    ['SARL Teranga Pharma Distribution', 'pme', 80000000, 180000000],
    ['GIE Femmes Transformatrices Kaolack', 'gie', 10000000, 28000000],
];

$typesActifsSuivants = ['immobilier', 'vehicule', 'epargne', 'autre'];
$idsClientsNouveaux = [];
$idsClientsEntreprise = [];
$idsClientsParticulier = [];

$compteur = 0;
foreach (range(1, 14) as $i) {
    $prenom = $prenoms[array_rand($prenoms)];
    $nom = $noms[array_rand($noms)];
    $ville = $villes[array_rand($villes)];
    $revenu = rand(45, 480) * 10000; // 450k à 4.8M
    $anciennete = rand(1, 120);
    $telephone = '7' . rand(0, 7) . rand(1000000, 9999999);
    $numeroPiece = '5' . str_pad((string) (1000000000000 + $compteur), 12, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare(
        'INSERT INTO clients (type_client, nom_raison_sociale, prenom, numero_piece, telephone, email, adresse, revenu_mensuel, anciennete_bancaire_mois, cree_par)
         VALUES ("particulier", :nom, :prenom, :piece, :tel, :email, :adresse, :revenu, :anciennete, :cree_par)'
    );
    $stmt->execute([
        'nom' => $nom, 'prenom' => $prenom, 'piece' => $numeroPiece, 'tel' => $telephone,
        'email' => strtolower($prenom . '.' . $nom . $compteur) . '@mail.sn', 'adresse' => $ville,
        'revenu' => $revenu, 'anciennete' => $anciennete, 'cree_par' => $idsCharge[array_rand($idsCharge)],
    ]);
    $id = (int) $pdo->lastInsertId();
    $idsClientsNouveaux[] = $id;
    $idsClientsParticulier[] = $id;
    $compteur++;
}

foreach ($entreprises as [$nomEntreprise, $taille, $caMin, $caMax]) {
    $ville = $villes[array_rand($villes)];
    $ca = rand((int) ($caMin / 100000), (int) ($caMax / 100000)) * 100000;
    $anciennete = rand(6, 156);
    $telephone = '33' . rand(1000000, 9999999);
    $numeroPiece = 'SN-DKR-' . rand(2008, 2023) . '-' . chr(rand(65, 90)) . '-' . rand(1000, 9999);

    $stmt = $pdo->prepare(
        'INSERT INTO clients (type_client, nom_raison_sociale, numero_piece, telephone, email, adresse, chiffre_affaires, anciennete_bancaire_mois, cree_par)
         VALUES ("entreprise", :nom, :piece, :tel, :email, :adresse, :ca, :anciennete, :cree_par)'
    );
    $stmt->execute([
        'nom' => $nomEntreprise, 'piece' => $numeroPiece, 'tel' => $telephone,
        'email' => 'contact@' . strtolower(preg_replace('/[^a-z]/', '', str_replace(' ', '', $nomEntreprise))) . '.sn',
        'adresse' => $ville, 'ca' => $ca, 'anciennete' => $anciennete, 'cree_par' => $idsCharge[array_rand($idsCharge)],
    ]);
    $id = (int) $pdo->lastInsertId();
    $idsClientsNouveaux[] = $id;
    $idsClientsEntreprise[] = ['id' => $id, 'taille' => $taille];
}

echo "Clients créés : " . count($idsClientsNouveaux) . " (total base : voir vérification finale)\n";

// ---------------------------------------------------------------------
// 4. DEMANDES / SCORING / CONTRATS — génération calibrée
// ---------------------------------------------------------------------
$moteurScoring = new MoteurScoring();
$generateurEcheancier = new GenerateurEcheancier();

$typesCredits = ['consommation', 'immobilier', 'investissement', 'tresorerie'];

function montantAleatoire(array $client, array $idsClientsEntreprise): array
{
    foreach ($idsClientsEntreprise as $entreprise) {
        if ($entreprise['id'] === $client['id']) {
            return match ($entreprise['taille']) {
                'gie'    => [rand(20, 60) * 1000000, false],
                'pme'    => [rand(40, 200) * 1000000, false],
                'grande' => [rand(150, 500) * 1000000, false],
                default  => [rand(20, 80) * 1000000, false],
            };
        }
    }
    return [rand(20, 80) * 1000000, true]; // particulier
}

$demandesActives = [];   // decaissées "propres"
$demandesImpayees = [];  // decaissées avec impayé
$idDemandeCourant = (int) $pdo->query('SELECT COALESCE(MAX(id_demande), 0) FROM demandes_credit')->fetchColumn();

$tousLesClients = array_merge(
    array_map(fn($id) => ['id' => $id], $idsClientsParticulier),
    array_map(fn($e) => ['id' => $e['id']], $idsClientsEntreprise)
);

function creerDemande(PDO $pdo, array $client, int $chargeId, float $montant, int $duree, float $taux, string $statut): int
{
    global $annee;
    $prochain = prochainNumero($pdo, 'demandes_credit', 'reference', 'CRD', $annee);
    $reference = sprintf('CRD-%s-%04d', $annee, $prochain);
    $typeCredit = ['consommation', 'immobilier', 'investissement', 'tresorerie'][array_rand(['consommation', 'immobilier', 'investissement', 'tresorerie'])];

    $stmt = $pdo->prepare(
        'INSERT INTO demandes_credit (reference, id_client, type_credit, montant_demande, duree_mois, taux_interet_propose, objet_credit, statut, charge_id)
         VALUES (:ref, :client, :type, :montant, :duree, :taux, :objet, :statut, :charge)'
    );
    $stmt->execute([
        'ref' => $reference, 'client' => $client['id'], 'type' => $typeCredit, 'montant' => $montant,
        'duree' => $duree, 'taux' => $taux, 'objet' => 'Financement activité', 'statut' => $statut, 'charge' => $chargeId,
    ]);
    return (int) $pdo->lastInsertId();
}

// --- 35 demandes décaissées "propres" (30) + avec impayé (5) : total 35 nouvelles ---
$sommeGeneree = 0.0;
$montantsBruts = [];
for ($i = 0; $i < 35; $i++) {
    $client = $tousLesClients[array_rand($tousLesClients)];
    [$montant, ] = montantAleatoire($client, $idsClientsEntreprise);
    $montantsBruts[] = $montant;
    $sommeGeneree += $montant;
}
// Calibrage : on vise ~5 Mds FCFA d'ENCOURS actif (capital restant dû), pas le montant brut
// accordé — comme une partie des contrats a jusqu'à 18 mois d'ancienneté et a donc déjà été
// partiellement remboursée, on sur-cale le montant brut généré (facteur empirique ~1.21,
// observé sur un premier run : ~4.12 Mds d'encours pour 5 Mds bruts, soit ~82% de rétention).
$facteurEchelle = (5000000000 * 1.21) / max(1, $sommeGeneree);
foreach ($montantsBruts as &$m) {
    $m = round(($m * $facteurEchelle) / 100000) * 100000; // arrondi au 100 000 FCFA
}
unset($m);

for ($i = 0; $i < 35; $i++) {
    $client = $tousLesClients[array_rand($tousLesClients)];
    $chargeId = $idsCharge[array_rand($idsCharge)];
    $montant = $montantsBruts[$i];
    $duree = [12, 24, 36, 48, 60, 84, 120][array_rand([12, 24, 36, 48, 60, 84, 120])];
    $taux = round(rand(650, 1150) / 100, 2); // 6.5% à 11.5%

    $idDemande = creerDemande($pdo, $client, $chargeId, $montant, $duree, $taux, 'en_attente');

    // Scoring de base
    $stmtClient = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
    $stmtClient->execute(['id' => $client['id']]);
    $clientRow = $stmtClient->fetch();
    $stmtDemande = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
    $stmtDemande->execute(['id' => $idDemande]);
    $demandeRow = $stmtDemande->fetch();

    $resultatScoring = $moteurScoring->evaluer($clientRow, $demandeRow, 0.0);
    $pdo->prepare(
        'INSERT INTO scoring (id_demande, capacite_remboursement, taux_endettement, valeur_garanties, score_total, grade, probabilite_defaut, calcule_par)
         VALUES (:id, :cap, :end, 0, :score, :grade, :pd, :calc)'
    )->execute([
        'id' => $idDemande, 'cap' => $resultatScoring['capacite_remboursement'], 'end' => $resultatScoring['taux_endettement'],
        'score' => $resultatScoring['score_total'], 'grade' => $resultatScoring['grade'], 'pd' => $resultatScoring['probabilite_defaut'],
        'calc' => $chargeId,
    ]);

    // Workflow : transmission + décision favorable (comité aléatoire)
    $pdo->prepare("INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision) VALUES (:id, 'charge_clientele', :d, 'favorable', 'Dossier complet', NOW())")
        ->execute(['id' => $idDemande, 'd' => $chargeId]);
    $comiteId = $idsComite[array_rand($idsComite)];
    $pdo->prepare("INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision) VALUES (:id, 'comite', :d, 'favorable', 'Approuvé en comité', NOW())")
        ->execute(['id' => $idDemande, 'd' => $comiteId]);
    $pdo->prepare("UPDATE demandes_credit SET statut = 'approuve', date_decision = NOW() WHERE id_demande = :id")->execute(['id' => $idDemande]);

    // Contrat + échéancier (date de décaissement étalée sur les 18 derniers mois pour donner de la profondeur historique)
    $prochainCtr = prochainNumero($pdo, 'contrats', 'numero_contrat', 'CTR', $annee);
    $numeroContrat = sprintf('CTR-%s-%04d', $annee, $prochainCtr);
    $moisDecaissement = rand(1, 18);
    $dateDecaissement = date('Y-m-d', strtotime("-{$moisDecaissement} months"));

    $pdo->prepare(
        'INSERT INTO contrats (id_demande, numero_contrat, montant_accorde, taux_final, duree_mois, date_signature, date_decaissement, statut)
         VALUES (:id, :num, :montant, :taux, :duree, :date1, :date2, "decaisse")'
    )->execute(['id' => $idDemande, 'num' => $numeroContrat, 'montant' => $montant, 'taux' => $taux, 'duree' => $duree, 'date1' => $dateDecaissement, 'date2' => $dateDecaissement]);
    $idContrat = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE demandes_credit SET statut = 'decaisse' WHERE id_demande = :id")->execute(['id' => $idDemande]);

    $tableau = $generateurEcheancier->genererTableau($montant, $duree, $taux, $dateDecaissement);
    $insertEch = $pdo->prepare(
        'INSERT INTO echeancier (id_contrat, numero_echeance, date_echeance, capital, interet, montant_echeance, capital_restant_du, statut)
         VALUES (:c, :n, :d, :cap, :int, :m, :rest, :statut)'
    );
    $echeancesInserees = [];
    foreach ($tableau as $echeance) {
        $estPassee = strtotime($echeance['date_echeance']) < time();
        $statutEch = $estPassee ? 'payee' : 'a_venir'; // provisoire, ajusté ci-dessous pour le sous-groupe impayé
        $insertEch->execute([
            'c' => $idContrat, 'n' => $echeance['numero_echeance'], 'd' => $echeance['date_echeance'],
            'cap' => $echeance['capital'], 'int' => $echeance['interet'], 'm' => $echeance['montant_echeance'],
            'rest' => $echeance['capital_restant_du'], 'statut' => $statutEch,
        ]);
        $echeancesInserees[] = ['id' => (int) $pdo->lastInsertId(), 'numero' => $echeance['numero_echeance'], 'montant' => $echeance['montant_echeance'], 'date' => $echeance['date_echeance'], 'passee' => $estPassee];
    }

    // Remboursements pour les échéances marquées "payee" (paiement à la date d'échéance)
    foreach ($echeancesInserees as $ech) {
        if ($ech['passee']) {
            $pdo->prepare(
                "INSERT INTO remboursements (id_echeance, date_paiement, montant_paye, mode_paiement, enregistre_par) VALUES (:e, :d, :m, 'virement', :u)"
            )->execute(['e' => $ech['id'], 'd' => $ech['date'], 'm' => $ech['montant'], 'u' => $chargeId]);
        }
    }

    $statutFinal = $i < 5 ? 'impayee' : 'decaisse';
    if ($i < 5) {
        // Sous-groupe "avec impayé" : on repasse la dernière échéance passée en impayé
        $echeancesPassees = array_values(array_filter($echeancesInserees, fn($e) => $e['passee']));
        if (!empty($echeancesPassees)) {
            $derniere = end($echeancesPassees);
            $pdo->prepare("DELETE FROM remboursements WHERE id_echeance = :id")->execute(['id' => $derniere['id']]);
            $pdo->prepare("UPDATE echeancier SET statut = 'impayee' WHERE id_echeance = :id")->execute(['id' => $derniere['id']]);
            $demandesImpayees[] = $idContrat;
        } else {
            $demandesActives[] = $idContrat;
        }
    } else {
        $demandesActives[] = $idContrat;
    }

    audit($pdo, $adminId, 'SEED_DEMANDE_DECAISSEE', 'contrats', $idContrat, "Seed : contrat $numeroContrat (" . number_format($montant, 0, ',', ' ') . " FCFA)");
}
echo "Demandes décaissées créées : 35 (dont 5 avec impayé) — encours ciblé ≈ 5 Mds FCFA\n";

// --- 6 demandes "en cours" (analyse / scoring / comité) ---
$statutsEnCours = ['en_attente', 'en_analyse', 'scoring_effectue', 'scoring_effectue', 'en_comite', 'en_comite'];
foreach ($statutsEnCours as $statut) {
    $client = $tousLesClients[array_rand($tousLesClients)];
    [$montant, ] = montantAleatoire($client, $idsClientsEntreprise);
    $chargeId = $idsCharge[array_rand($idsCharge)];
    $duree = [12, 24, 36, 48][array_rand([12, 24, 36, 48])];
    $taux = round(rand(650, 1150) / 100, 2);
    $idDemande = creerDemande($pdo, $client, $chargeId, $montant, $duree, $taux, 'en_attente');

    if (in_array($statut, ['scoring_effectue', 'en_comite'], true)) {
        $stmtClient = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
        $stmtClient->execute(['id' => $client['id']]);
        $clientRow = $stmtClient->fetch();
        $stmtDemande = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
        $stmtDemande->execute(['id' => $idDemande]);
        $demandeRow = $stmtDemande->fetch();
        $resultatScoring = $moteurScoring->evaluer($clientRow, $demandeRow, 0.0);
        $pdo->prepare(
            'INSERT INTO scoring (id_demande, capacite_remboursement, taux_endettement, valeur_garanties, score_total, grade, probabilite_defaut, calcule_par)
             VALUES (:id, :cap, :end, 0, :score, :grade, :pd, :calc)'
        )->execute([
            'id' => $idDemande, 'cap' => $resultatScoring['capacite_remboursement'], 'end' => $resultatScoring['taux_endettement'],
            'score' => $resultatScoring['score_total'], 'grade' => $resultatScoring['grade'], 'pd' => $resultatScoring['probabilite_defaut'], 'calc' => $chargeId,
        ]);
    }
    if ($statut === 'en_comite') {
        $pdo->prepare("INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision) VALUES (:id, 'charge_clientele', :d, 'favorable', 'Transmis au comité', NOW())")
            ->execute(['id' => $idDemande, 'd' => $chargeId]);
    }
    $pdo->prepare('UPDATE demandes_credit SET statut = :s WHERE id_demande = :id')->execute(['s' => $statut, 'id' => $idDemande]);
}
echo "Demandes en cours créées : " . count($statutsEnCours) . "\n";

// --- 5 demandes refusées ---
for ($i = 0; $i < 5; $i++) {
    $client = $tousLesClients[array_rand($tousLesClients)];
    [$montant, ] = montantAleatoire($client, $idsClientsEntreprise);
    $chargeId = $idsCharge[array_rand($idsCharge)];
    $duree = [12, 24, 36][array_rand([12, 24, 36])];
    $taux = round(rand(650, 1150) / 100, 2);
    $idDemande = creerDemande($pdo, $client, $chargeId, $montant, $duree, $taux, 'en_attente');

    $stmtClient = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
    $stmtClient->execute(['id' => $client['id']]);
    $clientRow = $stmtClient->fetch();
    $stmtDemande = $pdo->prepare('SELECT * FROM demandes_credit WHERE id_demande = :id');
    $stmtDemande->execute(['id' => $idDemande]);
    $demandeRow = $stmtDemande->fetch();
    $resultatScoring = $moteurScoring->evaluer($clientRow, $demandeRow, 0.0);
    $pdo->prepare(
        'INSERT INTO scoring (id_demande, capacite_remboursement, taux_endettement, valeur_garanties, score_total, grade, probabilite_defaut, calcule_par)
         VALUES (:id, :cap, :end, 0, :score, :grade, :pd, :calc)'
    )->execute([
        'id' => $idDemande, 'cap' => $resultatScoring['capacite_remboursement'], 'end' => $resultatScoring['taux_endettement'],
        'score' => $resultatScoring['score_total'], 'grade' => $resultatScoring['grade'], 'pd' => $resultatScoring['probabilite_defaut'], 'calc' => $chargeId,
    ]);
    $pdo->prepare("INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision) VALUES (:id, 'charge_clientele', :d, 'favorable', 'Transmis au comité', NOW())")
        ->execute(['id' => $idDemande, 'd' => $chargeId]);
    $comiteId = $idsComite[array_rand($idsComite)];
    $motifs = ['Capacité de remboursement insuffisante', 'Garanties insuffisantes', 'Taux d\'endettement excessif', 'Historique de paiement défavorable', 'Incohérence dans les documents fournis'];
    $pdo->prepare("INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision) VALUES (:id, 'comite', :d, 'defavorable', :m, NOW())")
        ->execute(['id' => $idDemande, 'd' => $comiteId, 'm' => $motifs[$i]]);
    $pdo->prepare("UPDATE demandes_credit SET statut = 'refuse', date_decision = NOW() WHERE id_demande = :id")->execute(['id' => $idDemande]);
}
echo "Demandes refusées créées : 5\n";

// ---------------------------------------------------------------------
// 5. RESTRUCTURATION DE 3 CONTRATS (parmi les décaissés propres)
// ---------------------------------------------------------------------
$aRestructurer = array_slice($demandesActives, 0, 3);
foreach ($aRestructurer as $idContrat) {
    $stmt = $pdo->prepare('SELECT * FROM contrats WHERE id_contrat = :id');
    $stmt->execute(['id' => $idContrat]);
    $contrat = $stmt->fetch();

    $stmtDernierPaye = $pdo->prepare("SELECT capital_restant_du FROM echeancier WHERE id_contrat = :id AND statut = 'payee' ORDER BY numero_echeance DESC LIMIT 1");
    $stmtDernierPaye->execute(['id' => $idContrat]);
    $capitalRestant = $stmtDernierPaye->fetchColumn();
    if ($capitalRestant === false || (float) $capitalRestant <= 0) {
        continue;
    }
    $capitalRestant = (float) $capitalRestant;

    $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(numero_echeance), 0) FROM echeancier WHERE id_contrat = :id');
    $stmtMax->execute(['id' => $idContrat]);
    $dernierNumero = (int) $stmtMax->fetchColumn();

    $pdo->prepare("UPDATE echeancier SET statut = 'annulee' WHERE id_contrat = :id AND statut != 'payee'")->execute(['id' => $idContrat]);

    $nouvelleDuree = (int) $contrat['duree_mois'] + 12;
    $nouveauTaux = max(5.0, (float) $contrat['taux_final'] - 1);
    $tableau = $generateurEcheancier->genererTableauReprise($capitalRestant, $nouvelleDuree, $nouveauTaux, date('Y-m-d'), 2, $dernierNumero + 1);
    $insertEch = $pdo->prepare(
        'INSERT INTO echeancier (id_contrat, numero_echeance, date_echeance, capital, interet, montant_echeance, capital_restant_du, statut)
         VALUES (:c, :n, :d, :cap, :int, :m, :rest, "a_venir")'
    );
    foreach ($tableau as $echeance) {
        $insertEch->execute([
            'c' => $idContrat, 'n' => $echeance['numero_echeance'], 'd' => $echeance['date_echeance'],
            'cap' => $echeance['capital'], 'int' => $echeance['interet'], 'm' => $echeance['montant_echeance'], 'rest' => $echeance['capital_restant_du'],
        ]);
    }
    $pdo->prepare('UPDATE contrats SET duree_mois = :d, taux_final = :t WHERE id_contrat = :id')
        ->execute(['d' => $nouvelleDuree, 't' => $nouveauTaux, 'id' => $idContrat]);
    $pdo->prepare(
        'INSERT INTO restructurations (id_contrat, ancienne_duree_mois, nouvelle_duree_mois, ancien_taux, nouveau_taux, differe_mois, capital_restant_avant, motif, decide_par)
         VALUES (:c, :ad, :nd, :at, :nt, 2, :cap, "Difficultés temporaires de trésorerie (seed)", :u)'
    )->execute([
        'c' => $idContrat, 'ad' => $contrat['duree_mois'], 'nd' => $nouvelleDuree,
        'at' => $contrat['taux_final'], 'nt' => $nouveauTaux, 'cap' => $capitalRestant, 'u' => $adminId,
    ]);
}
echo "Contrats restructurés : " . count($aRestructurer) . "\n";

// ---------------------------------------------------------------------
// 6. VÉRIFICATION FINALE
// ---------------------------------------------------------------------
echo "\n=== Vérification ===\n";
$totalClients = $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$totalDemandes = $pdo->query('SELECT COUNT(*) FROM demandes_credit')->fetchColumn();
echo "Total clients : $totalClients\n";
echo "Total demandes : $totalDemandes\n";

$repartition = $pdo->query("SELECT statut, COUNT(*) AS nb FROM demandes_credit GROUP BY statut")->fetchAll(PDO::FETCH_KEY_PAIR);
print_r($repartition);

$encours = $pdo->query(
    "SELECT COALESCE(SUM(c.montant_accorde - (SELECT COALESCE(SUM(e.capital),0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee')), 0)
     FROM contrats c WHERE c.statut IN ('decaisse','en_defaut')"
)->fetchColumn();
echo 'Encours total actif : ' . number_format((float) $encours, 0, ',', ' ') . " FCFA\n";

echo "\nSeed terminé.\n";
