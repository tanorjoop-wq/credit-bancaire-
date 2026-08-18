<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();
    $action = $_POST['action'] ?? '';

    if ($action === 'ajouter') {
        $pdo->prepare(
            'INSERT INTO produits_credit (nom, type_credit, taux_min, taux_max, duree_min_mois, duree_max_mois, plafond, actif)
             VALUES (:nom, :type, :tmin, :tmax, :dmin, :dmax, :plafond, 1)'
        )->execute([
            'nom' => $_POST['nom'], 'type' => $_POST['type_credit'], 'tmin' => $_POST['taux_min'], 'tmax' => $_POST['taux_max'],
            'dmin' => $_POST['duree_min_mois'], 'dmax' => $_POST['duree_max_mois'], 'plafond' => $_POST['plafond'],
        ]);
        enregistrerAudit('CREATION_PRODUIT', 'produits_credit', (int) $pdo->lastInsertId(), 'Création du produit ' . $_POST['nom']);
    } elseif ($action === 'basculer') {
        $id = (int) $_POST['id_produit'];
        $pdo->prepare('UPDATE produits_credit SET actif = 1 - actif WHERE id_produit = :id')->execute(['id' => $id]);
        enregistrerAudit('MODIFICATION_PRODUIT', 'produits_credit', $id, 'Basculement du statut actif/inactif');
    }
    rediriger('produits.php?succes=' . urlencode('Enregistré.'));
}

$produits = $pdo->query('SELECT * FROM produits_credit ORDER BY id_produit')->fetchAll();
$jetonCSRF = genererJetonCSRF();
$titrePage = 'Produits de crédit';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4"><i class="bi bi-boxes me-2 text-navy"></i>Produits de crédit</h1>
<?php if (isset($_GET['succes'])): ?><div class="alert alert-success small"><?= e($_GET['succes']) ?></div><?php endif; ?>

<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Nom</th><th>Type</th><th>Taux</th><th>Durée</th><th>Plafond</th><th>Statut</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($produits as $p): ?>
                    <tr>
                        <td><?= e($p['nom']) ?></td>
                        <td><?= e(ucfirst($p['type_credit'])) ?></td>
                        <td><?= e($p['taux_min']) ?> – <?= e($p['taux_max']) ?> %</td>
                        <td><?= (int) $p['duree_min_mois'] ?> – <?= (int) $p['duree_max_mois'] ?> mois</td>
                        <td><?= formaterMontant($p['plafond']) ?></td>
                        <td><span class="badge <?= $p['actif'] ? 'bg-success' : 'bg-secondary' ?>"><?= $p['actif'] ? 'Actif' : 'Inactif' ?></span></td>
                        <td class="text-end">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                                <input type="hidden" name="action" value="basculer">
                                <input type="hidden" name="id_produit" value="<?= (int) $p['id_produit'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"><?= $p['actif'] ? 'Désactiver' : 'Activer' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($produits)): ?><tr><td colspan="7" class="text-center text-muted py-3">Aucun produit défini.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Nouveau produit</div>
    <div class="card-body">
        <form method="post" class="row g-2">
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <input type="hidden" name="action" value="ajouter">
            <div class="col-md-3"><input type="text" name="nom" class="form-control form-control-sm" placeholder="Nom du produit" required></div>
            <div class="col-md-2">
                <select name="type_credit" class="form-select form-select-sm" required>
                    <option value="consommation">Consommation</option>
                    <option value="immobilier">Immobilier</option>
                    <option value="investissement">Investissement</option>
                    <option value="tresorerie">Trésorerie</option>
                </select>
            </div>
            <div class="col-md-1"><input type="number" step="0.01" name="taux_min" class="form-control form-control-sm" placeholder="Taux min" required></div>
            <div class="col-md-1"><input type="number" step="0.01" name="taux_max" class="form-control form-control-sm" placeholder="Taux max" required></div>
            <div class="col-md-1"><input type="number" name="duree_min_mois" class="form-control form-control-sm" placeholder="Durée min" required></div>
            <div class="col-md-1"><input type="number" name="duree_max_mois" class="form-control form-control-sm" placeholder="Durée max" required></div>
            <div class="col-md-2"><input type="number" name="plafond" class="form-control form-control-sm" placeholder="Plafond FCFA" required></div>
            <div class="col-md-1"><button type="submit" class="btn btn-navy btn-sm w-100">+</button></div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
