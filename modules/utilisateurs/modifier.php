<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

$idUtilisateur = (int) ($_GET['id'] ?? $_POST['id_utilisateur'] ?? 0);
if ($idUtilisateur <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare('SELECT * FROM utilisateurs WHERE id_utilisateur = :id');
$stmt->execute(['id' => $idUtilisateur]);
$utilisateur = $stmt->fetch();

if (!$utilisateur) {
    http_response_code(404);
    die('Utilisateur introuvable.');
}

$erreurs = [];
$rolesValides = ['administrateur', 'charge_clientele', 'comite_direction'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $utilisateur['nom']       = trim($_POST['nom'] ?? '');
    $utilisateur['prenom']    = trim($_POST['prenom'] ?? '');
    $utilisateur['telephone'] = trim($_POST['telephone'] ?? '');
    $utilisateur['role']      = $_POST['role'] ?? $utilisateur['role'];
    $nouveauMotDePasse        = $_POST['mot_de_passe'] ?? '';

    if ($utilisateur['nom'] === '' || $utilisateur['prenom'] === '') {
        $erreurs[] = 'Le nom et le prénom sont obligatoires.';
    }
    if (!in_array($utilisateur['role'], $rolesValides, true)) {
        $erreurs[] = 'Rôle invalide.';
    }
    // Empêche l'admin de se retirer son propre rôle d'administrateur (évite un verrouillage total)
    if ($idUtilisateur === (int) $_SESSION['id_utilisateur'] && $utilisateur['role'] !== 'administrateur') {
        $erreurs[] = 'Vous ne pouvez pas retirer votre propre rôle administrateur.';
    }
    if ($nouveauMotDePasse !== '' && strlen($nouveauMotDePasse) < 8) {
        $erreurs[] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
    }

    if (empty($erreurs)) {
        if ($nouveauMotDePasse !== '') {
            $maj = $pdo->prepare(
                'UPDATE utilisateurs SET nom = :nom, prenom = :prenom, telephone = :telephone,
                    role = :role, mot_de_passe_hash = :hash WHERE id_utilisateur = :id'
            );
            $maj->execute([
                'nom' => $utilisateur['nom'], 'prenom' => $utilisateur['prenom'],
                'telephone' => $utilisateur['telephone'] ?: null, 'role' => $utilisateur['role'],
                'hash' => password_hash($nouveauMotDePasse, PASSWORD_DEFAULT), 'id' => $idUtilisateur,
            ]);
        } else {
            $maj = $pdo->prepare(
                'UPDATE utilisateurs SET nom = :nom, prenom = :prenom, telephone = :telephone,
                    role = :role WHERE id_utilisateur = :id'
            );
            $maj->execute([
                'nom' => $utilisateur['nom'], 'prenom' => $utilisateur['prenom'],
                'telephone' => $utilisateur['telephone'] ?: null, 'role' => $utilisateur['role'], 'id' => $idUtilisateur,
            ]);
        }

        enregistrerAudit('MODIFICATION_UTILISATEUR', 'utilisateurs', $idUtilisateur, 'Modification du compte ' . $utilisateur['email']);

        // Si l'admin modifie son propre compte, met à jour le nom affiché en session
        if ($idUtilisateur === (int) $_SESSION['id_utilisateur']) {
            $_SESSION['nom_complet'] = $utilisateur['prenom'] . ' ' . $utilisateur['nom'];
        }

        rediriger('liste.php?succes=' . urlencode('Utilisateur modifié avec succès.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Modifier l\'utilisateur';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Modifier — <?= e($utilisateur['prenom'] . ' ' . $utilisateur['nom']) ?></h1>
<p class="text-muted small"><?= e($utilisateur['email']) ?> (l'email ne peut pas être modifié)</p>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($erreurs as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="modifier.php" data-validate="true" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <input type="hidden" name="id_utilisateur" value="<?= (int) $idUtilisateur ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" required value="<?= e($utilisateur['prenom']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control" required value="<?= e($utilisateur['nom']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-control" value="<?= e($utilisateur['telephone']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rôle</label>
                    <select name="role" class="form-select" required>
                        <option value="charge_clientele" <?= $utilisateur['role'] === 'charge_clientele' ? 'selected' : '' ?>>Chargé de clientèle</option>
                        <option value="comite_direction" <?= $utilisateur['role'] === 'comite_direction' ? 'selected' : '' ?>>Comité / Direction</option>
                        <option value="administrateur" <?= $utilisateur['role'] === 'administrateur' ? 'selected' : '' ?>>Administrateur</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nouveau mot de passe (laisser vide pour ne pas changer)</label>
                    <input type="password" name="mot_de_passe" class="form-control" minlength="8">
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Enregistrer</button>
                <a href="liste.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
