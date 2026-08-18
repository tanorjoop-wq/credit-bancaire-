<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AnalyseFinanciere.php';
require_once __DIR__ . '/../../includes/ScoringEngine.php';
exigerConnexion();

global $pdo;

$idDemande = (int) ($_GET['id'] ?? $_POST['id_demande'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT d.*, c.* FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     WHERE d.id_demande = :id'
);
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}
$idClient = (int) $demande['id_client'];
$estEntreprise = $demande['type_client'] === 'entreprise';

$stmtDonnees = $pdo->prepare('SELECT * FROM donnees_financieres WHERE id_client = :id ORDER BY date_exercice DESC, id_donnee DESC LIMIT 1');
$stmtDonnees->execute(['id' => $idClient]);
$donneesFinancieres = $stmtDonnees->fetch();

$jetonCSRF = genererJetonCSRF();
$peutGerer = in_array($_SESSION['role'], ['administrateur', 'charge_clientele', 'comite_direction'], true);

$resultat = null;
$chocTaux = null;
$chocRevenu = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'enregistrer') {
    verifierJetonCSRF();

    $insert = $pdo->prepare(
        'INSERT INTO simulations_stress (id_demande, choc_taux, choc_revenu, echeance_avant, echeance_apres,
            dscr_avant, dscr_apres, viable_apres_choc, teste_par)
         VALUES (:id_demande, :choc_taux, :choc_revenu, :echeance_avant, :echeance_apres,
            :dscr_avant, :dscr_apres, :viable, :teste_par)'
    );
    $insert->execute([
        'id_demande'    => $idDemande,
        'choc_taux'     => $_POST['choc_taux_valeur'],
        'choc_revenu'   => $_POST['choc_revenu_valeur'],
        'echeance_avant'=> $_POST['echeance_avant_valeur'],
        'echeance_apres'=> $_POST['echeance_apres_valeur'],
        'dscr_avant'    => $_POST['dscr_avant_valeur'] !== '' ? $_POST['dscr_avant_valeur'] : null,
        'dscr_apres'    => $_POST['dscr_apres_valeur'] !== '' ? $_POST['dscr_apres_valeur'] : null,
        'viable'        => $_POST['viable_valeur'],
        'teste_par'     => $_SESSION['id_utilisateur'],
    ]);

    enregistrerAudit('STRESS_TEST', 'simulations_stress', (int) $pdo->lastInsertId(), 'Stress-test enregistré pour la demande #' . $idDemande);

    $chocTaux = (float) $_POST['choc_taux_valeur'];
    $chocRevenu = (float) $_POST['choc_revenu_valeur'];
    $succesEnregistrement = true;
}

