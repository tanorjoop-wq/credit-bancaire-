<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/EcheancierGenerator.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

$idContrat = (int) ($_GET['id'] ?? $_POST['id_contrat'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, d.reference, cl.nom_raison_sociale, cl.prenom
     FROM contrats c
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     WHERE c.id_contrat = :id'
);
$stmt->execute(['id' => $idContrat]);
$contrat = $stmt->fetch();

if (!$contrat) {
    http_response_code(404);
    die('Contrat introuvable.');
}

if (!in_array($contrat['statut'], ['decaisse', 'en_defaut'], true)) {
    rediriger('voir.php?id=' . $idContrat . '&erreur=' . urlencode('Seul un contrat décaissé (ou en défaut) peut être restructuré.'));
}

// Capital restant dû = solde après la dernière échéance payée, ou montant total si aucun paiement
$stmtDernierPaye = $pdo->prepare(
    "SELECT capital_restant_du FROM echeancier WHERE id_contrat = :id AND statut = 'payee' ORDER BY numero_echeance DESC LIMIT 1"
);
$stmtDernierPaye->execute(['id' => $idContrat]);
$capitalRestant = $stmtDernierPaye->fetchColumn();
$capitalRestant = $capitalRestant !== false ? (float) $capitalRestant : (float) $contrat['montant_accorde'];

$stmtMaxNumero = $pdo->prepare('SELECT COALESCE(MAX(numero_echeance), 0) FROM echeancier WHERE id_contrat = :id');
$stmtMaxNumero->execute(['id' => $idContrat]);
$dernierNumero = (int) $stmtMaxNumero->fetchColumn();

$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifierJetonCSRF();

    $nouvelleDuree = (int) ($_POST['nouvelle_duree_mois'] ?? 0);
    $nouveauTaux = $_POST['nouveau_taux'] ?? '';
    $differeMois = (int) ($_POST['differe_mois'] ?? 0);
    $motif = trim($_POST['motif'] ?? '');

    if ($nouvelleDuree <= 0 || $nouvelleDuree > 360) {
        $erreurs[] = 'Durée invalide.';
    }
    if (!is_numeric($nouveauTaux) || (float) $nouveauTaux < 0) {
        $erreurs[] = 'Taux invalide.';
    }
    if ($differeMois < 0 || $differeMois >= $nouvelleDuree) {
        $erreurs[] = 'Le différé doit être inférieur à la nouvelle durée.';
    }
    if ($motif === '') {
        $erreurs[] = 'Le motif de la restructuration est obligatoire.';
    }

    if (empty($erreurs)) {
        $pdo->beginTransaction();

        // Annule toutes les échéances futures non payées
        $pdo->prepare(
            "UPDATE echeancier SET statut = 'annulee' WHERE id_contrat = :id AND statut != 'payee'"
        )->execute(['id' => $idContrat]);

        // Régénère l'échéancier de reprise
        $generateur = new GenerateurEcheancier();
        $tableau = $generateur->genererTableauReprise(
            $capitalRestant, $nouvelleDuree, (float) $nouveauTaux, date('Y-m-d'), $differeMois, $dernierNumero + 1
        );

        $insertEcheance = $pdo->prepare(
            'INSERT INTO echeancier (id_contrat, numero_echeance, date_echeance, capital, interet, montant_echeance, capital_restant_du, statut)
             VALUES (:id_contrat, :numero_echeance, :date_echeance, :capital, :interet, :montant_echeance, :capital_restant_du, :statut)'
        );
        foreach ($tableau as $echeance) {
            $insertEcheance->execute([
                'id_contrat'          => $idContrat,
                'numero_echeance'     => $echeance['numero_echeance'],
                'date_echeance'       => $echeance['date_echeance'],
                'capital'             => $echeance['capital'],
                'interet'             => $echeance['interet'],
                'montant_echeance'    => $echeance['montant_echeance'],
                'capital_restant_du'  => $echeance['capital_restant_du'],
                'statut'              => 'a_venir',
            ]);
        }

        $pdo->prepare('UPDATE contrats SET duree_mois = :duree, taux_final = :taux, statut = :statut WHERE id_contrat = :id')
            ->execute([
                'duree'  => $nouvelleDuree,
                'taux'   => $nouveauTaux,
                'statut' => 'decaisse',
                'id'     => $idContrat,
            ]);

        $pdo->prepare(
            'INSERT INTO restructurations (id_contrat, ancienne_duree_mois, nouvelle_duree_mois, ancien_taux, nouveau_taux,
                differe_mois, capital_restant_avant, motif, decide_par)
             VALUES (:id_contrat, :ancienne_duree, :nouvelle_duree, :ancien_taux, :nouveau_taux,
                :differe, :capital_avant, :motif, :decide_par)'
        )->execute([
            'id_contrat'      => $idContrat,
            'ancienne_duree'  => $contrat['duree_mois'],
            'nouvelle_duree'  => $nouvelleDuree,
            'ancien_taux'     => $contrat['taux_final'],
            'nouveau_taux'    => $nouveauTaux,
            'differe'         => $differeMois,
            'capital_avant'   => $capitalRestant,
            'motif'           => $motif,
            'decide_par'      => $_SESSION['id_utilisateur'],
        ]);

        $pdo->commit();

        enregistrerAudit('RESTRUCTURATION_CONTRAT', 'contrats', $idContrat, 'Restructuration du contrat ' . $contrat['numero_contrat'] . ' : ' . $motif);

        rediriger('voir.php?id=' . $idContrat . '&succes=' . urlencode('Contrat restructuré : nouvel échéancier généré.'));
    }
}

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Restructuration — ' . $contrat['numero_contrat'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Restructuration — <?= e($contrat['numero_contrat']) ?></h1>
    <a href="voir.php?id=<?= (int) $idContrat ?>" class="btn btn-outline-secondary btn-sm">&larr; Retour au contrat</a>
