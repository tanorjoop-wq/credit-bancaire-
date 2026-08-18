<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

detecterEcheancesImpayees($pdo);

$stmt = $pdo->query(
    "SELECT e.id_echeance, e.numero_echeance, e.date_echeance, e.montant_echeance, e.statut, e.id_contrat,
            c.numero_contrat, cl.nom_raison_sociale, cl.prenom, cl.telephone,
            DATEDIFF(CURDATE(), e.date_echeance) AS jours_retard,
            (SELECT COUNT(*) FROM relances_recouvrement r WHERE r.id_echeance = e.id_echeance) AS nb_relances,
            (SELECT MAX(date_relance) FROM relances_recouvrement r WHERE r.id_echeance = e.id_echeance) AS derniere_relance
     FROM echeancier e
     JOIN contrats c ON c.id_contrat = e.id_contrat
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     WHERE e.statut IN ('en_retard', 'impayee')
     ORDER BY (e.montant_echeance * DATEDIFF(CURDATE(), e.date_echeance)) DESC"
);
$echeances = $stmt->fetchAll();

$tranches = [
    '0-7j'   => ['min' => 0, 'max' => 7, 'items' => []],
    '8-30j'  => ['min' => 8, 'max' => 30, 'items' => []],
    '31-60j' => ['min' => 31, 'max' => 60, 'items' => []],
    '61-90j' => ['min' => 61, 'max' => 90, 'items' => []],
    '+90j'   => ['min' => 91, 'max' => PHP_INT_MAX, 'items' => []],
];
foreach ($echeances as $ech) {
    foreach ($tranches as $cle => &$tranche) {
        if ($ech['jours_retard'] >= $tranche['min'] && $ech['jours_retard'] <= $tranche['max']) {
            $tranche['items'][] = $ech;
            break;
        }
    }
    unset($tranche);
}

$couleursTranche = ['0-7j' => 'secondary', '8-30j' => 'warning', '31-60j' => 'orange', '61-90j' => 'danger', '+90j' => 'dark'];

/**
 * Signal de pré-défaillance — heuristique simple et transparente (pas une
 * prédiction ML) combinant l'ancienneté du retard et le nombre de relances
 * déjà effectuées sans résolution : plus un dossier résiste aux relances,
 * plus le signal monte, indépendamment de la tranche de retard.
 */
function signalPreDefaillance(int $joursRetard, int $nbRelances): array
{
    if ($joursRetard > 60 || $nbRelances >= 3) {
        return ['Élevé', 'danger'];
    }
    if ($joursRetard > 7 || $nbRelances >= 1) {
        return ['Moyen', 'warning'];
    }
    return ['Faible', 'success'];
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Recouvrement';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-1"><i class="bi bi-telephone-outbound me-2 text-navy"></i>Recouvrement</h1>
<p class="text-muted small mb-4">Segmentation automatique des impayés par tranche d'ancienneté, triée par priorité (montant × jours de retard).</p>

<?php if (isset($_GET['succes'])): ?><div class="alert alert-success small"><?= e($_GET['succes']) ?></div><?php endif; ?>

<div class="row g-3 mb-4">
    <?php foreach ($tranches as $cle => $tranche): ?>
        <div class="col-6 col-md-2">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body py-3">
                    <div class="small text-muted"><?= $cle ?></div>
                    <div class="h4 fw-bold mb-0 text-<?= $couleursTranche[$cle] === 'orange' ? 'warning' : $couleursTranche[$cle] ?>"><?= count($tranche['items']) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php foreach ($tranches as $cle => $tranche): ?>
    <?php if (empty($tranche['items'])) continue; ?>
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-white fw-semibold">Tranche <?= $cle ?> (<?= count($tranche['items']) ?>)</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Contrat</th><th>Client</th><th>Téléphone</th><th class="text-end">Montant dû</th><th>Retard</th><th>Relances</th><th>Signal pré-défaillance</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                    <?php foreach ($tranche['items'] as $ech): ?>
                        <?php [$signalLabel, $signalCouleur] = signalPreDefaillance((int) $ech['jours_retard'], (int) $ech['nb_relances']); ?>
                        <tr>
                            <td class="fw-semibold"><?= e($ech['numero_contrat']) ?></td>
                            <td><?= e($ech['nom_raison_sociale']) ?> <?= e($ech['prenom'] ?? '') ?></td>
                            <td><?= e($ech['telephone']) ?></td>
                            <td class="text-end"><?= formaterMontant($ech['montant_echeance']) ?></td>
                            <td><?= (int) $ech['jours_retard'] ?> j</td>
                            <td>
                                <?= (int) $ech['nb_relances'] ?>
                                <?php if ($ech['derniere_relance']): ?><span class="text-muted small">(dernière : <?= e(date('d/m/Y', strtotime($ech['derniere_relance']))) ?>)</span><?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?= $signalCouleur ?>"><?= $signalLabel ?></span></td>
                            <td class="text-end"><a href="dossier.php?id=<?= (int) $ech['id_echeance'] ?>" class="btn btn-sm btn-outline-secondary">Relancer</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if (empty($echeances)): ?>
    <div class="card shadow-sm border-0"><div class="card-body text-center text-muted py-5">Aucun impayé en cours. Portefeuille sain.</div></div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
