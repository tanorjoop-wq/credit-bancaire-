<?php
/**
 * Comparatif rentabilité Prévisionnelle (calculée à l'octroi, sur la durée
 * totale du prêt) vs Réelle (intérêts effectivement encaissés à date, via les
 * échéances payées) — même référentiel (echeancier/remboursements), pas de
 * duplication de données.
 */
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

$stmt = $pdo->query(
    "SELECT r.id_demande, d.reference, cl.nom_raison_sociale, cl.prenom, c.id_contrat, c.numero_contrat,
            r.interets_bruts AS previsionnel, r.raroc AS raroc_previsionnel, r.date_calcul,
            (SELECT COALESCE(SUM(e.interet), 0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee') AS reel,
            (SELECT COUNT(*) FROM echeancier e WHERE e.id_contrat = c.id_contrat) AS nb_echeances_total,
            (SELECT COUNT(*) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee') AS nb_echeances_payees
     FROM rentabilite_demande r
     JOIN demandes_credit d ON d.id_demande = r.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     JOIN contrats c ON c.id_demande = d.id_demande
     ORDER BY r.id_demande DESC"
);
$lignes = $stmt->fetchAll();

$titrePage = 'Rentabilité prévisionnelle vs réelle';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h4 mb-0">Prévisionnel vs Réel</h1>
    <a href="liste.php" class="btn btn-outline-secondary btn-sm">&larr; Retour</a>
</div>
<p class="text-muted small mb-4">Intérêts prévisionnels calculés à l'octroi (sur la durée totale) comparés aux intérêts effectivement encaissés à date.</p>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead><tr><th>Contrat</th><th>Client</th><th class="text-end">Intérêts prévisionnels</th><th class="text-end">Intérêts réels à date</th><th>Avancement</th><th class="text-end">Taux de réalisation</th></tr></thead>
            <tbody>
                <?php if (empty($lignes)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucune donnée.</td></tr>
                <?php endif; ?>
                <?php foreach ($lignes as $l): ?>
                    <?php
                        $avancement = $l['nb_echeances_total'] > 0 ? round($l['nb_echeances_payees'] / $l['nb_echeances_total'] * 100) : 0;
                        $tauxRealisation = $l['previsionnel'] > 0 ? round($l['reel'] / $l['previsionnel'] * 100, 1) : 0;
                        $ecartFavorable = $avancement > 0 && $tauxRealisation >= $avancement - 5;
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e($l['numero_contrat']) ?></td>
                        <td><?= e($l['nom_raison_sociale']) ?> <?= e($l['prenom'] ?? '') ?></td>
                        <td class="text-end"><?= formaterMontant($l['previsionnel']) ?></td>
                        <td class="text-end"><?= formaterMontant($l['reel']) ?></td>
                        <td>
                            <div class="progress" style="height: 14px;">
                                <div class="progress-bar bg-navy" style="width: <?= $avancement ?>%"><?= $avancement ?>%</div>
                            </div>
                            <span class="small text-muted"><?= (int) $l['nb_echeances_payees'] ?> / <?= (int) $l['nb_echeances_total'] ?> échéances</span>
                        </td>
                        <td class="text-end">
                            <span class="badge <?= $ecartFavorable ? 'bg-success' : 'bg-warning text-dark' ?>"><?= e((string) $tauxRealisation) ?> %</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
