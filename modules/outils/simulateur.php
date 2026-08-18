<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/EcheancierGenerator.php';
exigerConnexion();

$montant = $_GET['montant'] ?? '';
$duree = $_GET['duree'] ?? '';
$taux = $_GET['taux'] ?? '';

$tableau = null;
$erreur = '';

if ($montant !== '' && $duree !== '' && $taux !== '') {
    if (!is_numeric($montant) || (float) $montant <= 0) {
        $erreur = 'Le montant doit être un nombre positif.';
    } elseif (!ctype_digit((string) $duree) || (int) $duree <= 0) {
        $erreur = 'La durée doit être un nombre entier de mois positif.';
    } elseif (!is_numeric($taux) || (float) $taux < 0) {
        $erreur = 'Le taux est invalide.';
    } else {
        $generateur = new GenerateurEcheancier();
        $tableau = $generateur->genererTableau((float) $montant, (int) $duree, (float) $taux, date('Y-m-d'));
    }
}

$totalInteret = $tableau ? array_sum(array_column($tableau, 'interet')) : 0;
$echeanceMensuelle = $tableau ? $tableau[0]['montant_echeance'] : null;

$titrePage = 'Simulateur de crédit';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Simulateur de crédit</h1>
<p class="text-muted small">Estimez l'échéance mensuelle et le coût total d'un crédit avant de créer une demande formelle.</p>

<?php if ($erreur): ?>
    <div class="alert alert-danger small"><?= e($erreur) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="get" action="simulateur.php" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Montant souhaité (FCFA)</label>
                <input type="number" step="1" min="1" name="montant" class="form-control" required value="<?= e((string) $montant) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Durée (mois)</label>
                <input type="number" step="1" min="1" name="duree" class="form-control" required value="<?= e((string) $duree) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Taux d'intérêt annuel (%)</label>
                <input type="number" step="0.01" min="0" name="taux" class="form-control" required value="<?= e((string) $taux) ?>">
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-navy w-100">Simuler</button>
            </div>
        </form>
    </div>
</div>

<?php if ($tableau): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Échéance mensuelle</div>
                    <div class="h4 fw-bold text-navy mb-0"><?= formaterMontant($echeanceMensuelle) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Coût total du crédit (intérêts)</div>
                    <div class="h4 fw-bold text-navy mb-0"><?= formaterMontant($totalInteret) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-body">
                    <div class="text-muted small">Montant total remboursé</div>
                    <div class="h4 fw-bold text-navy mb-0"><?= formaterMontant((float) $montant + $totalInteret) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold">Tableau d'amortissement complet</div>
        <div class="table-responsive" style="max-height: 500px;">
            <table class="table table-sm mb-0">
                <thead class="sticky-top">
                    <tr><th>#</th><th>Date</th><th class="text-end">Capital</th><th class="text-end">Intérêt</th><th class="text-end">Échéance</th><th class="text-end">Capital restant dû</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($tableau as $ligne): ?>
                        <tr>
                            <td><?= (int) $ligne['numero_echeance'] ?></td>
                            <td><?= e(date('m/Y', strtotime($ligne['date_echeance']))) ?></td>
                            <td class="text-end"><?= formaterMontant($ligne['capital']) ?></td>
                            <td class="text-end"><?= formaterMontant($ligne['interet']) ?></td>
                            <td class="text-end fw-semibold"><?= formaterMontant($ligne['montant_echeance']) ?></td>
                            <td class="text-end"><?= formaterMontant($ligne['capital_restant_du']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
