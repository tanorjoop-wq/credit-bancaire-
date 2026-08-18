<?php
/**
 * Risk Management Center (Module 10) — situation calculée en direct
 * (pas de photo historique stockée), datée explicitement dans l'UI.
 * LGD/EAD utilisent la même logique que modules/rentabilite/calculer.php
 * (cohérence inter-modules sur le référentiel unique).
 */
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

detecterEcheancesImpayees($pdo);

const LGD_PLANCHER = 15.0;

// --- Encours total (référence pour tous les ratios PAR) ---
$encoursTotal = (float) $pdo->query(
    "SELECT COALESCE(SUM(c.montant_accorde - (SELECT COALESCE(SUM(e.capital),0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee')), 0)
     FROM contrats c WHERE c.statut IN ('decaisse', 'en_defaut')"
)->fetchColumn();

function encoursParTranche(PDO $pdo, int $seuilJours): float
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(c.montant_accorde - (SELECT COALESCE(SUM(e2.capital),0) FROM echeancier e2 WHERE e2.id_contrat = c.id_contrat AND e2.statut = 'payee')), 0)
         FROM contrats c
         WHERE c.statut IN ('decaisse', 'en_defaut')
           AND EXISTS (
               SELECT 1 FROM echeancier e WHERE e.id_contrat = c.id_contrat
               AND e.statut IN ('en_retard', 'impayee') AND DATEDIFF(CURDATE(), e.date_echeance) >= :seuil
           )"
    );
    $stmt->execute(['seuil' => $seuilJours]);
    return (float) $stmt->fetchColumn();
}

$encoursPar30 = encoursParTranche($pdo, 30);
$encoursPar60 = encoursParTranche($pdo, 60);
$encoursPar90 = encoursParTranche($pdo, 90);

$par30 = $encoursTotal > 0 ? round($encoursPar30 / $encoursTotal * 100, 2) : 0.0;
$par60 = $encoursTotal > 0 ? round($encoursPar60 / $encoursTotal * 100, 2) : 0.0;
$par90 = $encoursTotal > 0 ? round($encoursPar90 / $encoursTotal * 100, 2) : 0.0;

$nbContratsActifs = (int) $pdo->query("SELECT COUNT(*) FROM contrats WHERE statut IN ('decaisse','en_defaut')")->fetchColumn();
$nbContratsImpayes = (int) $pdo->query("SELECT COUNT(DISTINCT id_contrat) FROM echeancier WHERE statut = 'impayee'")->fetchColumn();
$npl = $nbContratsActifs > 0 ? round($nbContratsImpayes / $nbContratsActifs * 100, 2) : 0.0;

// --- Expected Loss portefeuille : Σ PD × LGD × EAD sur les contrats actifs scorés ---
$stmtContrats = $pdo->query(
    "SELECT c.id_contrat, c.montant_accorde - (SELECT COALESCE(SUM(e.capital),0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee') AS ead,
            c.id_demande, s.probabilite_defaut,
            (SELECT COALESCE(SUM(g.valeur_estimee),0) FROM garanties g WHERE g.id_demande = c.id_demande AND g.statut != 'rejetee') AS garanties
     FROM contrats c
     LEFT JOIN scoring s ON s.id_demande = c.id_demande
     WHERE c.statut IN ('decaisse', 'en_defaut')"
);
$expectedLoss = 0.0;
$contratsScores = 0;
foreach ($stmtContrats->fetchAll() as $c) {
    $pd = $c['probabilite_defaut'] !== null ? (float) $c['probabilite_defaut'] : 15.0;
    $ead = (float) $c['ead'];
    $tauxCouverture = $ead > 0 ? min(1, (float) $c['garanties'] / $ead) : 0;
    $lgd = max(LGD_PLANCHER, (1 - $tauxCouverture) * 100);
    $expectedLoss += ($pd / 100) * ($lgd / 100) * $ead;
    if ($c['probabilite_defaut'] !== null) {
        $contratsScores++;
    }
}
$provisionsRecommandees = $expectedLoss; // simplification pédagogique : provisions = Expected Loss

// --- Matrice de migration (première évaluation vs dernière, par demande) ---
$stmtMigration = $pdo->query(
    "SELECT id_demande, note_globale, date_calcul,
            ROW_NUMBER() OVER (PARTITION BY id_demande ORDER BY date_calcul ASC) AS rang_asc,
            ROW_NUMBER() OVER (PARTITION BY id_demande ORDER BY date_calcul DESC) AS rang_desc
     FROM scoring_avance"
);
$evaluations = $stmtMigration->fetchAll();
$premiereParDemande = [];
$derniereParDemande = [];
foreach ($evaluations as $e) {
    if ((int) $e['rang_asc'] === 1) $premiereParDemande[$e['id_demande']] = $e['note_globale'];
    if ((int) $e['rang_desc'] === 1) $derniereParDemande[$e['id_demande']] = $e['note_globale'];
}
$migrations = [];
foreach ($premiereParDemande as $idDemande => $noteInitiale) {
    $noteFinale = $derniereParDemande[$idDemande] ?? $noteInitiale;
    if ($noteFinale !== $noteInitiale) {
        $migrations[] = ['id_demande' => $idDemande, 'de' => $noteInitiale, 'vers' => $noteFinale];
    }
}
$ordreNotes = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D', 'E', 'F'];
$ameliorations = 0;
$degradations = 0;
foreach ($migrations as $m) {
    if (array_search($m['vers'], $ordreNotes) < array_search($m['de'], $ordreNotes)) {
        $ameliorations++;
    } else {
        $degradations++;
    }
}

