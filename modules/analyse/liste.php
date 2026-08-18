<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$recherche = trim($_GET['q'] ?? '');
$conditions = [];
$parametres = [];
if ($recherche !== '') {
    $conditions[] = '(c.nom_raison_sociale LIKE :recherche OR c.prenom LIKE :recherche)';
    $parametres['recherche'] = '%' . $recherche . '%';
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare(
    "SELECT c.id_client, c.type_client, c.nom_raison_sociale, c.prenom,
            (SELECT COUNT(*) FROM donnees_financieres df WHERE df.id_client = c.id_client) AS nb_saisies,
            (SELECT MAX(date_exercice) FROM donnees_financieres df WHERE df.id_client = c.id_client) AS derniere_date
     FROM clients c
     $whereSql
     ORDER BY c.nom_raison_sociale"
);
$stmt->execute($parametres);
$clients = $stmt->fetchAll();

$titrePage = 'Analyse financière';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4"><i class="bi bi-graph-up me-2 text-navy"></i>Analyse financière</h1>
<p class="text-muted small">Ratios prudentiels (SIG/EBE, DSCR, FDR/BFR) calculés à partir des données financières saisies par client.</p>

<form method="get" class="row g-2 mb-3">
    <div class="col-md-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Rechercher un client" value="<?= e($recherche) ?>">
    </div>
    <div class="col-md-2">
        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Rechercher</button>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Client</th><th>Type</th><th>Données financières</th><th>Dernière saisie</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php if (empty($clients)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Aucun client.</td></tr>
                <?php endif; ?>
                <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><?= e($client['nom_raison_sociale']) ?> <?= e($client['prenom'] ?? '') ?></td>
                        <td><?= e(ucfirst($client['type_client'])) ?></td>
                        <td>
                            <?php if ($client['nb_saisies'] > 0): ?>
                                <span class="badge bg-success"><?= (int) $client['nb_saisies'] ?> exercice(s)</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Aucune saisie</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $client['derniere_date'] ? e(date('d/m/Y', strtotime($client['derniere_date']))) : '—' ?></td>
                        <td class="text-end">
                            <?php if ($client['nb_saisies'] > 0): ?>
                                <a href="voir.php?id_client=<?= (int) $client['id_client'] ?>" class="btn btn-sm btn-outline-secondary">Voir les ratios</a>
                            <?php endif; ?>
                            <a href="saisie.php?id_client=<?= (int) $client['id_client'] ?>" class="btn btn-sm btn-navy">Saisir des données</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
