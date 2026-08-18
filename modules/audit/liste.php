<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

// --- Filtres ---
$actionFiltre = trim($_GET['action'] ?? '');
$utilisateurFiltre = (int) ($_GET['utilisateur'] ?? 0);
$roleFiltre = trim($_GET['role'] ?? '');
$dateDebut = trim($_GET['date_debut'] ?? '');
$dateFin = trim($_GET['date_fin'] ?? '');

$conditions = [];
$parametres = [];

if ($actionFiltre !== '') {
    $conditions[] = 'j.action = :action';
    $parametres['action'] = $actionFiltre;
}
if ($utilisateurFiltre > 0) {
    $conditions[] = 'j.id_utilisateur = :utilisateur';
    $parametres['utilisateur'] = $utilisateurFiltre;
}
if ($roleFiltre !== '' && in_array($roleFiltre, ['administrateur', 'charge_clientele', 'comite_direction'], true)) {
    $conditions[] = 'u.role = :role';
    $parametres['role'] = $roleFiltre;
}
if ($dateDebut !== '') {
    $conditions[] = 'j.date_action >= :date_debut';
    $parametres['date_debut'] = $dateDebut . ' 00:00:00';
}
if ($dateFin !== '') {
    $conditions[] = 'j.date_action <= :date_fin';
    $parametres['date_fin'] = $dateFin . ' 23:59:59';
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// --- Pagination ---
$parPage = 30;
$pageActuelle = max(1, (int) ($_GET['page'] ?? 1));
$offset = ($pageActuelle - 1) * $parPage;

$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM journal_audit j JOIN utilisateurs u ON u.id_utilisateur = j.id_utilisateur $whereSql");
$stmtTotal->execute($parametres);
$total = (int) $stmtTotal->fetchColumn();
$totalPages = (int) ceil($total / $parPage);

$stmt = $pdo->prepare(
    "SELECT j.*, u.nom, u.prenom, u.role
     FROM journal_audit j
     JOIN utilisateurs u ON u.id_utilisateur = j.id_utilisateur
     $whereSql
     ORDER BY j.id_audit DESC
     LIMIT :limite OFFSET :offset"
);
foreach ($parametres as $cle => $valeur) {
    $stmt->bindValue($cle, $valeur);
}
$stmt->bindValue('limite', $parPage, PDO::PARAM_INT);
$stmt->bindValue('offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$entrees = $stmt->fetchAll();

$actionsDisponibles = $pdo->query('SELECT DISTINCT action FROM journal_audit ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$utilisateursDisponibles = $pdo->query('SELECT id_utilisateur, nom, prenom FROM utilisateurs ORDER BY prenom')->fetchAll();

$titrePage = "Journal d'audit";
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Journal d'audit</h1>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-2">
        <select name="action" class="form-select form-select-sm">
            <option value="">Toutes les actions</option>
            <?php foreach ($actionsDisponibles as $a): ?>
                <option value="<?= e($a) ?>" <?= $actionFiltre === $a ? 'selected' : '' ?>><?= e($a) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="utilisateur" class="form-select form-select-sm">
            <option value="0">Tous les utilisateurs</option>
            <?php foreach ($utilisateursDisponibles as $u): ?>
                <option value="<?= (int) $u['id_utilisateur'] ?>" <?= $utilisateurFiltre === (int) $u['id_utilisateur'] ? 'selected' : '' ?>>
                    <?= e($u['prenom'] . ' ' . $u['nom']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="role" class="form-select form-select-sm">
            <option value="">Tous les rôles</option>
            <option value="administrateur" <?= $roleFiltre === 'administrateur' ? 'selected' : '' ?>>Administrateur</option>
            <option value="charge_clientele" <?= $roleFiltre === 'charge_clientele' ? 'selected' : '' ?>>Chargé de clientèle</option>
            <option value="comite_direction" <?= $roleFiltre === 'comite_direction' ? 'selected' : '' ?>>Comité / Direction</option>
        </select>
    </div>
    <div class="col-md-2">
        <input type="date" name="date_debut" class="form-control form-control-sm" value="<?= e($dateDebut) ?>">
    </div>
    <div class="col-md-2">
        <input type="date" name="date_fin" class="form-control form-control-sm" value="<?= e($dateFin) ?>">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Filtrer</button>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive table-sticky">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Utilisateur</th>
                    <th>Rôle</th>
                    <th>Action</th>
                    <th>Table</th>
                    <th>ID</th>
                    <th>Détails</th>
                    <th>Avant → Après</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($entrees)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucune entrée.</td></tr>
                <?php endif; ?>
                <?php foreach ($entrees as $entree): ?>
                    <tr>
                        <td class="small text-muted"><?= e(date('d/m/Y H:i:s', strtotime($entree['date_action']))) ?></td>
                        <td><?= e($entree['prenom'] . ' ' . $entree['nom']) ?></td>
                        <td class="small"><?= e($entree['role']) ?></td>
                        <td><span class="badge bg-secondary"><?= e($entree['action']) ?></span></td>
                        <td class="small"><?= e($entree['table_concernee']) ?></td>
                        <td class="small">#<?= $entree['id_enregistrement'] !== null ? (int) $entree['id_enregistrement'] : '—' ?></td>
                        <td class="small"><?= e($entree['details']) ?></td>
                        <td class="small">
                            <?php if (!empty($entree['ancienne_valeur']) || !empty($entree['nouvelle_valeur'])): ?>
                                <span class="badge bg-light text-dark border"><?= e($entree['ancienne_valeur']) ?></span>
                                <i class="bi bi-arrow-right mx-1"></i>
                                <span class="badge bg-navy"><?= e($entree['nouvelle_valeur']) ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
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
                    <a class="page-link" href="?page=<?= $p ?>&action=<?= urlencode($actionFiltre) ?>&utilisateur=<?= $utilisateurFiltre ?>&role=<?= urlencode($roleFiltre) ?>&date_debut=<?= urlencode($dateDebut) ?>&date_fin=<?= urlencode($dateFin) ?>"><?= $p ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

<p class="text-muted small"><?= $total ?> entrée(s) au total.</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