if (isset($_GET['choc_taux']) && isset($_GET['choc_revenu']) && $donneesFinancieres) {
    $chocTaux = (float) $_GET['choc_taux'];
    $chocRevenu = (float) $_GET['choc_revenu'];

    $moteur = new MoteurScoring();
    $analyseur = new AnalyseFinanciere($pdo);

    $echeanceAvant = $moteur->calculerEcheanceMensuelle(
        (float) $demande['montant_demande'], (int) $demande['duree_mois'], (float) $demande['taux_interet_propose']
    );
    $echeanceApres = $moteur->calculerEcheanceMensuelle(
        (float) $demande['montant_demande'], (int) $demande['duree_mois'], (float) $demande['taux_interet_propose'] + $chocTaux
    );

    if ($estEntreprise) {
        $facteurChoc = 1 - ($chocRevenu / 100);
        $donneesChoquees = $donneesFinancieres;
        $donneesChoquees['chiffre_affaires'] = (float) $donneesFinancieres['chiffre_affaires'] * $facteurChoc;
        // Les charges (achats, personnel) sont supposées fixes à court terme — hypothèse
        // de stress-test classique : le choc porte sur le chiffre d'affaires, pas les charges.

        $avant = $analyseur->calculerEntreprise($donneesFinancieres, $echeanceAvant);
        $apres = $analyseur->calculerEntreprise($donneesChoquees, $echeanceApres);

        $resultat = [
            'echeance_avant' => $echeanceAvant, 'echeance_apres' => $echeanceApres,
            'dscr_avant' => $avant['dscr'], 'dscr_apres' => $apres['dscr'],
            'caf_avant' => $avant['caf'], 'caf_apres' => $apres['caf'],
            'viable' => $apres['dscr'] !== null && $apres['dscr'] >= 1.0,
        ];
    } else {
        $clientChoque = $demande;
        $clientChoque['revenu_mensuel'] = (float) $demande['revenu_mensuel'] * (1 - $chocRevenu / 100);

        $avant = $analyseur->calculerParticulier($demande, $donneesFinancieres, $echeanceAvant);
        $apres = $analyseur->calculerParticulier($clientChoque, $donneesFinancieres, $echeanceApres);

        $resultat = [
            'echeance_avant' => $echeanceAvant, 'echeance_apres' => $echeanceApres,
            'dscr_avant' => $avant['dscr'], 'dscr_apres' => $apres['dscr'],
            'reste_avant' => $avant['reste_a_vivre'], 'reste_apres' => $apres['reste_a_vivre'],
            'viable' => $apres['reste_a_vivre'] >= 0 && ($apres['dscr'] === null || $apres['dscr'] >= 1.0),
        ];
    }
}

