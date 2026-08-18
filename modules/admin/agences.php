<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();
    $pdo->prepare('INSERT INTO agences (nom, ville) VALUES (:nom, :ville)')
        ->execute(['nom' => $_POST['nom'], 'ville' => $_POST['ville']]);
    enregistrerAudit('CREATION_AGENCE', 'agences', (int) $pdo->lastInsertId(), 'Création de l\'agence ' . $_POST['nom']);
    rediriger('agences.php?succes=' . urlencode('Agence créée.'));
}

$agences = $pdo->query(
    "SELECT a.*, (SELECT COUNT(*) FROM utilisateurs u WHERE u.id_agence = a.id_agence) AS nb_utilisateurs
     FROM agences a ORDER BY a.nom"
)->fetchAll();

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Agences';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4"><i class="bi bi-building me-2 text-navy"></i>Agences</h1>
<?php if (isset($_GET['succes'])): ?><div class="alert alert-success small"><?= e($_GET['succes']) ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Nom</th><th>Ville</th><th>Utilisateurs rattachés</th></tr></thead>
            <tbody>
                <?php foreach ($agences as $a): ?>
                    <tr><td><?= e($a['nom']) ?></td><td><?= e($a['ville']) ?></td><td><?= (int) $a['nb_utilisateurs'] ?></td></tr>
                <?php endforeach; ?>
                <?php if (empty($agences)): ?><tr><td colspan="3" class="text-center text-muted py-3">Aucune agence.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Nouvelle agence</div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <div class="col-md-5"><input type="text" name="nom" class="form-control form-control-sm" placeholder="Nom de l'agence" required></div>
            <div class="col-md-5"><input type="text" name="ville" class="form-control form-control-sm" placeholder="Ville" required></div>
            <div class="col-md-2"><button type="submit" class="btn btn-navy btn-sm w-100">Créer</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
