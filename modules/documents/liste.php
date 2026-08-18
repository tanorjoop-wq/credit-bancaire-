<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

detecterDocumentsExpires($pdo);

$statutFiltre = trim($_GET['statut'] ?? '');
$conditions = [];
$parametres = [];
if ($statutFiltre !== '' && in_array($statutFiltre, ['valide', 'manquant', 'expire'], true)) {
    $conditions[] = 'd.statut_validation = :statut';
    $parametres['statut'] = $statutFiltre;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare(
    "SELECT d.*, u.nom, u.prenom,
            dc.reference AS ref_demande,
            cl1.nom_raison_sociale AS nom_client_direct, cl1.prenom AS prenom_client_direct,
            cl2.nom_raison_sociale AS nom_client_demande, cl2.prenom AS prenom_client_demande,
            ct.numero_contrat
     FROM documents d
     JOIN utilisateurs u ON u.id_utilisateur = d.uploade_par
     LEFT JOIN demandes_credit dc ON dc.id_demande = d.id_demande
     LEFT JOIN clients cl1 ON cl1.id_client = d.id_client
     LEFT JOIN clients cl2 ON cl2.id_client = dc.id_client
     LEFT JOIN contrats ct ON ct.id_contrat = d.id_contrat
     $whereSql
     ORDER BY d.id_document DESC
     LIMIT 200"
);
$stmt->execute($parametres);
$documents = $stmt->fetchAll();

$compteurs = $pdo->query("SELECT statut_validation, COUNT(*) AS nb FROM documents GROUP BY statut_validation")->fetchAll(PDO::FETCH_KEY_PAIR);

$libellesStatut = ['valide' => 'Validé', 'manquant' => 'Manquant', 'expire' => 'Expiré'];
$couleursStatut = ['valide' => 'bg-success', 'manquant' => 'bg-secondary', 'expire' => 'bg-danger'];

$titrePage = 'Documents (GED)';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-1"><i class="bi bi-folder2-open me-2 text-navy"></i>Documents — GED bancaire centralisée</h1>
<p class="text-muted small mb-4">Vue unifiée des pièces déposées sur les clients, demandes et contrats.</p>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-0"><div class="card-body">
            <div class="text-muted small">Documents validés</div>
            <div class="h3 fw-bold text-success mb-0"><?= (int) ($compteurs['valide'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0"><div class="card-body">
            <div class="text-muted small">Documents expirés</div>
            <div class="h3 fw-bold text-danger mb-0"><?= (int) ($compteurs['expire'] ?? 0) ?></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-0"><div class="card-body">
            <div class="text-muted small">Total documents</div>
            <div class="h3 fw-bold text-navy mb-0"><?= array_sum($compteurs) ?></div>
        </div></div>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-3">
        <select name="statut" class="form-select form-select-sm">
            <option value="">Tous les statuts</option>
            <?php foreach ($libellesStatut as $val => $lib): ?>
                <option value="<?= $val ?>" <?= $statutFiltre === $val ? 'selected' : '' ?>><?= e($lib) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button type="submit" class="btn btn-outline-secondary btn-sm w-100">Filtrer</button></div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead><tr><th>Type</th><th>Fichier</th><th>Rattaché à</th><th>Version</th><th>Statut</th><th>Expiration</th><th>Déposé par</th><th>Date</th></tr></thead>
            <tbody>
                <?php if (empty($documents)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucun document.</td></tr>
                <?php endif; ?>
                <?php foreach ($documents as $doc): ?>
                    <?php
                        if ($doc['id_client']) {
                            $entite = e($doc['nom_client_direct']) . ' ' . e($doc['prenom_client_direct'] ?? '') . ' <span class="badge bg-secondary">Client</span>';
                        } elseif ($doc['id_contrat']) {
                            $entite = e($doc['numero_contrat']) . ' <span class="badge bg-secondary">Contrat</span>';
                        } else {
                            $entite = e($doc['ref_demande']) . ' (' . e($doc['nom_client_demande']) . ' ' . e($doc['prenom_client_demande'] ?? '') . ') <span class="badge bg-secondary">Demande</span>';
                        }
                    ?>
                    <tr>
                        <td><?= e($doc['type_document']) ?></td>
                        <td class="small"><?= e($doc['nom_fichier']) ?></td>
                        <td class="small"><?= $entite ?></td>
                        <td>v<?= (int) $doc['version'] ?></td>
                        <td><span class="badge <?= $couleursStatut[$doc['statut_validation']] ?? 'bg-secondary' ?>"><?= e($libellesStatut[$doc['statut_validation']] ?? $doc['statut_validation']) ?></span></td>
                        <td class="small"><?= $doc['date_expiration'] ? e(date('d/m/Y', strtotime($doc['date_expiration']))) : '—' ?></td>
                        <td class="small"><?= e($doc['prenom'] . ' ' . $doc['nom']) ?></td>
                        <td class="small text-muted"><?= e(date('d/m/Y', strtotime($doc['date_upload']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
