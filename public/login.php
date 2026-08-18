<?php
require_once __DIR__ . '/../includes/auth.php';

// Si déjà connecté, redirection directe vers le tableau de bord
if (!empty($_SESSION['id_utilisateur'])) {
    rediriger(BASE_URL . '/public/dashboard.php');
}

$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $email      = trim($_POST['email'] ?? '');
    $motDePasse = $_POST['mot_de_passe'] ?? '';

    if ($email === '' || $motDePasse === '') {
        $erreur = 'Veuillez renseigner votre email et votre mot de passe.';
    } elseif (connecterUtilisateur($email, $motDePasse)) {
        rediriger(BASE_URL . '/public/dashboard.php');
    } else {
        $erreur = 'Email ou mot de passe incorrect.';
    }
}

$jetonCSRF = genererJetonCSRF();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion — Plateforme Crédit Bancaire</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-login d-flex align-items-center justify-content-center vh-100">
    <div class="card shadow-lg p-4" style="width: 100%; max-width: 420px;">
        <div class="text-center mb-4">
            <div class="mx-auto mb-2 d-inline-flex align-items-center justify-content-center bg-navy bg-opacity-10 text-navy rounded-circle" style="width:56px;height:56px;font-size:1.6rem;">
                <i class="bi bi-bank2"></i>
            </div>
            <h1 class="h4 fw-bold text-navy mb-0">Crédit Bancaire</h1>
            <p class="text-muted small">Gestion des demandes de crédit &amp; scoring</p>
        </div>

        <?php if (isset($_GET['expire'])): ?>
            <div class="alert alert-warning small">Votre session a expiré par inactivité. Veuillez vous reconnecter.</div>
        <?php endif; ?>

        <?php if ($erreur): ?>
            <div class="alert alert-danger small"><?= e($erreur) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">

            <div class="mb-3">
                <label for="email" class="form-label">Adresse email</label>
                <input type="email" class="form-control" id="email" name="email" required
                       value="<?= e($_POST['email'] ?? '') ?>">
            </div>

            <div class="mb-3">
                <label for="mot_de_passe" class="form-label">Mot de passe</label>
                <input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe" required>
            </div>

            <button type="submit" class="btn btn-navy w-100">Se connecter</button>
        </form>

        <p class="text-muted small text-center mt-3 mb-0">
            Comptes de test : admin@creditbanque.sn / charge@creditbanque.sn / comite@creditbanque.sn
        </p>
    </div>
</body>
</html>
