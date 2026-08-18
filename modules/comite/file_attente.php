<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

$stmt = $pdo->query(
    "SELECT d.id_demande, d.reference, d.montant_demande, d.type_credit, d.date_demande,
            c.nom_raison_sociale, c.prenom, s.score_total, s.grade
     FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     LEFT JOIN scoring s ON s.id_demande = d.id_demande
     WHERE d.statut = 'en_comite'
     ORDER BY d.date_demande ASC"
);
$dossiers = $stmt->fetchAll();

$nbComiteActifs = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'comite_direction' AND actif = 1")->fetchColumn();
$quorum = intdiv($nbComiteActifs, 2) + 1;

$titrePage = 'Comité de crédit — File d\'attente';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-1"><i class="bi bi-people-fill me-2 text-navy"></i>Comité de crédit</h1>
<p class="text-muted small mb-4"><?= $nbComiteActifs ?> membre(s) actif(s) — quorum de résolution : <?= $quorum ?> vote(s) dans le même sens.</p>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Référence</th><th>Client</th><th>Type</th><th class="text-end">Montant</th><th>Score</th><th>Votes exprimés</th><th>Transmis le</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php if (empty($dossiers)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucun dossier en attente de décision comité.</td></tr>
                <?php endif; ?>
                <?php foreach ($dossiers as $d): ?>
                    <?php
                        $stmtVotes = $pdo->prepare(
                            "SELECT COUNT(*) FROM workflow_approbation w
                             WHERE w.id_demande = :id1 AND w.niveau = 'comite'
                               AND w.date_decision > (SELECT COALESCE(MAX(date_decision), '1970-01-01') FROM workflow_approbation WHERE id_demande = :id2 AND niveau = 'charge_clientele')"
                        );
                        $stmtVotes->execute(['id1' => $d['id_demande'], 'id2' => $d['id_demande']]);
                        $nbVotes = (int) $stmtVotes->fetchColumn();
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e($d['reference']) ?></td>
                        <td><?= e($d['nom_raison_sociale']) ?> <?= e($d['prenom'] ?? '') ?></td>
                        <td><?= e(ucfirst($d['type_credit'])) ?></td>
                        <td class="text-end"><?= formaterMontant($d['montant_demande']) ?></td>
                        <td><?= $d['score_total'] !== null ? e($d['grade']) . ' (' . e($d['score_total']) . '/100)' : '—' ?></td>
                        <td><span class="badge bg-navy"><?= $nbVotes ?> / <?= $quorum ?></span></td>
                        <td class="small text-muted"><?= e(date('d/m/Y', strtotime($d['date_demande']))) ?></td>
                        <td class="text-end"><a href="synthese.php?id=<?= (int) $d['id_demande'] ?>" class="btn btn-sm btn-navy">Synthèse & vote</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
