<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

$stmt = $pdo->query(
    'SELECT id_utilisateur, nom, prenom, email, role, telephone, actif, date_creation, derniere_connexion
     FROM utilisateurs ORDER BY id_utilisateur'
);
$utilisateurs = $stmt->fetchAll();

$libellesRoles = [
    'administrateur'   => 'Administrateur',
    'charge_clientele' => 'Chargé de clientèle',
    'comite_direction' => 'Comité / Direction',
];

$titrePage = 'Utilisateurs';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Utilisateurs</h1>
    <a href="ajouter.php" class="btn btn-navy btn-sm">+ Nouvel utilisateur</a>
</div>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['erreur'])): ?>
    <div class="alert alert-danger small"><?= e($_GET['erreur']) ?></div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Email</th>
                    <th>Rôle</th>
                    <th>Téléphone</th>
                    <th>Statut</th>
                    <th>Dernière connexion</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($utilisateurs as $u): ?>
                    <tr>
                        <td><?= e($u['prenom'] . ' ' . $u['nom']) ?></td>
                        <td><?= e($u['email']) ?></td>
                        <td><?= e($libellesRoles[$u['role']] ?? $u['role']) ?></td>
                        <td><?= e($u['telephone'] ?? '—') ?></td>
                        <td>
                            <?php if ($u['actif']): ?>
                                <span class="badge bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Désactivé</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?= $u['derniere_connexion'] ? e(date('d/m/Y H:i', strtotime($u['derniere_connexion']))) : 'Jamais' ?>
                        </td>
                        <td class="text-end">
                            <a href="modifier.php?id=<?= (int) $u['id_utilisateur'] ?>" class="btn btn-sm btn-outline-secondary">Modifier</a>
                            <?php if ($u['id_utilisateur'] !== (int) $_SESSION['id_utilisateur']): ?>
                                <form method="post" action="basculer_statut.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= e(genererJetonCSRF()) ?>">
                                    <input type="hidden" name="id_utilisateur" value="<?= (int) $u['id_utilisateur'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-<?= $u['actif'] ? 'danger' : 'success' ?>">
                                        <?= $u['actif'] ? 'Désactiver' : 'Réactiver' ?>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3"><?= count($utilisateurs) ?> utilisateur(s) au total.</p>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
