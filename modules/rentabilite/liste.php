<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

$stmt = $pdo->query(
    "SELECT d.id_demande, d.reference, d.montant_demande, d.statut, c.nom_raison_sociale, c.prenom,
            r.raroc, r.verdict, r.date_calcul
     FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     LEFT JOIN rentabilite_demande r ON r.id_demande = d.id_demande
     WHERE d.statut NOT IN ('en_attente', 'refuse')
     ORDER BY d.id_demande DESC"
);
$demandes = $stmt->fetchAll();

// --- Rollup par produit (type de crédit) ---
$parProduit = $pdo->query(
    "SELECT d.type_credit, COUNT(*) AS nb, ROUND(AVG(r.raroc), 2) AS raroc_moyen, SUM(c.montant_accorde) AS encours_brut
     FROM rentabilite_demande r
     JOIN demandes_credit d ON d.id_demande = r.id_demande
     JOIN contrats c ON c.id_demande = d.id_demande
     GROUP BY d.type_credit ORDER BY encours_brut DESC"
)->fetchAll();

// --- Rollup par agence (via le chargé de clientèle assigné) ---
$parAgence = $pdo->query(
    "SELECT a.nom AS agence, COUNT(*) AS nb, ROUND(AVG(r.raroc), 2) AS raroc_moyen, SUM(c.montant_accorde) AS encours_brut
     FROM rentabilite_demande r
     JOIN demandes_credit d ON d.id_demande = r.id_demande
     JOIN contrats c ON c.id_demande = d.id_demande
     JOIN utilisateurs u ON u.id_utilisateur = d.charge_id
     LEFT JOIN agences a ON a.id_agence = u.id_agence
     GROUP BY a.nom ORDER BY encours_brut DESC"
)->fetchAll();

$titrePage = 'Rentabilité';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-1">
    <h1 class="h4 mb-0"><i class="bi bi-cash-coin me-2 text-navy"></i>Rentabilité (RAROC)</h1>
    <a href="comparatif.php" class="btn btn-outline-secondary btn-sm">Prévisionnel vs Réel</a>
</div>
<p class="text-muted small mb-4">Rentabilité ajustée du risque par demande de crédit — usage interne comité/direction.</p>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">RAROC moyen par produit</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Produit</th><th class="text-end">Nb</th><th class="text-end">RAROC moyen</th><th class="text-end">Encours brut</th></tr></thead>
                    <tbody>
                        <?php foreach ($parProduit as $p): ?>
                            <tr>
                                <td><?= e(ucfirst($p['type_credit'])) ?></td>
                                <td class="text-end"><?= (int) $p['nb'] ?></td>
                                <td class="text-end <?= $p['raroc_moyen'] >= 15 ? 'text-success' : 'text-danger' ?>"><?= e($p['raroc_moyen']) ?> %</td>
                                <td class="text-end"><?= formaterMontant($p['encours_brut']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">RAROC moyen par agence</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Agence</th><th class="text-end">Nb</th><th class="text-end">RAROC moyen</th><th class="text-end">Encours brut</th></tr></thead>
                    <tbody>
                        <?php foreach ($parAgence as $a): ?>
                            <tr>
                                <td><?= e($a['agence'] ?: 'Non affecté') ?></td>
                                <td class="text-end"><?= (int) $a['nb'] ?></td>
                                <td class="text-end <?= $a['raroc_moyen'] >= 15 ? 'text-success' : 'text-danger' ?>"><?= e($a['raroc_moyen']) ?> %</td>
                                <td class="text-end"><?= formaterMontant($a['encours_brut']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Référence</th><th>Client</th><th class="text-end">Montant</th><th>RAROC</th><th>Verdict</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (empty($demandes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucune demande éligible.</td></tr>
                <?php endif; ?>
                <?php foreach ($demandes as $d): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($d['reference']) ?></td>
                        <td><?= e($d['nom_raison_sociale']) ?> <?= e($d['prenom'] ?? '') ?></td>
                        <td class="text-end"><?= formaterMontant($d['montant_demande']) ?></td>
                        <td><?= $d['raroc'] !== null ? e($d['raroc']) . ' %' : '—' ?></td>
                        <td>
                            <?php if ($d['verdict'] === 'rentable'): ?>
                                <span class="badge bg-success">Rentable</span>
                            <?php elseif ($d['verdict'] === 'marge_insuffisante'): ?>
                                <span class="badge bg-danger">Marge insuffisante</span>
                            <?php else: ?>
                                <span class="text-muted small">Non calculé</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="voir.php?id_demande=<?= (int) $d['id_demande'] ?>" class="btn btn-sm btn-outline-secondary">Détails</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
