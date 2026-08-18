<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']); // le comité ne saisit pas de clients

global $pdo;

$erreurs  = [];
$donnees  = [
    'type_client' => 'particulier', 'nom_raison_sociale' => '', 'prenom' => '',
    'numero_piece' => '', 'telephone' => '', 'email' => '', 'adresse' => '',
    'revenu_mensuel' => '', 'chiffre_affaires' => '', 'anciennete_bancaire_mois' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $donnees['type_client']              = $_POST['type_client'] ?? 'particulier';
    $donnees['nom_raison_sociale']       = trim($_POST['nom_raison_sociale'] ?? '');
    $donnees['prenom']                    = trim($_POST['prenom'] ?? '');
    $donnees['numero_piece']              = trim($_POST['numero_piece'] ?? '');
    $donnees['telephone']                 = trim($_POST['telephone'] ?? '');
    $donnees['email']                     = trim($_POST['email'] ?? '');
    $donnees['adresse']                   = trim($_POST['adresse'] ?? '');
    $donnees['revenu_mensuel']            = $_POST['revenu_mensuel'] ?? null;
    $donnees['chiffre_affaires']          = $_POST['chiffre_affaires'] ?? null;
    $donnees['anciennete_bancaire_mois']  = (int) ($_POST['anciennete_bancaire_mois'] ?? 0);

    // --- Validation côté serveur (seule protection réellement fiable) ---
    if (!in_array($donnees['type_client'], ['particulier', 'entreprise'], true)) {
        $erreurs[] = 'Type de client invalide.';
    }
    if ($donnees['nom_raison_sociale'] === '') {
        $erreurs[] = 'Le nom (ou la raison sociale) est obligatoire.';
    }
    if ($donnees['numero_piece'] === '') {
        $erreurs[] = 'Le numéro de pièce (CNI ou NINEA) est obligatoire.';
    }
    if (!preg_match('/^\d{9}$/', $donnees['telephone'])) {
        $erreurs[] = 'Le téléphone doit contenir exactement 9 chiffres.';
    }
    if ($donnees['email'] !== '' && !filter_var($donnees['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'L\'adresse email n\'est pas valide.';
    }

    if (empty($erreurs)) {
        $stmt = $pdo->prepare(
            'INSERT INTO clients (type_client, nom_raison_sociale, prenom, numero_piece,
                telephone, email, adresse, revenu_mensuel, chiffre_affaires,
                anciennete_bancaire_mois, cree_par)
             VALUES (:type_client, :nom_raison_sociale, :prenom, :numero_piece,
                :telephone, :email, :adresse, :revenu_mensuel, :chiffre_affaires,
                :anciennete_bancaire_mois, :cree_par)'
        );
        $stmt->execute([
            'type_client'              => $donnees['type_client'],
            'nom_raison_sociale'       => $donnees['nom_raison_sociale'],
            'prenom'                    => $donnees['prenom'] ?: null,
            'numero_piece'              => $donnees['numero_piece'],
            'telephone'                 => $donnees['telephone'],
            'email'                     => $donnees['email'] ?: null,
            'adresse'                   => $donnees['adresse'] ?: null,
            'revenu_mensuel'            => $donnees['revenu_mensuel'] ?: null,
            'chiffre_affaires'          => $donnees['chiffre_affaires'] ?: null,
            'anciennete_bancaire_mois'  => $donnees['anciennete_bancaire_mois'],
            'cree_par'                  => $_SESSION['id_utilisateur'],
        ]);

        $idClient = (int) $pdo->lastInsertId();
        enregistrerAudit('CREATION_CLIENT', 'clients', $idClient, 'Création du client ' . $donnees['nom_raison_sociale']);

        rediriger('liste.php?succes=' . urlencode('Client ajouté avec succès.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Ajouter un client';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Ajouter un client</h1>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($erreurs as $erreur): ?>
                <li><?= e($erreur) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="ajouter.php" data-validate="true" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Type de client</label>
                    <select name="type_client" class="form-select" required>
                        <option value="particulier" <?= $donnees['type_client'] === 'particulier' ? 'selected' : '' ?>>Particulier</option>
                        <option value="entreprise" <?= $donnees['type_client'] === 'entreprise' ? 'selected' : '' ?>>Entreprise</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Nom / Raison sociale</label>
                    <input type="text" name="nom_raison_sociale" class="form-control" required
                           value="<?= e($donnees['nom_raison_sociale']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Prénom (si particulier)</label>
                    <input type="text" name="prenom" class="form-control"
                           value="<?= e($donnees['prenom']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">N° pièce (CNI ou NINEA)</label>
                    <input type="text" name="numero_piece" class="form-control" required
                           value="<?= e($donnees['numero_piece']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Téléphone (9 chiffres)</label>
                    <input type="text" name="telephone" class="form-control" required
                           pattern="\d{9}" maxlength="9"
                           value="<?= e($donnees['telephone']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= e($donnees['email']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Adresse</label>
                    <input type="text" name="adresse" class="form-control"
                           value="<?= e($donnees['adresse']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Revenu mensuel (particulier)</label>
                    <input type="number" step="1" min="0" name="revenu_mensuel" class="form-control" data-type="montant"
                           value="<?= e((string) $donnees['revenu_mensuel']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Chiffre d'affaires (entreprise)</label>
                    <input type="number" step="1" min="0" name="chiffre_affaires" class="form-control" data-type="montant"
                           value="<?= e((string) $donnees['chiffre_affaires']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ancienneté bancaire (mois)</label>
                    <input type="number" min="0" name="anciennete_bancaire_mois" class="form-control"
                           value="<?= (int) $donnees['anciennete_bancaire_mois'] ?>">
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
