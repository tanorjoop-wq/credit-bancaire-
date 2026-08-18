<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

$idClient = (int) ($_GET['id_client'] ?? $_POST['id_client'] ?? 0);
$stmt = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    die('Client introuvable.');
}

$estEntreprise = $client['type_client'] === 'entreprise';
$erreurs = [];

$champsEntreprise = [
    'chiffre_affaires', 'achats_consommes', 'charges_personnel', 'dotations_amortissements',
    'charges_financieres', 'produits_financiers', 'resultat_exceptionnel', 'impots_societe',
    'stocks', 'creances_clients', 'dettes_fournisseurs', 'dettes_financieres_lt',
    'capitaux_propres', 'actif_immobilise', 'tresorerie',
];
$champsParticulier = ['charges_mensuelles_fixes', 'autres_revenus'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $dateExercice = $_POST['date_exercice'] ?? date('Y-m-d');
    $valeurs = [];
    $champsAValider = $estEntreprise ? $champsEntreprise : $champsParticulier;
    foreach ($champsAValider as $champ) {
        $brut = trim($_POST[$champ] ?? '');
        if ($brut !== '' && !is_numeric($brut)) {
            $erreurs[] = 'Le champ ' . $champ . ' doit être numérique.';
        }
        $valeurs[$champ] = $brut !== '' ? (float) $brut : null;
    }

    if (empty($erreurs)) {
        $colonnes = array_merge(['id_client', 'date_exercice'], $champsAValider, ['saisi_par']);
        $placeholders = array_map(fn($c) => ':' . $c, $colonnes);
        $sql = 'INSERT INTO donnees_financieres (' . implode(', ', $colonnes) . ')
                VALUES (' . implode(', ', $placeholders) . ')';
        $params = array_merge(
            ['id_client' => $idClient, 'date_exercice' => $dateExercice],
            $valeurs,
            ['saisi_par' => $_SESSION['id_utilisateur']]
        );
        $pdo->prepare($sql)->execute($params);

        enregistrerAudit('SAISIE_DONNEES_FINANCIERES', 'donnees_financieres', (int) $pdo->lastInsertId(), 'Saisie de données financières pour le client #' . $idClient);

        rediriger('voir.php?id_client=' . $idClient . '&succes=' . urlencode('Données financières enregistrées.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Saisie des données financières';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-1">Données financières — <?= e($client['nom_raison_sociale']) ?> <?= e($client['prenom'] ?? '') ?></h1>
<p class="text-muted small mb-4">Type de client : <?= e(ucfirst($client['type_client'])) ?></p>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0"><?php foreach ($erreurs as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="saisie.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <input type="hidden" name="id_client" value="<?= (int) $idClient ?>">

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Date de l'exercice</label>
                    <input type="date" name="date_exercice" class="form-control" value="<?= e(date('Y-m-d')) ?>" required>
                </div>
            </div>

            <?php if ($estEntreprise): ?>
                <h2 class="h6 text-navy mt-4">Compte de résultat (cascade SIG)</h2>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Chiffre d'affaires</label><input type="number" step="1" name="chiffre_affaires" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Achats consommés</label><input type="number" step="1" name="achats_consommes" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Charges de personnel</label><input type="number" step="1" name="charges_personnel" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Dotations aux amortissements</label><input type="number" step="1" name="dotations_amortissements" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Charges financières</label><input type="number" step="1" name="charges_financieres" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Produits financiers</label><input type="number" step="1" name="produits_financiers" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Résultat exceptionnel</label><input type="number" step="1" name="resultat_exceptionnel" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Impôts sur les sociétés</label><input type="number" step="1" name="impots_societe" class="form-control"></div>
                </div>

                <h2 class="h6 text-navy mt-4">Bilan (FDR / BFR)</h2>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Stocks</label><input type="number" step="1" name="stocks" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Créances clients</label><input type="number" step="1" name="creances_clients" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Dettes fournisseurs</label><input type="number" step="1" name="dettes_fournisseurs" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Dettes financières long terme</label><input type="number" step="1" name="dettes_financieres_lt" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Capitaux propres</label><input type="number" step="1" name="capitaux_propres" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Actif immobilisé</label><input type="number" step="1" name="actif_immobilise" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Trésorerie (bilan)</label><input type="number" step="1" name="tresorerie" class="form-control"></div>
                </div>
            <?php else: ?>
                <h2 class="h6 text-navy mt-4">Budget mensuel</h2>
                <p class="text-muted small">Le revenu mensuel principal est déjà renseigné sur la fiche client (<?= formaterMontant($client['revenu_mensuel']) ?>).</p>
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Autres revenus mensuels</label><input type="number" step="1" name="autres_revenus" class="form-control"></div>
                    <div class="col-md-4"><label class="form-label">Charges mensuelles fixes</label><input type="number" step="1" name="charges_mensuelles_fixes" class="form-control"></div>
                </div>
            <?php endif; ?>

            <div class="mt-4">
                <button type="submit" class="btn btn-navy">Enregistrer</button>
                <a href="liste.php" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
