<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

$clients = $pdo->query(
    'SELECT id_client, type_client, nom_raison_sociale, prenom FROM clients ORDER BY nom_raison_sociale'
)->fetchAll();

$erreurs = [];
$donnees = [
    'id_client'            => (int) ($_GET['id_client'] ?? 0),
    'type_credit'          => 'consommation',
    'montant_demande'      => '',
    'duree_mois'           => '',
    'taux_interet_propose' => '',
    'objet_credit'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $donnees['id_client']            = (int) ($_POST['id_client'] ?? 0);
    $donnees['type_credit']          = $_POST['type_credit'] ?? 'consommation';
    $donnees['montant_demande']      = $_POST['montant_demande'] ?? '';
    $donnees['duree_mois']           = $_POST['duree_mois'] ?? '';
    $donnees['taux_interet_propose'] = $_POST['taux_interet_propose'] ?? '';
    $donnees['objet_credit']         = trim($_POST['objet_credit'] ?? '');

    $typesValides = ['consommation', 'immobilier', 'investissement', 'tresorerie'];

    if ($donnees['id_client'] <= 0) {
        $erreurs[] = 'Veuillez sélectionner un client.';
    }
    if (!in_array($donnees['type_credit'], $typesValides, true)) {
        $erreurs[] = 'Type de crédit invalide.';
    }
    if (!is_numeric($donnees['montant_demande']) || (float) $donnees['montant_demande'] <= 0) {
        $erreurs[] = 'Le montant demandé doit être un nombre positif.';
    }
    if (!ctype_digit((string) $donnees['duree_mois']) || (int) $donnees['duree_mois'] <= 0) {
        $erreurs[] = 'La durée doit être un nombre entier de mois positif.';
    }
    if (!is_numeric($donnees['taux_interet_propose']) || (float) $donnees['taux_interet_propose'] < 0) {
        $erreurs[] = 'Le taux d\'intérêt proposé est invalide.';
    }

    if (empty($erreurs)) {
        // Vérifie que le client existe réellement
        $verifClient = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE id_client = :id');
        $verifClient->execute(['id' => $donnees['id_client']]);
        if ((int) $verifClient->fetchColumn() === 0) {
            $erreurs[] = 'Client introuvable.';
        }
    }

    if (empty($erreurs)) {
        // Génère une référence séquentielle du type CRD-2026-0004
        $annee = date('Y');
        $stmtRef = $pdo->prepare(
            "SELECT reference FROM demandes_credit WHERE reference LIKE :motif ORDER BY id_demande DESC LIMIT 1"
        );
        $stmtRef->execute(['motif' => "CRD-{$annee}-%"]);
        $derniereRef = $stmtRef->fetchColumn();
        $prochainNumero = $derniereRef ? ((int) substr($derniereRef, -4) + 1) : 1;
        $reference = sprintf('CRD-%s-%04d', $annee, $prochainNumero);

        $stmt = $pdo->prepare(
            'INSERT INTO demandes_credit (reference, id_client, type_credit, montant_demande,
                duree_mois, taux_interet_propose, objet_credit, statut, charge_id)
             VALUES (:reference, :id_client, :type_credit, :montant_demande,
                :duree_mois, :taux_interet_propose, :objet_credit, :statut, :charge_id)'
        );
        $stmt->execute([
            'reference'             => $reference,
            'id_client'             => $donnees['id_client'],
            'type_credit'           => $donnees['type_credit'],
            'montant_demande'       => $donnees['montant_demande'],
            'duree_mois'            => $donnees['duree_mois'],
            'taux_interet_propose'  => $donnees['taux_interet_propose'],
            'objet_credit'          => $donnees['objet_credit'] ?: null,
            'statut'                => 'en_attente',
            'charge_id'             => $_SESSION['id_utilisateur'],
        ]);

        $idDemande = (int) $pdo->lastInsertId();
        enregistrerAudit('CREATION_DEMANDE', 'demandes_credit', $idDemande, 'Création de la demande ' . $reference);

        rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Demande créée avec succès.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Nouvelle demande de crédit';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Nouvelle demande de crédit</h1>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0">
            <?php foreach ($erreurs as $erreur): ?>
                <li><?= e($erreur) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (empty($clients)): ?>
    <div class="alert alert-warning small">
        Aucun client enregistré. <a href="<?= BASE_URL ?>/modules/clients/ajouter.php">Créez d'abord un client</a>.
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="ajouter.php" data-validate="true" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Client</label>
                    <select name="id_client" class="form-select" required>
                        <option value="">-- Sélectionner un client --</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?= (int) $client['id_client'] ?>"
                                <?= $donnees['id_client'] === (int) $client['id_client'] ? 'selected' : '' ?>>
                                <?= e($client['nom_raison_sociale']) ?> <?= e($client['prenom'] ?? '') ?>
                                (<?= e(ucfirst($client['type_client'])) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type de crédit</label>
                    <select name="type_credit" class="form-select" required>
                        <?php foreach (['consommation', 'immobilier', 'investissement', 'tresorerie'] as $type): ?>
                            <option value="<?= $type ?>" <?= $donnees['type_credit'] === $type ? 'selected' : '' ?>>
                                <?= e(ucfirst($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Montant demandé (FCFA)</label>
                    <input type="number" step="1" min="1" name="montant_demande" class="form-control" data-type="montant" required
                           value="<?= e((string) $donnees['montant_demande']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Durée (mois)</label>
                    <input type="number" step="1" min="1" name="duree_mois" class="form-control" required
                           value="<?= e((string) $donnees['duree_mois']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Taux d'intérêt proposé (%)</label>
                    <input type="number" step="0.01" min="0" name="taux_interet_propose" class="form-control" required
                           value="<?= e((string) $donnees['taux_interet_propose']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Objet du crédit</label>
                    <input type="text" name="objet_credit" class="form-control"
                           value="<?= e($donnees['objet_credit']) ?>">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Créer la demande</button>
                <a href="liste.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
