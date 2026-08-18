<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur']);

global $pdo;

$erreurs = [];
$donnees = ['nom' => '', 'prenom' => '', 'email' => '', 'role' => 'charge_clientele', 'telephone' => ''];
$rolesValides = ['administrateur', 'charge_clientele', 'comite_direction'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $donnees['nom']       = trim($_POST['nom'] ?? '');
    $donnees['prenom']    = trim($_POST['prenom'] ?? '');
    $donnees['email']     = trim($_POST['email'] ?? '');
    $donnees['role']      = $_POST['role'] ?? 'charge_clientele';
    $donnees['telephone'] = trim($_POST['telephone'] ?? '');
    $motDePasse           = $_POST['mot_de_passe'] ?? '';

    if ($donnees['nom'] === '' || $donnees['prenom'] === '') {
        $erreurs[] = 'Le nom et le prénom sont obligatoires.';
    }
    if (!filter_var($donnees['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'Adresse email invalide.';
    }
    if (!in_array($donnees['role'], $rolesValides, true)) {
        $erreurs[] = 'Rôle invalide.';
    }
    if (strlen($motDePasse) < 8) {
        $erreurs[] = 'Le mot de passe doit contenir au moins 8 caractères.';
    }

    if (empty($erreurs)) {
        $verif = $pdo->prepare('SELECT COUNT(*) FROM utilisateurs WHERE email = :email');
        $verif->execute(['email' => $donnees['email']]);
        if ((int) $verif->fetchColumn() > 0) {
            $erreurs[] = 'Cette adresse email est déjà utilisée.';
        }
    }

    if (empty($erreurs)) {
        $stmt = $pdo->prepare(
            'INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe_hash, role, telephone, actif)
             VALUES (:nom, :prenom, :email, :hash, :role, :telephone, 1)'
        );
        $stmt->execute([
            'nom'       => $donnees['nom'],
            'prenom'    => $donnees['prenom'],
            'email'     => $donnees['email'],
            'hash'      => password_hash($motDePasse, PASSWORD_DEFAULT),
            'role'      => $donnees['role'],
            'telephone' => $donnees['telephone'] ?: null,
        ]);

        $idUtilisateur = (int) $pdo->lastInsertId();
        enregistrerAudit('CREATION_UTILISATEUR', 'utilisateurs', $idUtilisateur, 'Création du compte ' . $donnees['email'] . ' (rôle ' . $donnees['role'] . ')');

        rediriger('liste.php?succes=' . urlencode('Utilisateur créé avec succès.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Nouvel utilisateur';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Nouvel utilisateur</h1>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($erreurs as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="ajouter.php" data-validate="true" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Prénom</label>
                    <input type="text" name="prenom" class="form-control" required value="<?= e($donnees['prenom']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control" required value="<?= e($donnees['nom']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" required value="<?= e($donnees['email']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-control" value="<?= e($donnees['telephone']) ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Rôle</label>
                    <select name="role" class="form-select" required>
                        <option value="charge_clientele" <?= $donnees['role'] === 'charge_clientele' ? 'selected' : '' ?>>Chargé de clientèle</option>
                        <option value="comite_direction" <?= $donnees['role'] === 'comite_direction' ? 'selected' : '' ?>>Comité / Direction</option>
                        <option value="administrateur" <?= $donnees['role'] === 'administrateur' ? 'selected' : '' ?>>Administrateur</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mot de passe (8 caractères min.)</label>
                    <input type="password" name="mot_de_passe" class="form-control" required minlength="8">
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Créer le compte</button>
                <a href="liste.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
