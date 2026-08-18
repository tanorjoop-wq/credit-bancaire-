<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

$idClient = (int) ($_GET['id'] ?? $_POST['id_client'] ?? 0);
if ($idClient <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    die('Client introuvable.');
}

$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $client['type_client']             = $_POST['type_client'] ?? 'particulier';
    $client['nom_raison_sociale']      = trim($_POST['nom_raison_sociale'] ?? '');
    $client['prenom']                   = trim($_POST['prenom'] ?? '');
    $client['numero_piece']             = trim($_POST['numero_piece'] ?? '');
    $client['telephone']                = trim($_POST['telephone'] ?? '');
    $client['email']                    = trim($_POST['email'] ?? '');
    $client['adresse']                  = trim($_POST['adresse'] ?? '');
    $client['revenu_mensuel']           = $_POST['revenu_mensuel'] ?? null;
    $client['chiffre_affaires']         = $_POST['chiffre_affaires'] ?? null;
    $client['anciennete_bancaire_mois'] = (int) ($_POST['anciennete_bancaire_mois'] ?? 0);

    if ($client['nom_raison_sociale'] === '') {
        $erreurs[] = 'Le nom (ou la raison sociale) est obligatoire.';
    }
    if (!preg_match('/^\d{9}$/', $client['telephone'])) {
        $erreurs[] = 'Le téléphone doit contenir exactement 9 chiffres.';
    }
    if ($client['email'] !== '' && !filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
        $erreurs[] = 'L\'adresse email n\'est pas valide.';
    }

    if (empty($erreurs)) {
        $maj = $pdo->prepare(
            'UPDATE clients SET
                type_client = :type_client,
                nom_raison_sociale = :nom_raison_sociale,
                prenom = :prenom,
                numero_piece = :numero_piece,
                telephone = :telephone,
                email = :email,
                adresse = :adresse,
                revenu_mensuel = :revenu_mensuel,
                chiffre_affaires = :chiffre_affaires,
                anciennete_bancaire_mois = :anciennete_bancaire_mois
             WHERE id_client = :id'
        );
        $maj->execute([
            'type_client'              => $client['type_client'],
            'nom_raison_sociale'       => $client['nom_raison_sociale'],
            'prenom'                    => $client['prenom'] ?: null,
            'numero_piece'              => $client['numero_piece'],
            'telephone'                 => $client['telephone'],
            'email'                     => $client['email'] ?: null,
            'adresse'                   => $client['adresse'] ?: null,
            'revenu_mensuel'            => $client['revenu_mensuel'] ?: null,
            'chiffre_affaires'          => $client['chiffre_affaires'] ?: null,
            'anciennete_bancaire_mois'  => $client['anciennete_bancaire_mois'],
            'id'                        => $idClient,
        ]);

        enregistrerAudit('MODIFICATION_CLIENT', 'clients', $idClient, 'Modification du client ' . $client['nom_raison_sociale']);

        rediriger('liste.php?succes=' . urlencode('Client modifié avec succès.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Modifier un client';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Modifier le client — <?= e($client['nom_raison_sociale']) ?></h1>

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
        <form method="post" action="modifier.php" data-validate="true" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <input type="hidden" name="id_client" value="<?= (int) $client['id_client'] ?>">

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Type de client</label>
                    <select name="type_client" class="form-select" required>
                        <option value="particulier" <?= $client['type_client'] === 'particulier' ? 'selected' : '' ?>>Particulier</option>
                        <option value="entreprise" <?= $client['type_client'] === 'entreprise' ? 'selected' : '' ?>>Entreprise</option>
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Nom / Raison sociale</label>
                    <input type="text" name="nom_raison_sociale" class="form-control" required
                           value="<?= e($client['nom_raison_sociale']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Prénom (si particulier)</label>
                    <input type="text" name="prenom" class="form-control"
                           value="<?= e($client['prenom']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">N° pièce (CNI ou NINEA)</label>
                    <input type="text" name="numero_piece" class="form-control" required
                           value="<?= e($client['numero_piece']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Téléphone (9 chiffres)</label>
                    <input type="text" name="telephone" class="form-control" required
                           pattern="\d{9}" maxlength="9"
                           value="<?= e($client['telephone']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= e($client['email']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Adresse</label>
                    <input type="text" name="adresse" class="form-control"
                           value="<?= e($client['adresse']) ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Revenu mensuel (particulier)</label>
                    <input type="number" step="1" min="0" name="revenu_mensuel" class="form-control" data-type="montant"
                           value="<?= e((string) $client['revenu_mensuel']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Chiffre d'affaires (entreprise)</label>
                    <input type="number" step="1" min="0" name="chiffre_affaires" class="form-control" data-type="montant"
                           value="<?= e((string) $client['chiffre_affaires']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ancienneté bancaire (mois)</label>
                    <input type="number" min="0" name="anciennete_bancaire_mois" class="form-control"
                           value="<?= (int) $client['anciennete_bancaire_mois'] ?>">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Enregistrer les modifications</button>
                <a href="liste.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
