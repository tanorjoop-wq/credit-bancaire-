<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

detecterEcheancesImpayees($pdo);

$stmt = $pdo->query(
    "SELECT c.*, d.reference, cl.nom_raison_sociale, cl.prenom,
            (SELECT COUNT(*) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut IN ('en_retard','impayee')) AS nb_impayes,
            (SELECT COALESCE(MAX(DATEDIFF(CURDATE(), e.date_echeance)), 0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut IN ('en_retard','impayee')) AS max_jours_retard,
            (SELECT COUNT(*) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut != 'annulee') AS nb_echeances_total,
            (SELECT COUNT(*) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee') AS nb_echeances_payees
     FROM contrats c
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     ORDER BY c.id_contrat DESC"
);
$contrats = $stmt->fetchAll();

$libellesStatuts = [
    'en_preparation' => 'En préparation',
    'signe'          => 'Signé',
    'decaisse'       => 'Décaissé',
    'solde'          => 'Soldé',
    'en_defaut'      => 'En défaut',
];
$couleursStatuts = [
    'en_preparation' => 'bg-secondary', 'signe' => 'bg-primary', 'decaisse' => 'bg-info text-dark',
    'solde' => 'bg-dark', 'en_defaut' => 'bg-danger',
];

/**
 * Classification Loan 360° (Module 7) dérivée du retard maximum observé
 * sur les échéances du contrat — aucune donnée dupliquée, calcul à la volée.
 */
function classifierLoan(string $statutContrat, int $maxJoursRetard): array
{
    if ($statutContrat === 'en_defaut' || $maxJoursRetard > 90) {
        return ['Default', 'bg-dark'];
    }
    if ($maxJoursRetard > 30) {
        return ['At Risk', 'bg-danger'];
    }
    if ($maxJoursRetard > 0) {
        return ['Watchlist', 'bg-warning text-dark'];
    }
    return ['Performing', 'bg-success'];
}

$titrePage = 'Contrats';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Contrats</h1>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['erreur'])): ?>
    <div class="alert alert-danger small"><?= e($_GET['erreur']) ?></div>
<?php endif; ?>

<div class="mb-3">
    <input type="text" class="form-control form-control-sm" style="max-width:320px;"
           placeholder="Filtre rapide (client, n° contrat, statut)…" data-table-filter="#tableContrats">
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive table-sticky">
        <table class="table table-hover align-middle mb-0" id="tableContrats">
            <thead>
                <tr>
                    <th>N° contrat</th>
                    <th>Demande</th>
                    <th>Client</th>
                    <th class="text-end">Montant accordé</th>
                    <th>Statut</th>
                    <th>Qualité (Loan 360°)</th>
                    <th>Progression</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contrats)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucun contrat pour le moment.</td></tr>
                <?php endif; ?>
                <?php foreach ($contrats as $contrat): ?>
                    <?php
                        [$classeLabel, $classeCouleur] = classifierLoan($contrat['statut'], (int) $contrat['max_jours_retard']);
                        $progression = $contrat['nb_echeances_total'] > 0 ? round($contrat['nb_echeances_payees'] / $contrat['nb_echeances_total'] * 100) : 0;
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e($contrat['numero_contrat']) ?></td>
                        <td><?= e($contrat['reference']) ?></td>
                        <td><?= e($contrat['nom_raison_sociale']) ?> <?= e($contrat['prenom'] ?? '') ?></td>
                        <td class="text-end"><?= formaterMontant($contrat['montant_accorde']) ?></td>
                        <td><span class="badge <?= $couleursStatuts[$contrat['statut']] ?? 'bg-secondary' ?>"><?= e($libellesStatuts[$contrat['statut']] ?? $contrat['statut']) ?></span></td>
                        <td><span class="badge <?= $classeCouleur ?>"><?= $classeLabel ?></span></td>
                        <td style="min-width:110px;">
                            <div class="progress" style="height: 12px;">
                                <div class="progress-bar bg-navy" style="width: <?= $progression ?>%"></div>
                            </div>
                            <span class="small text-muted"><?= $progression ?>%</span>
                        </td>
                        <td class="text-end">
                            <a href="voir.php?id=<?= (int) $contrat['id_contrat'] ?>" class="btn btn-sm btn-outline-secondary">Détails</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3"><?= count($contrats) ?> contrat(s) au total.</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