$titrePage = 'Risque';
require __DIR__ . '/../../includes/header.php';
?>

<h1 class="h4 mb-1"><i class="bi bi-shield-exclamation me-2 text-navy"></i>Risk Management Center</h1>
<p class="text-muted small mb-4">Situation calculée en direct au <?= e(date('d/m/Y à H:i')) ?> — pas de photo historique stockée (cf. note méthodologique du projet).</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center"><div class="card-body">
            <div class="small text-muted">PAR 30</div>
            <div class="h3 fw-bold mb-0 <?= $par30 >= 15 ? 'text-danger' : ($par30 > 0 ? 'text-warning' : 'text-success') ?>"><?= e((string) $par30) ?> %</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center"><div class="card-body">
            <div class="small text-muted">PAR 60</div>
            <div class="h3 fw-bold mb-0 <?= $par60 >= 10 ? 'text-danger' : ($par60 > 0 ? 'text-warning' : 'text-success') ?>"><?= e((string) $par60) ?> %</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center"><div class="card-body">
            <div class="small text-muted">PAR 90 / NPL</div>
            <div class="h3 fw-bold mb-0 <?= $par90 >= 5 ? 'text-danger' : ($par90 > 0 ? 'text-warning' : 'text-success') ?>"><?= e((string) $par90) ?> %</div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card shadow-sm border-0 text-center"><div class="card-body">
            <div class="small text-muted">NPL (contrats)</div>
            <div class="h3 fw-bold mb-0 <?= $npl >= 10 ? 'text-danger' : ($npl > 0 ? 'text-warning' : 'text-success') ?>"><?= e((string) $npl) ?> %</div>
        </div></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Expected Loss & Provisions</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-7">Encours total actif</dt><dd class="col-sm-5 text-end"><?= formaterMontant($encoursTotal) ?></dd>
                    <dt class="col-sm-7">Contrats scorés (PD connue)</dt><dd class="col-sm-5 text-end"><?= $contratsScores ?> / <?= $nbContratsActifs ?></dd>
                    <dt class="col-sm-7 fw-semibold">Expected Loss (Σ PD×LGD×EAD)</dt><dd class="col-sm-5 text-end fw-semibold text-danger"><?= formaterMontant($expectedLoss) ?></dd>
                    <dt class="col-sm-7">Provisions recommandées</dt><dd class="col-sm-5 text-end"><?= formaterMontant($provisionsRecommandees) ?></dd>
                    <dt class="col-sm-7">Taux de perte attendue</dt><dd class="col-sm-5 text-end"><?= $encoursTotal > 0 ? number_format($expectedLoss / $encoursTotal * 100, 2) : 0 ?> %</dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Matrice de migration de risque</div>
            <div class="card-body">
                <?php if (empty($evaluations)): ?>
                    <p class="text-muted small mb-0">Aucun historique de scoring avancé disponible pour le moment.</p>
                <?php else: ?>
                    <dl class="row mb-2 small">
                        <dt class="col-sm-7">Dossiers avec ≥2 évaluations</dt><dd class="col-sm-5 text-end"><?= count($migrations) ?></dd>
                        <dt class="col-sm-7 text-success">Améliorations</dt><dd class="col-sm-5 text-end text-success"><?= $ameliorations ?></dd>
                        <dt class="col-sm-7 text-danger">Dégradations</dt><dd class="col-sm-5 text-end text-danger"><?= $degradations ?></dd>
                    </dl>
                    <?php if (!empty($migrations)): ?>
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Demande</th><th>De</th><th>Vers</th></tr></thead>
                            <tbody>
                            <?php foreach (array_slice($migrations, 0, 10) as $m): ?>
                                <tr><td>#<?= (int) $m['id_demande'] ?></td><td><?= e($m['de']) ?></td><td><?= e($m['vers']) ?></td></tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="text-muted small mb-0">Aucune migration de note détectée (scores stables entre évaluations).</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Stress-test portefeuille</div>
    <div class="card-body">
        <p class="text-muted small">
            Le stress-test individuel par dossier est disponible sur chaque demande (bouton "Stress-test").
            Un stress-test agrégé sur l'ensemble du portefeuille nécessite que les données financières
            (Module 3) soient saisies pour chaque client actif — actuellement disponibles pour un sous-ensemble
            du portefeuille. Utilisez <a href="<?= BASE_URL ?>/modules/analyse/liste.php">Analyse financière</a>
            pour compléter la couverture, puis testez chaque dossier individuellement.
        </p>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
