<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

$idDemande = (int) ($_GET['id'] ?? $_POST['id_demande'] ?? 0);
if ($idDemande <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare(
    'SELECT d.*, c.nom_raison_sociale, c.prenom FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     WHERE d.id_demande = :id'
);
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

// Modification possible uniquement avant le calcul du scoring (données figées ensuite)
if (!in_array($demande['statut'], ['en_attente', 'en_analyse'], true)) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Cette demande ne peut plus être modifiée (scoring déjà calculé).'));
}

$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $demande['type_credit']          = $_POST['type_credit'] ?? $demande['type_credit'];
    $demande['montant_demande']      = $_POST['montant_demande'] ?? $demande['montant_demande'];
    $demande['duree_mois']           = $_POST['duree_mois'] ?? $demande['duree_mois'];
    $demande['taux_interet_propose'] = $_POST['taux_interet_propose'] ?? $demande['taux_interet_propose'];
    $demande['objet_credit']         = trim($_POST['objet_credit'] ?? '');

    $typesValides = ['consommation', 'immobilier', 'investissement', 'tresorerie'];

    if (!in_array($demande['type_credit'], $typesValides, true)) {
        $erreurs[] = 'Type de crédit invalide.';
    }
    if (!is_numeric($demande['montant_demande']) || (float) $demande['montant_demande'] <= 0) {
        $erreurs[] = 'Le montant demandé doit être un nombre positif.';
    }
    if (!ctype_digit((string) $demande['duree_mois']) || (int) $demande['duree_mois'] <= 0) {
        $erreurs[] = 'La durée doit être un nombre entier de mois positif.';
    }
    if (!is_numeric($demande['taux_interet_propose']) || (float) $demande['taux_interet_propose'] < 0) {
        $erreurs[] = 'Le taux d\'intérêt proposé est invalide.';
    }

    if (empty($erreurs)) {
        $maj = $pdo->prepare(
            'UPDATE demandes_credit SET
                type_credit = :type_credit,
                montant_demande = :montant_demande,
                duree_mois = :duree_mois,
                taux_interet_propose = :taux_interet_propose,
                objet_credit = :objet_credit
             WHERE id_demande = :id'
        );
        $maj->execute([
            'type_credit'           => $demande['type_credit'],
            'montant_demande'       => $demande['montant_demande'],
            'duree_mois'            => $demande['duree_mois'],
            'taux_interet_propose'  => $demande['taux_interet_propose'],
            'objet_credit'          => $demande['objet_credit'] ?: null,
            'id'                    => $idDemande,
        ]);

        enregistrerAudit('MODIFICATION_DEMANDE', 'demandes_credit', $idDemande, 'Modification de la demande ' . $demande['reference']);

        rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Demande modifiée avec succès.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Modifier la demande ' . $demande['reference'];
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-4">Modifier la demande — <?= e($demande['reference']) ?></h1>
<p class="text-muted small">Client : <?= e($demande['nom_raison_sociale']) ?> <?= e($demande['prenom'] ?? '') ?></p>

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
            <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Type de crédit</label>
                    <select name="type_credit" class="form-select" required>
                        <?php foreach (['consommation', 'immobilier', 'investissement', 'tresorerie'] as $type): ?>
                            <option value="<?= $type ?>" <?= $demande['type_credit'] === $type ? 'selected' : '' ?>>
                                <?= e(ucfirst($type)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Montant demandé (FCFA)</label>
                    <input type="number" step="1" min="1" name="montant_demande" class="form-control" data-type="montant" required
                           value="<?= e((string) $demande['montant_demande']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Durée (mois)</label>
                    <input type="number" step="1" min="1" name="duree_mois" class="form-control" required
                           value="<?= e((string) $demande['duree_mois']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Taux d'intérêt proposé (%)</label>
                    <input type="number" step="0.01" min="0" name="taux_interet_propose" class="form-control" required
                           value="<?= e((string) $demande['taux_interet_propose']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Objet du crédit</label>
                    <input type="text" name="objet_credit" class="form-control"
                           value="<?= e($demande['objet_credit']) ?>">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Enregistrer les modifications</button>
                <a href="voir.php?id=<?= (int) $idDemande ?>" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
