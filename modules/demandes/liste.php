<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion(); // les 3 rôles peuvent consulter la liste des demandes

global $pdo;

// --- Filtres ---
$recherche = trim($_GET['q'] ?? '');
$statutFiltre = trim($_GET['statut'] ?? '');
$statutsValides = [
    'en_attente', 'en_analyse', 'scoring_effectue', 'en_comite',
    'approuve', 'refuse', 'decaisse', 'solde',
];

// --- Pagination ---
$parPage = 20;
$pageActuelle = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($pageActuelle - 1) * $parPage;

$conditions = [];
$parametres = [];

if ($recherche !== '') {
    $conditions[] = '(d.reference LIKE :recherche OR c.nom_raison_sociale LIKE :recherche)';
    $parametres['recherche'] = '%' . $recherche . '%';
}
if ($statutFiltre !== '' && in_array($statutFiltre, $statutsValides, true)) {
    $conditions[] = 'd.statut = :statut';
    $parametres['statut'] = $statutFiltre;
}

$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// --- Pipeline visuel à 10 étapes (Module 4 — Credit Origination Center) ---
$etapesPipeline = [
    'Nouvelle demande'   => (int) $pdo->query("SELECT COUNT(*) FROM demandes_credit WHERE statut = 'en_attente'")->fetchColumn(),
    'En analyse'         => (int) $pdo->query("SELECT COUNT(*) FROM demandes_credit WHERE statut = 'en_analyse'")->fetchColumn(),
    'Scoring effectué'   => (int) $pdo->query("SELECT COUNT(*) FROM demandes_credit WHERE statut = 'scoring_effectue'")->fetchColumn(),
    'En comité'          => (int) $pdo->query("SELECT COUNT(*) FROM demandes_credit WHERE statut = 'en_comite'")->fetchColumn(),
    'Approuvée'          => (int) $pdo->query("SELECT COUNT(*) FROM demandes_credit d WHERE d.statut = 'approuve'")->fetchColumn(),
    'Contrat en prépa.'  => (int) $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'en_preparation'")->fetchColumn(),
    'Contrat signé'      => (int) $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'signe'")->fetchColumn(),
    'Décaissée'          => (int) $pdo->query(
        "SELECT COUNT(*) FROM contrats c WHERE c.statut = 'decaisse' AND NOT EXISTS (SELECT 1 FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee')"
    )->fetchColumn(),
    'En remboursement'  => (int) $pdo->query(
        "SELECT COUNT(*) FROM contrats c WHERE c.statut = 'decaisse' AND EXISTS (SELECT 1 FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee')"
    )->fetchColumn(),
    'Soldée'             => (int) $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut = 'solde'")->fetchColumn(),
];

$stmtTotal = $pdo->prepare(
    "SELECT COUNT(*) FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     $whereSql"
);
$stmtTotal->execute($parametres);
$totalDemandes = (int) $stmtTotal->fetchColumn();
$totalPages = (int) ceil($totalDemandes / $parPage);

$sql = "SELECT d.id_demande, d.reference, d.type_credit, d.montant_demande, d.duree_mois,
               d.statut, d.date_demande, c.nom_raison_sociale, c.prenom,
               s.score_total, s.grade
        FROM demandes_credit d
        JOIN clients c ON c.id_client = d.id_client
        LEFT JOIN scoring s ON s.id_demande = d.id_demande
        $whereSql
        ORDER BY d.id_demande DESC
        LIMIT :limite OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($parametres as $cle => $valeur) {
    $stmt->bindValue($cle, $valeur);
}
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$demandes = $stmt->fetchAll();

$libellesStatuts = [
    'en_attente'       => 'En attente',
    'en_analyse'       => 'En analyse',
    'scoring_effectue' => 'Scoring effectué',
    'en_comite'        => 'En comité',
    'approuve'         => 'Approuvée',
    'refuse'           => 'Refusée',
    'decaisse'         => 'Décaissée',
    'solde'            => 'Soldée',
];

$titrePage = 'Demandes de crédit';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Demandes de crédit</h1>
    <div>
        <a href="<?= BASE_URL ?>/modules/exports/demandes_pdf.php?statut=<?= urlencode($statutFiltre) ?>" class="btn btn-outline-secondary btn-sm" target="_blank">
            Exporter en PDF
        </a>
        <?php if (in_array($_SESSION['role'], ['administrateur', 'charge_clientele'], true)): ?>
            <a href="ajouter.php" class="btn btn-navy btn-sm">+ Nouvelle demande</a>
        <?php endif; ?>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <div class="d-flex flex-wrap justify-content-between text-center small">
            <?php foreach ($etapesPipeline as $libelle => $nb): ?>
                <div class="flex-fill px-1">
                    <div class="fw-bold text-navy h5 mb-0"><?= $nb ?></div>
                    <div class="text-muted" style="font-size:0.72rem;"><?= e($libelle) ?></div>
                </div>
                <div class="align-self-center text-muted d-none d-lg-block"><i class="bi bi-chevron-right"></i></div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Rechercher (référence, client)"
               value="<?= e($recherche) ?>">
    </div>
    <div class="col-md-3">
        <select name="statut" class="form-select form-select-sm">
            <option value="">Tous les statuts</option>
            <?php foreach ($libellesStatuts as $valeur => $libelle): ?>
                <option value="<?= e($valeur) ?>" <?= $statutFiltre === $valeur ? 'selected' : '' ?>>
                    <?= e($libelle) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Filtrer</button>
    </div>
</form>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Client</th>
                    <th>Type</th>
                    <th class="text-end">Montant</th>
                    <th>Durée</th>
                    <th>Score</th>
                    <th>Statut</th>
                    <th>Date</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($demandes)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">Aucune demande trouvée.</td></tr>
                <?php endif; ?>
                <?php foreach ($demandes as $demande): ?>
                    <tr>
                        <td><span class="fw-semibold"><?= e($demande['reference']) ?></span></td>
                        <td><?= e($demande['nom_raison_sociale']) ?> <?= e($demande['prenom'] ?? '') ?></td>
                        <td><?= e(ucfirst($demande['type_credit'])) ?></td>
                        <td class="text-end"><?= formaterMontant($demande['montant_demande']) ?></td>
                        <td><?= (int) $demande['duree_mois'] ?> mois</td>
                        <td>
                            <?php if ($demande['score_total'] !== null): ?>
                                <span class="badge bg-secondary"><?= e($demande['grade']) ?> — <?= e($demande['score_total']) ?>/100</span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-statut-<?= e($demande['statut']) ?>"><?= e($libellesStatuts[$demande['statut']] ?? $demande['statut']) ?></span></td>
                        <td class="small text-muted"><?= e(date('d/m/Y', strtotime($demande['date_demande']))) ?></td>
                        <td class="text-end">
                            <a href="voir.php?id=<?= (int) $demande['id_demande'] ?>" class="btn btn-sm btn-outline-secondary">Détails</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totalPages > 1): ?>
    <nav class="mt-3">
        <ul class="pagination pagination-sm justify-content-center">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <li class="page-item <?= $p === $pageActuelle ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($recherche) ?>&statut=<?= urlencode($statutFiltre) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<p class="text-muted small"><?= (int) $totalDemandes ?> demande(s) au total.</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
