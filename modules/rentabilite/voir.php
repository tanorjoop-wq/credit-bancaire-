<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

$idDemande = (int) ($_GET['id_demande'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT d.*, c.nom_raison_sociale, c.prenom FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client WHERE d.id_demande = :id'
);
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

$stmtRentabilite = $pdo->prepare('SELECT * FROM rentabilite_demande WHERE id_demande = :id');
$stmtRentabilite->execute(['id' => $idDemande]);
$rentabilite = $stmtRentabilite->fetch();

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Rentabilité — ' . $demande['reference'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Rentabilité — <?= e($demande['reference']) ?> (<?= e($demande['nom_raison_sociale']) ?> <?= e($demande['prenom'] ?? '') ?>)</h1>
    <a href="liste.php" class="btn btn-outline-secondary btn-sm">&larr; Retour</a>
</div>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>

<form method="post" action="calculer.php" class="mb-3">
    <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
    <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
    <button type="submit" class="btn btn-navy btn-sm"><?= $rentabilite ? 'Recalculer la rentabilité' : 'Calculer la rentabilité' ?></button>
</form>

<?php if ($rentabilite): ?>
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Revenus & coûts</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tbody>
                            <tr><td>Intérêts bruts projetés</td><td class="text-end"><?= formaterMontant($rentabilite['interets_bruts']) ?></td></tr>
                            <tr><td>Coût de refinancement</td><td class="text-end">− <?= formaterMontant($rentabilite['cout_refinancement']) ?></td></tr>
                            <tr class="fw-semibold"><td>Marge Nette d'Intérêt (MNI)</td><td class="text-end"><?= formaterMontant($rentabilite['marge_nette_interet']) ?></td></tr>
                            <tr><td>Coût du risque (PD × LGD × EAD)</td><td class="text-end">− <?= formaterMontant($rentabilite['cout_du_risque']) ?></td></tr>
                            <tr><td>Charges opératoires</td><td class="text-end">− <?= formaterMontant($rentabilite['charges_operatoires']) ?></td></tr>
                            <tr class="fw-semibold text-navy"><td>Gain net ajusté du risque</td><td class="text-end"><?= formaterMontant($rentabilite['gain_net_ajuste']) ?></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Paramètres de risque & RAROC</div>
                <div class="card-body">
                    <table class="table table-sm mb-3">
                        <tbody>
                            <tr><td>Probabilité de défaut (PD)</td><td class="text-end"><?= e($rentabilite['probabilite_defaut']) ?> %</td></tr>
                            <tr><td>Perte en cas de défaut (LGD)</td><td class="text-end"><?= e($rentabilite['perte_en_cas_defaut']) ?> %</td></tr>
                            <tr><td>Exposition au défaut (EAD)</td><td class="text-end"><?= formaterMontant($rentabilite['exposition_defaut']) ?></td></tr>
                            <tr><td>Capital économique alloué</td><td class="text-end"><?= formaterMontant($rentabilite['capital_economique']) ?></td></tr>
                        </tbody>
                    </table>
                    <div class="text-center">
                        <div class="display-6 fw-bold <?= $rentabilite['verdict'] === 'rentable' ? 'text-success' : 'text-danger' ?>"><?= e($rentabilite['raroc']) ?> %</div>
                        <div class="text-muted small mb-2">RAROC (seuil cible <?= e($rentabilite['seuil_cible']) ?> %)</div>
                        <span class="badge <?= $rentabilite['verdict'] === 'rentable' ? 'bg-success' : 'bg-danger' ?> fs-6">
                            <?= $rentabilite['verdict'] === 'rentable' ? 'Rentable pour la banque' : 'Marge insuffisante vs risque pris' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <p class="text-muted small mt-3">Calculé le <?= e(date('d/m/Y H:i', strtotime($rentabilite['date_calcul']))) ?></p>
<?php else: ?>
    <p class="text-muted small">Aucun calcul de rentabilité pour cette demande.</p>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
