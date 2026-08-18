<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion(); // les 3 rôles peuvent consulter la liste des clients

global $pdo;

// --- Recherche ---
$recherche = trim($_GET['q'] ?? '');

// --- Pagination ---
$parPage    = 20;
$pageActuelle = max(1, (int) ($_GET['page'] ?? 1));
$offset     = ($pageActuelle - 1) * $parPage;

$conditionRecherche = '';
$parametres = [];
if ($recherche !== '') {
    $conditionRecherche = 'WHERE nom_raison_sociale LIKE :recherche
                            OR numero_piece LIKE :recherche
                            OR telephone LIKE :recherche';
    $parametres['recherche'] = '%' . $recherche . '%';
}

// Total pour la pagination
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM clients $conditionRecherche");
$stmtTotal->execute($parametres);
$totalClients = (int) $stmtTotal->fetchColumn();
$totalPages   = (int) ceil($totalClients / $parPage);

// Requête paginée (requête préparée PDO — protection injection SQL)
$sql = "SELECT id_client, type_client, nom_raison_sociale, prenom, numero_piece,
               telephone, email, anciennete_bancaire_mois
        FROM clients
        $conditionRecherche
        ORDER BY id_client DESC
        LIMIT :limite OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($parametres as $cle => $valeur) {
    $stmt->bindValue($cle, $valeur);
}
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll();

$titrePage = 'Clients';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Clients</h1>
    <div>
        <a href="<?= BASE_URL ?>/modules/exports/clients_excel.php" class="btn btn-outline-secondary btn-sm">Exporter en Excel</a>
        <a href="ajouter.php" class="btn btn-navy btn-sm">+ Nouveau client</a>
    </div>
</div>

<form method="get" class="row g-2 mb-2">
    <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm"
               placeholder="Rechercher (nom, NINEA/CNI, téléphone)"
               value="<?= e($recherche) ?>">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Rechercher</button>
    </div>
</form>

<div class="mb-3">
    <input type="text" class="form-control form-control-sm" style="max-width:320px;"
           placeholder="Filtre rapide sur cette page…" data-table-filter="#tableClients">
</div>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="table-responsive table-sticky">
        <table class="table table-hover align-middle mb-0" id="tableClients">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Nom / Raison sociale</th>
                    <th>Pièce (NINEA/CNI)</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th>Ancienneté (mois)</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Aucun client trouvé.</td></tr>
                <?php endif; ?>
                <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><?= e(ucfirst($client['type_client'])) ?></td>
                        <td>
                            <?= e($client['nom_raison_sociale']) ?>
                            <?= $client['prenom'] ? e($client['prenom']) : '' ?>
                        </td>
                        <td><?= e($client['numero_piece']) ?></td>
                        <td><?= e($client['telephone']) ?></td>
                        <td><?= e($client['email']) ?></td>
                        <td><?= (int) $client['anciennete_bancaire_mois'] ?></td>
                        <td class="text-end">
                            <a href="voir.php?id=<?= (int) $client['id_client'] ?>"
                               class="btn btn-sm btn-outline-secondary">Dossier</a>
                            <a href="modifier.php?id=<?= (int) $client['id_client'] ?>"
                               class="btn btn-sm btn-outline-secondary">Modifier</a>
                            <?php if ($_SESSION['role'] === 'administrateur'): ?>
                                <a href="supprimer.php?id=<?= (int) $client['id_client'] ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Confirmez-vous la suppression de ce client ?');">Supprimer</a>
                            <?php endif; ?>
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
                    <a class="page-link" href="?page=<?= $p ?>&q=<?= urlencode($recherche) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<p class="text-muted small"><?= (int) $totalClients ?> client(s) au total.</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
