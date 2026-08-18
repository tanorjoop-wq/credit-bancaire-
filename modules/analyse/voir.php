<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AnalyseFinanciere.php';
require_once __DIR__ . '/../../includes/ScoringEngine.php';
exigerConnexion();

global $pdo;

$idClient = (int) ($_GET['id_client'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    die('Client introuvable.');
}

$stmtDonnees = $pdo->prepare('SELECT * FROM donnees_financieres WHERE id_client = :id ORDER BY date_exercice DESC, id_donnee DESC LIMIT 1');
$stmtDonnees->execute(['id' => $idClient]);
$donnees = $stmtDonnees->fetch();

if (!$donnees) {
    rediriger('saisie.php?id_client=' . $idClient);
}

// Demande active la plus récente pour contextualiser l'échéance (DSCR / reste à vivre)
$stmtDemande = $pdo->prepare(
    "SELECT * FROM demandes_credit WHERE id_client = :id AND statut != 'refuse' ORDER BY id_demande DESC LIMIT 1"
);
$stmtDemande->execute(['id' => $idClient]);
$demande = $stmtDemande->fetch();

$echeanceMensuelle = 0.0;
if ($demande) {
    $moteur = new MoteurScoring();
    $echeanceMensuelle = $moteur->calculerEcheanceMensuelle(
        (float) $demande['montant_demande'],
        (int) $demande['duree_mois'],
        (float) $demande['taux_interet_propose']
    );
}

$analyseur = new AnalyseFinanciere($pdo);
$resultat = $client['type_client'] === 'entreprise'
    ? $analyseur->calculerEntreprise($donnees, $echeanceMensuelle)
    : $analyseur->calculerParticulier($client, $donnees, $echeanceMensuelle);

// --- Détection d'anomalies (Moteur d'Intelligence Crédit, même moteur que le Copilote IA) ---
$incoherencesDetectees = [];
if ($demande) {
    require_once __DIR__ . '/../../includes/MoteurIntelligenceCredit.php';
    $stmtPatrimoineAnalyse = $pdo->prepare('SELECT COALESCE(SUM(valeur_estimee),0) FROM patrimoine_client WHERE id_client = :id');
    $stmtPatrimoineAnalyse->execute(['id' => $idClient]);
    $patrimoineNetAnalyse = (float) $stmtPatrimoineAnalyse->fetchColumn();

    $stmtGarantiesAnalyse = $pdo->prepare("SELECT COALESCE(SUM(valeur_estimee),0) FROM garanties WHERE id_demande = :id AND statut != 'rejetee'");
    $stmtGarantiesAnalyse->execute(['id' => $demande['id_demande']]);
    $totalGarantiesAnalyse = (float) $stmtGarantiesAnalyse->fetchColumn();

    $moteurIAAnalyse = new MoteurIntelligenceCredit();
    $avisIAAnalyse = $moteurIAAnalyse->evaluer($client, $demande, $resultat, null, $patrimoineNetAnalyse, $totalGarantiesAnalyse);
    $incoherencesDetectees = $avisIAAnalyse['incoherences'];
}

$couleurClasse = ['vert' => 'success', 'orange' => 'warning', 'rouge' => 'danger'];
$badge = function (string $couleur) use ($couleurClasse) {
    $classe = $couleurClasse[$couleur] ?? 'secondary';
    return '<span class="badge bg-' . $classe . ($couleur === 'orange' ? ' text-dark' : '') . '">' . strtoupper($couleur) . '</span>';
};

$titrePage = 'Ratios financiers';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Ratios financiers — <?= e($client['nom_raison_sociale']) ?> <?= e($client['prenom'] ?? '') ?></h1>
    <a href="saisie.php?id_client=<?= (int) $idClient ?>" class="btn btn-outline-secondary btn-sm">Nouvelle saisie</a>
</div>
<p class="text-muted small">Exercice du <?= e(date('d/m/Y', strtotime($donnees['date_exercice']))) ?>
    <?php if ($demande): ?> — Contextualisé sur la demande <?= e($demande['reference']) ?> (échéance <?= formaterMontant($echeanceMensuelle) ?>/mois)<?php else: ?> — Aucune demande active pour contextualiser le service de la dette<?php endif; ?>
</p>

<?php if (!empty($incoherencesDetectees)): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Incohérences détectées par le moteur d'analyse</div>
        <ul class="mb-0 small">
            <?php foreach ($incoherencesDetectees as $inc): ?><li><?= e($inc) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if ($client['type_client'] === 'entreprise'): ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Cascade des Soldes Intermédiaires de Gestion</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Chiffre d'affaires</td><td class="text-end"><?= formaterMontant($resultat['chiffre_affaires']) ?></td></tr>
                            <tr><td>Valeur ajoutée</td><td class="text-end"><?= formaterMontant($resultat['valeur_ajoutee']) ?></td></tr>
                            <tr class="fw-semibold"><td>EBE (Excédent Brut d'Exploitation)</td><td class="text-end"><?= formaterMontant($resultat['ebe']) ?></td></tr>
                            <tr><td>Résultat d'exploitation</td><td class="text-end"><?= formaterMontant($resultat['resultat_exploitation']) ?></td></tr>
                            <tr><td>Résultat courant avant impôts</td><td class="text-end"><?= formaterMontant($resultat['rcai']) ?></td></tr>
                            <tr class="fw-semibold"><td>Résultat net</td><td class="text-end"><?= formaterMontant($resultat['resultat_net']) ?></td></tr>
                            <tr class="fw-semibold text-navy"><td>CAF (Capacité d'Autofinancement)</td><td class="text-end"><?= formaterMontant($resultat['caf']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Structure financière & ratios prudentiels</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Fonds de Roulement (FDR)</td><td class="text-end"><?= formaterMontant($resultat['fdr']) ?></td></tr>
                            <tr><td>Besoin en Fonds de Roulement (BFR)</td><td class="text-end"><?= formaterMontant($resultat['bfr']) ?></td></tr>
                            <tr><td>Trésorerie nette <?= $badge($resultat['couleur_tresorerie']) ?></td><td class="text-end fw-semibold"><?= formaterMontant($resultat['tresorerie_nette']) ?></td></tr>
                            <tr><td>DSCR <?= $badge($resultat['couleur_dscr']) ?></td><td class="text-end fw-semibold"><?= $resultat['dscr'] !== null ? number_format($resultat['dscr'], 2) : '—' ?></td></tr>
                            <tr><td>Taux d'endettement net <?= $badge($resultat['couleur_endettement']) ?></td><td class="text-end fw-semibold"><?= $resultat['taux_endettement_net'] !== null ? number_format($resultat['taux_endettement_net'], 1) . ' %' : '—' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-12">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Profitabilité, endettement structurel & cycle d'exploitation</div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 col-md-2">
                            <div class="small text-muted">ROE</div>
                            <div class="h5 fw-bold text-navy mb-0"><?= $resultat['roe'] !== null ? number_format($resultat['roe'], 1) . ' %' : '—' ?></div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="small text-muted">ROA</div>
                            <div class="h5 fw-bold text-navy mb-0"><?= $resultat['roa'] !== null ? number_format($resultat['roa'], 1) . ' %' : '—' ?></div>
                        </div>
                        <div class="col-6 col-md-2">
                            <div class="small text-muted">Marge EBITDA</div>
                            <div class="h5 fw-bold text-navy mb-0"><?= $resultat['marge_ebitda'] !== null ? number_format($resultat['marge_ebitda'], 1) . ' %' : '—' ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="small text-muted">Dette LT / EBITDA</div>
                            <div class="h5 fw-bold text-navy mb-0"><?= $resultat['dette_sur_ebitda'] !== null ? number_format($resultat['dette_sur_ebitda'], 2) . 'x' : '—' ?></div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="small text-muted">Cycle de conversion cash</div>
                            <div class="h5 fw-bold text-navy mb-0"><?= $resultat['ccc_jours'] !== null ? $resultat['ccc_jours'] . ' j' : '—' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Budget mensuel</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Revenu total</td><td class="text-end"><?= formaterMontant($resultat['revenu_total']) ?></td></tr>
                            <tr><td>Reste à vivre <?= $badge($resultat['couleur_reste_a_vivre']) ?></td><td class="text-end fw-semibold"><?= formaterMontant($resultat['reste_a_vivre']) ?></td></tr>
                            <tr><td>Reste à vivre (% du revenu)</td><td class="text-end"><?= $resultat['reste_a_vivre_pourcent'] !== null ? number_format($resultat['reste_a_vivre_pourcent'], 1) . ' %' : '—' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Ratios prudentiels</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Taux d'endettement <?= $badge($resultat['couleur_endettement']) ?></td><td class="text-end fw-semibold"><?= $resultat['taux_endettement'] !== null ? number_format($resultat['taux_endettement'], 1) . ' %' : '—' ?></td></tr>
                            <tr><td>DSCR <?= $badge($resultat['couleur_dscr']) ?></td><td class="text-end fw-semibold"><?= $resultat['dscr'] !== null ? number_format($resultat['dscr'], 2) : '—' ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/copilote_ia.php'; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