$titrePage = 'Stress-test — ' . $demande['reference'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-tsunami me-2 text-navy"></i>Stress-test — <?= e($demande['reference']) ?></h1>
    <a href="voir.php?id=<?= (int) $idDemande ?>" class="btn btn-outline-secondary btn-sm">&larr; Retour à la demande</a>
</div>

<?php if (!empty($succesEnregistrement)): ?>
    <div class="alert alert-success small">Stress-test enregistré dans le dossier.</div>
<?php endif; ?>

<?php if (!$donneesFinancieres): ?>
    <div class="alert alert-warning small">
        Saisissez d'abord les <a href="<?= BASE_URL ?>/modules/analyse/saisie.php?id_client=<?= $idClient ?>">données financières du client</a> pour pouvoir lancer un stress-test.
    </div>
<?php else: ?>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <form method="get" action="stress_test.php" class="row g-3 align-items-end">
            <input type="hidden" name="id" value="<?= (int) $idDemande ?>">
            <div class="col-md-4">
                <label class="form-label">Choc sur le taux d'intérêt (points de %)</label>
                <input type="number" step="0.1" name="choc_taux" class="form-control" value="<?= e((string) ($chocTaux ?? 2)) ?>" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Choc sur le revenu / chiffre d'affaires (% de baisse)</label>
                <input type="number" step="1" min="0" max="100" name="choc_revenu" class="form-control" value="<?= e((string) ($chocRevenu ?? 20)) ?>" required>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-navy w-100">Simuler le choc</button>
            </div>
        </form>
    </div>
</div>

<?php if ($resultat): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-semibold">Avant choc</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-7">Échéance mensuelle</dt><dd class="col-sm-5 text-end"><?= formaterMontant($resultat['echeance_avant']) ?></dd>
                        <dt class="col-sm-7">DSCR</dt><dd class="col-sm-5 text-end"><?= $resultat['dscr_avant'] !== null ? number_format($resultat['dscr_avant'], 2) : '—' ?></dd>
                        <?php if (isset($resultat['reste_avant'])): ?>
                            <dt class="col-sm-7">Reste à vivre</dt><dd class="col-sm-5 text-end"><?= formaterMontant($resultat['reste_avant']) ?></dd>
                        <?php else: ?>
                            <dt class="col-sm-7">CAF</dt><dd class="col-sm-5 text-end"><?= formaterMontant($resultat['caf_avant']) ?></dd>
                        <?php endif; ?>
                    </dl>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow-sm border-0 h-100 <?= $resultat['viable'] ? 'border-success' : 'border-danger' ?>" style="border-width:2px;">
                <div class="card-header bg-white fw-semibold">Après choc (+<?= e((string) $chocTaux) ?> pts / -<?= e((string) $chocRevenu) ?> %)</div>
                <div class="card-body">
                    <dl class="row mb-0 small">
                        <dt class="col-sm-7">Échéance mensuelle</dt><dd class="col-sm-5 text-end"><?= formaterMontant($resultat['echeance_apres']) ?></dd>
                        <dt class="col-sm-7">DSCR</dt><dd class="col-sm-5 text-end"><?= $resultat['dscr_apres'] !== null ? number_format($resultat['dscr_apres'], 2) : '—' ?></dd>
                        <?php if (isset($resultat['reste_apres'])): ?>
                            <dt class="col-sm-7">Reste à vivre</dt><dd class="col-sm-5 text-end"><?= formaterMontant($resultat['reste_apres']) ?></dd>
                        <?php else: ?>
                            <dt class="col-sm-7">CAF</dt><dd class="col-sm-5 text-end"><?= formaterMontant($resultat['caf_apres']) ?></dd>
                        <?php endif; ?>
                    </dl>
                    <div class="mt-2 text-center">
                        <span class="badge <?= $resultat['viable'] ? 'bg-success' : 'bg-danger' ?> fs-6">
                            <?= $resultat['viable'] ? 'Dossier résiste au choc' : 'Dossier fragilisé par le choc' ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($peutGerer): ?>
        <form method="post" action="stress_test.php">
            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
            <input type="hidden" name="action" value="enregistrer">
            <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
            <input type="hidden" name="choc_taux_valeur" value="<?= e((string) $chocTaux) ?>">
            <input type="hidden" name="choc_revenu_valeur" value="<?= e((string) $chocRevenu) ?>">
            <input type="hidden" name="echeance_avant_valeur" value="<?= e((string) $resultat['echeance_avant']) ?>">
            <input type="hidden" name="echeance_apres_valeur" value="<?= e((string) $resultat['echeance_apres']) ?>">
            <input type="hidden" name="dscr_avant_valeur" value="<?= e((string) $resultat['dscr_avant']) ?>">
            <input type="hidden" name="dscr_apres_valeur" value="<?= e((string) $resultat['dscr_apres']) ?>">
            <input type="hidden" name="viable_valeur" value="<?= $resultat['viable'] ? 1 : 0 ?>">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Enregistrer ce test dans le dossier</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php
$stmtHistorique = $pdo->prepare('SELECT s.*, u.nom, u.prenom FROM simulations_stress s JOIN utilisateurs u ON u.id_utilisateur = s.teste_par WHERE s.id_demande = :id ORDER BY s.id_simulation DESC');
$stmtHistorique->execute(['id' => $idDemande]);
$historique = $stmtHistorique->fetchAll();
?>
<?php if (!empty($historique)): ?>
    <div class="card shadow-sm border-0 mt-3">
        <div class="card-header bg-white fw-semibold">Historique des stress-tests enregistrés</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Choc taux</th><th>Choc revenu</th><th>DSCR après</th><th>Résultat</th><th>Par</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($historique as $h): ?>
                    <tr>
                        <td>+<?= e($h['choc_taux']) ?> pts</td>
                        <td>-<?= e($h['choc_revenu']) ?> %</td>
                        <td><?= $h['dscr_apres'] !== null ? e($h['dscr_apres']) : '—' ?></td>
                        <td><span class="badge <?= $h['viable_apres_choc'] ? 'bg-success' : 'bg-danger' ?>"><?= $h['viable_apres_choc'] ? 'Résiste' : 'Fragilisé' ?></span></td>
                        <td><?= e($h['prenom'] . ' ' . $h['nom']) ?></td>
                        <td class="small text-muted"><?= e(date('d/m/Y H:i', strtotime($h['date_test']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