</div>
<p class="text-muted small">Client : <?= e($contrat['nom_raison_sociale']) ?> <?= e($contrat['prenom'] ?? '') ?> — Capital restant dû : <strong><?= formaterMontant($capitalRestant) ?></strong></p>

<?php if (!empty($erreurs)): ?>
    <div class="alert alert-danger small">
        <ul class="mb-0"><?php foreach ($erreurs as $erreur): ?><li><?= e($erreur) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="alert alert-warning small">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Cette opération annule toutes les échéances futures non payées et génère un nouvel échéancier sur le capital restant dû (<?= formaterMontant($capitalRestant) ?>).
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <form method="post" action="restructurer.php" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <input type="hidden" name="id_contrat" value="<?= (int) $idContrat ?>">

            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Nouvelle durée totale (mois)</label>
                    <input type="number" step="1" min="1" name="nouvelle_duree_mois" class="form-control" value="<?= (int) $contrat['duree_mois'] ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nouveau taux d'intérêt (%)</label>
                    <input type="number" step="0.01" min="0" name="nouveau_taux" class="form-control" value="<?= e($contrat['taux_final']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Différé d'amortissement (mois)</label>
                    <input type="number" step="1" min="0" name="differe_mois" class="form-control" value="0" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Motif de la restructuration</label>
                    <textarea name="motif" class="form-control" rows="2" required placeholder="Ex : difficultés temporaires de trésorerie du client"></textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-navy" onclick="return confirm('Confirmer la restructuration ? Cette action est irréversible.');">Restructurer le contrat</button>
                <a href="voir.php?id=<?= (int) $idContrat ?>" class="btn btn-outline-secondary">Annuler</a>
            </div>
        </form>
    </div>
</div>

<?php
$stmtHistorique = $pdo->prepare(
    'SELECT r.*, u.nom, u.prenom FROM restructurations r JOIN utilisateurs u ON u.id_utilisateur = r.decide_par WHERE r.id_contrat = :id ORDER BY r.id_restructuration DESC'
);
$stmtHistorique->execute(['id' => $idContrat]);
$historique = $stmtHistorique->fetchAll();
?>
<?php if (!empty($historique)): ?>
    <div class="card shadow-sm border-0 mt-3">
        <div class="card-header bg-white fw-semibold">Historique des restructurations</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Ancienne durée</th><th>Nouvelle durée</th><th>Ancien taux</th><th>Nouveau taux</th><th>Différé</th><th>Motif</th><th>Par</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($historique as $h): ?>
                    <tr>
                        <td><?= (int) $h['ancienne_duree_mois'] ?> mois</td>
                        <td><?= (int) $h['nouvelle_duree_mois'] ?> mois</td>
                        <td><?= e($h['ancien_taux']) ?> %</td>
                        <td><?= e($h['nouveau_taux']) ?> %</td>
                        <td><?= (int) $h['differe_mois'] ?> mois</td>
                        <td class="small"><?= e($h['motif']) ?></td>
                        <td class="small"><?= e($h['prenom'] . ' ' . $h['nom']) ?></td>
                        <td class="small text-muted"><?= e(date('d/m/Y', strtotime($h['date_restructuration']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
