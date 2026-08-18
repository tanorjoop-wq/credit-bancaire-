<?php
require_once __DIR__ . '/../includes/auth.php';
exigerConnexion();

global $pdo;

// --- KPI ---
$nbClients  = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
$nbDemandes = (int) $pdo->query('SELECT COUNT(*) FROM demandes_credit')->fetchColumn();

$nbEnCours = (int) $pdo->query(
    "SELECT COUNT(*) FROM demandes_credit
     WHERE statut IN ('en_attente','en_analyse','scoring_effectue','en_comite')"
)->fetchColumn();

$nbApprouvees = (int) $pdo->query(
    "SELECT COUNT(*) FROM demandes_credit WHERE statut IN ('approuve','decaisse','solde')"
)->fetchColumn();

$nbRefusees = (int) $pdo->query(
    "SELECT COUNT(*) FROM demandes_credit WHERE statut = 'refuse'"
)->fetchColumn();

$nbDecisions = $nbApprouvees + $nbRefusees;
$tauxApprobation = $nbDecisions > 0 ? round($nbApprouvees / $nbDecisions * 100, 1) : 0.0;

$montantAccorde = (float) $pdo->query(
    "SELECT COALESCE(SUM(montant_demande), 0) FROM demandes_credit WHERE statut IN ('approuve','decaisse','solde')"
)->fetchColumn();

$scoreMoyen = $pdo->query('SELECT AVG(score_total) FROM scoring')->fetchColumn();
$scoreMoyen = $scoreMoyen !== null ? round((float) $scoreMoyen, 1) : null;

// --- KPI de gestion des risques ---

// Encours total = capital restant dû réel (montant accordé − capital déjà remboursé)
// sur tous les contrats décaissés, y compris après restructuration.
$encoursTotal = (float) $pdo->query(
    "SELECT COALESCE(SUM(c.montant_accorde -
                (SELECT COALESCE(SUM(e.capital), 0) FROM echeancier e WHERE e.id_contrat = c.id_contrat AND e.statut = 'payee')
            ), 0)
     FROM contrats c WHERE c.statut IN ('decaisse', 'en_defaut')"
)->fetchColumn();

// Intérêts déjà perçus (portion intérêt des échéances payées)
$interetsGeneres = (float) $pdo->query(
    "SELECT COALESCE(SUM(e.interet), 0) FROM echeancier e WHERE e.statut = 'payee'"
)->fetchColumn();

// Taux de créances douteuses (NPL) : part des contrats actifs ayant au moins une échéance impayée
$nbContratsActifs = (int) $pdo->query(
    "SELECT COUNT(*) FROM contrats WHERE statut IN ('decaisse', 'en_defaut')"
)->fetchColumn();
$nbContratsEnImpaye = (int) $pdo->query(
    "SELECT COUNT(DISTINCT id_contrat) FROM echeancier WHERE statut = 'impayee'"
)->fetchColumn();
$tauxNpl = $nbContratsActifs > 0 ? round($nbContratsEnImpaye / $nbContratsActifs * 100, 1) : 0.0;

// --- Bloc Production crédit ---
$montantDemande30j = (float) $pdo->query("SELECT COALESCE(SUM(montant_demande),0) FROM demandes_credit WHERE date_demande >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$montantApprouve30j = (float) $pdo->query("SELECT COALESCE(SUM(montant_demande),0) FROM demandes_credit WHERE date_decision >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND statut IN ('approuve','decaisse','solde')")->fetchColumn();
$montantDecaisse30j = (float) $pdo->query("SELECT COALESCE(SUM(montant_accorde),0) FROM contrats WHERE date_decaissement >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
$tauxConversion = $nbDemandes > 0 ? round($nbApprouvees / $nbDemandes * 100, 1) : 0.0;

// --- Bloc Rentabilité (résumé portefeuille) ---
$rentabiliteResume = $pdo->query(
    "SELECT COUNT(*) AS nb, COALESCE(SUM(marge_nette_interet),0) AS mni_totale,
            COALESCE(SUM(cout_du_risque),0) AS cout_risque_total, COALESCE(AVG(raroc),0) AS raroc_moyen
     FROM rentabilite_demande"
)->fetch();

// --- Bloc Analyse du portefeuille : répartition par produit (type de crédit) ---
$parProduitDashboard = $pdo->query(
    "SELECT type_credit, COUNT(*) AS nb, COALESCE(SUM(montant_demande),0) AS montant
     FROM demandes_credit WHERE statut IN ('approuve','decaisse','solde') GROUP BY type_credit ORDER BY montant DESC"
)->fetchAll();

// --- Bloc Activité & Performance ---
$delaiMoyenTraitement = $pdo->query(
    "SELECT AVG(DATEDIFF(date_decision, date_demande)) FROM demandes_credit WHERE date_decision IS NOT NULL"
)->fetchColumn();
$delaiMoyenTraitement = $delaiMoyenTraitement !== null ? round((float) $delaiMoyenTraitement, 1) : null;

$activiteRecente = $pdo->query(
    "SELECT j.action, j.details, j.date_action, u.prenom, u.nom
     FROM journal_audit j JOIN utilisateurs u ON u.id_utilisateur = j.id_utilisateur
     ORDER BY j.id_audit DESC LIMIT 8"
)->fetchAll();

// --- Widget Early Warning : échéances en retard/impayées, triées par sévérité ---
$stmtAlertes = $pdo->query(
    "SELECT e.id_echeance, e.numero_echeance, e.date_echeance, e.montant_echeance, e.statut, e.id_contrat,
            c.numero_contrat, cl.nom_raison_sociale, cl.prenom,
            DATEDIFF(CURDATE(), e.date_echeance) AS jours_retard
     FROM echeancier e
     JOIN contrats c ON c.id_contrat = e.id_contrat
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     WHERE e.statut IN ('en_retard', 'impayee')
     ORDER BY (e.montant_echeance * DATEDIFF(CURDATE(), e.date_echeance)) DESC
     LIMIT 8"
);
$alertes = $stmtAlertes->fetchAll();

// --- Graphique : répartition par note de scoring avancé ---
$notesBrutes = $pdo->query('SELECT note_globale, COUNT(*) AS nb FROM vue_scoring_avance_actuel GROUP BY note_globale')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$ordreNotes = ['A+', 'A', 'B+', 'B', 'C+', 'C', 'D', 'E', 'F'];
$couleursNotes = [
    'A+' => '#146c43', 'A' => '#198754', 'B+' => '#6610f2', 'B' => '#7c4dff',
    'C+' => '#0d6efd', 'C' => '#0dcaf0', 'D' => '#fd7e14', 'E' => '#dc3545', 'F' => '#842029',
];
$noteLabels = [];
$noteData = [];
$noteColors = [];
foreach ($ordreNotes as $note) {
    $noteLabels[] = $note;
    $noteData[] = (int) ($notesBrutes[$note] ?? 0);
    $noteColors[] = $couleursNotes[$note];
}

// --- Graphique : remboursements vs impayés sur les 6 derniers mois ---
$remboursementsBruts = $pdo->query(
    "SELECT DATE_FORMAT(date_paiement, '%Y-%m') AS mois, SUM(montant_paye) AS total
     FROM remboursements GROUP BY mois ORDER BY mois DESC LIMIT 6"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$impayesBruts = $pdo->query(
    "SELECT DATE_FORMAT(date_echeance, '%Y-%m') AS mois, SUM(montant_echeance) AS total
     FROM echeancier WHERE statut IN ('en_retard', 'impayee') GROUP BY mois ORDER BY mois DESC LIMIT 6"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$moisCombines = array_unique(array_merge(array_keys($remboursementsBruts), array_keys($impayesBruts)));
sort($moisCombines);
$moisCombines = array_slice($moisCombines, -6);

$suiviLabels = [];
$suiviRembourse = [];
$suiviImpaye = [];
foreach ($moisCombines as $mois) {
    $suiviLabels[] = date('M Y', strtotime($mois . '-01'));
    $suiviRembourse[] = (float) ($remboursementsBruts[$mois] ?? 0);
    $suiviImpaye[] = (float) ($impayesBruts[$mois] ?? 0);
}

// --- Graphique 1 : répartition des demandes par statut ---
$libellesStatuts = [
    'en_attente'       => 'En attente',
    'en_analyse'       => 'En analyse',
    'scoring_effectue' => 'Scoring effectué',
    'en_comite'        => 'En comité',
    'approuve'         => 'Approuvée',
    'refuse'           => 'Refusée',
    'decaisse'         => 'Décaissée',
    'solde'            => 'Soldée',
];
$couleursStatuts = [
    'en_attente' => '#6c757d', 'en_analyse' => '#0d6efd', 'scoring_effectue' => '#6610f2',
    'en_comite' => '#fd7e14', 'approuve' => '#198754', 'refuse' => '#dc3545',
    'decaisse' => '#20c997', 'solde' => '#212529',
];

$statutsBruts = $pdo->query('SELECT statut, COUNT(*) AS nb FROM demandes_credit GROUP BY statut')
    ->fetchAll(PDO::FETCH_KEY_PAIR);

$statutLabels = [];
$statutData   = [];
$statutColors = [];
foreach ($libellesStatuts as $cle => $libelle) {
    if (!empty($statutsBruts[$cle])) {
        $statutLabels[] = $libelle;
        $statutData[]   = (int) $statutsBruts[$cle];
        $statutColors[] = $couleursStatuts[$cle];
    }
}

// --- Graphique 2 : évolution des demandes sur les 6 derniers mois ---
$evolutionBrute = $pdo->query(
    "SELECT DATE_FORMAT(date_demande, '%Y-%m') AS mois, COUNT(*) AS nb
     FROM demandes_credit
     GROUP BY mois
     ORDER BY mois DESC
     LIMIT 6"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$evolutionBrute = array_reverse($evolutionBrute, true);

$moisLabels = [];
$moisData   = [];
foreach ($evolutionBrute as $mois => $nb) {
    $moisLabels[] = date('M Y', strtotime($mois . '-01'));
    $moisData[]   = (int) $nb;
}

// --- Graphique 3 : répartition des grades de scoring ---
$gradesBruts = $pdo->query('SELECT grade, COUNT(*) AS nb FROM scoring GROUP BY grade ORDER BY grade')
    ->fetchAll(PDO::FETCH_KEY_PAIR);
$couleursGrades = ['A' => '#198754', 'B' => '#6610f2', 'C' => '#0d6efd', 'D' => '#fd7e14', 'E' => '#dc3545'];
$gradeLabels = [];
$gradeData   = [];
$gradeColors = [];
foreach (['A', 'B', 'C', 'D', 'E'] as $grade) {
    $gradeLabels[] = $grade;
    $gradeData[]   = (int) ($gradesBruts[$grade] ?? 0);
    $gradeColors[] = $couleursGrades[$grade];
}

$jsonOptions = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;

$titrePage = 'Tableau de bord';
require __DIR__ . '/../includes/header.php';
?>

<h1 class="h4 mb-4"><i class="bi bi-speedometer2 me-2 text-navy"></i>Tableau de bord</h1>

<div class="row g-3 mb-2">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-navy bg-opacity-10 text-navy"><i class="bi bi-people-fill"></i></span>
                <div>
                    <div class="text-muted small">Clients enregistrés</div>
                    <div class="h2 fw-bold text-navy mb-0"><?= $nbClients ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-navy bg-opacity-10 text-navy"><i class="bi bi-file-earmark-text-fill"></i></span>
                <div>
                    <div class="text-muted small">Demandes de crédit</div>
                    <div class="h2 fw-bold text-navy mb-0"><?= $nbDemandes ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-warning bg-opacity-25 text-warning"><i class="bi bi-hourglass-split"></i></span>
                <div>
                    <div class="text-muted small">En cours de traitement</div>
                    <div class="h2 fw-bold text-warning mb-0"><?= $nbEnCours ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-success bg-opacity-25 text-success"><i class="bi bi-check-circle-fill"></i></span>
                <div>
                    <div class="text-muted small">Demandes approuvées</div>
                    <div class="h2 fw-bold text-success mb-0"><?= $nbApprouvees ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Taux d'approbation</div>
                <div class="h3 fw-bold text-navy mb-0"><?= e((string) $tauxApprobation) ?> %</div>
                <div class="text-muted small mt-1"><?= $nbApprouvees ?> approuvées / <?= $nbRefusees ?> refusées</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Montant total accordé</div>
                <div class="h3 fw-bold text-navy mb-0"><?= formaterMontant($montantAccorde) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Score de scoring moyen</div>
                <div class="h3 fw-bold text-navy mb-0"><?= $scoreMoyen !== null ? e((string) $scoreMoyen) . ' / 100' : '—' ?></div>
            </div>
        </div>
    </div>
</div>

<h2 class="h6 text-navy mt-2 mb-3"><i class="bi bi-shield-exclamation me-1"></i>Risk management</h2>
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Encours total du portefeuille</div>
                <div class="h3 fw-bold text-navy mb-0"><?= formaterMontant($encoursTotal) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Intérêts perçus à date</div>
                <div class="h3 fw-bold text-success mb-0"><?= formaterMontant($interetsGeneres) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small">Taux de créances douteuses (NPL)</div>
                <div class="h3 fw-bold mb-0 <?= $tauxNpl >= 15 ? 'text-danger' : ($tauxNpl > 0 ? 'text-warning' : 'text-success') ?>"><?= e((string) $tauxNpl) ?> %</div>
                <div class="text-muted small mt-1"><?= $nbContratsEnImpaye ?> / <?= $nbContratsActifs ?> contrats actifs</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up-arrow me-1"></i>Production crédit (30 derniers jours)</div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="small text-muted">Demandé</div>
                        <div class="h6 fw-bold text-navy"><?= formaterMontant($montantDemande30j) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Approuvé</div>
                        <div class="h6 fw-bold text-success"><?= formaterMontant($montantApprouve30j) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Décaissé</div>
                        <div class="h6 fw-bold text-info"><?= formaterMontant($montantDecaisse30j) ?></div>
                    </div>
                </div>
                <hr class="my-2">
                <div class="text-center">
                    <span class="small text-muted">Taux de conversion (approuvées / total) : </span>
                    <span class="fw-bold text-navy"><?= e((string) $tauxConversion) ?> %</span>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-cash-coin me-1"></i>Rentabilité portefeuille</div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="small text-muted">MNI cumulée</div>
                        <div class="h6 fw-bold text-navy"><?= formaterMontant($rentabiliteResume['mni_totale']) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Coût du risque</div>
                        <div class="h6 fw-bold text-danger"><?= formaterMontant($rentabiliteResume['cout_risque_total']) ?></div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">RAROC moyen</div>
                        <div class="h6 fw-bold <?= $rentabiliteResume['raroc_moyen'] >= 15 ? 'text-success' : 'text-danger' ?>"><?= number_format((float) $rentabiliteResume['raroc_moyen'], 1) ?> %</div>
                    </div>
                </div>
                <div class="text-center mt-2"><a href="<?= BASE_URL ?>/modules/rentabilite/liste.php" class="small">Voir le détail par produit/agence &rarr;</a></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-diagram-3 me-1"></i>Analyse du portefeuille par produit</span>
                <a href="<?= BASE_URL ?>/modules/demandes/liste.php" class="small">Pipeline détaillé &rarr;</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Produit</th><th class="text-end">Nb</th><th class="text-end">Montant</th></tr></thead>
                    <tbody>
                        <?php foreach ($parProduitDashboard as $p): ?>
                            <tr><td><?= e(ucfirst($p['type_credit'])) ?></td><td class="text-end"><?= (int) $p['nb'] ?></td><td class="text-end"><?= formaterMontant($p['montant']) ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($parProduitDashboard)): ?><tr><td colspan="3" class="text-center text-muted py-2">Aucune donnée.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between">
                <span><i class="bi bi-activity me-1"></i>Activité récente</span>
                <span class="small text-muted">Délai moyen de traitement : <?= $delaiMoyenTraitement !== null ? $delaiMoyenTraitement . ' j' : '—' ?></span>
            </div>
            <div class="card-body py-2" style="max-height: 220px; overflow-y: auto;">
                <ul class="list-unstyled mb-0 small">
                    <?php foreach ($activiteRecente as $a): ?>
                        <li class="mb-1 pb-1 border-bottom">
                            <span class="fw-semibold"><?= e($a['prenom'] . ' ' . $a['nom']) ?></span> —
                            <span class="text-muted"><?= e($a['action']) ?></span>
                            <span class="text-muted float-end"><?= e(date('d/m H:i', strtotime($a['date_action']))) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($alertes)): ?>
<div class="card shadow-sm border-0 mb-4 border-danger" style="border-width:2px;">
    <div class="card-header bg-white fw-semibold text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Early Warning System — dossiers à risque de défaillance</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Contrat</th><th>Client</th><th>Échéance</th><th class="text-end">Montant</th><th>Retard</th><th>Statut</th><th></th></tr></thead>
            <tbody>
                <?php foreach ($alertes as $alerte): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($alerte['numero_contrat']) ?></td>
                        <td><?= e($alerte['nom_raison_sociale']) ?> <?= e($alerte['prenom'] ?? '') ?></td>
                        <td>#<?= (int) $alerte['numero_echeance'] ?> — <?= e(date('d/m/Y', strtotime($alerte['date_echeance']))) ?></td>
                        <td class="text-end"><?= formaterMontant($alerte['montant_echeance']) ?></td>
                        <td><?= (int) $alerte['jours_retard'] ?> j</td>
                        <td><span class="badge <?= $alerte['statut'] === 'impayee' ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $alerte['statut'] === 'impayee' ? 'Impayée' : 'En retard' ?></span></td>
                        <td class="text-end"><a href="<?= BASE_URL ?>/modules/contrats/voir.php?id=<?= (int) $alerte['id_contrat'] ?>" class="btn btn-sm btn-outline-danger">Voir</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Répartition des demandes par statut</div>
            <div class="card-body">
                <?php if (empty($statutData)): ?>
                    <p class="text-muted small mb-0">Aucune donnée disponible.</p>
                <?php else: ?>
                    <canvas id="graphStatuts" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Évolution des demandes (6 derniers mois)</div>
            <div class="card-body">
                <?php if (empty($moisData)): ?>
                    <p class="text-muted small mb-0">Aucune donnée disponible.</p>
                <?php else: ?>
                    <canvas id="graphEvolution" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Répartition des grades de scoring (de base)</div>
            <div class="card-body">
                <?php if (array_sum($gradeData) === 0): ?>
                    <p class="text-muted small mb-0">Aucun scoring calculé pour le moment.</p>
                <?php else: ?>
                    <canvas id="graphGrades" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Répartition par note (scoring avancé)</div>
            <div class="card-body">
                <?php if (array_sum($noteData) === 0): ?>
                    <p class="text-muted small mb-0">Aucun scoring avancé calculé pour le moment.</p>
                <?php else: ?>
                    <canvas id="graphNotes" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-12">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-white fw-semibold">Remboursements vs impayés (6 derniers mois)</div>
            <div class="card-body">
                <?php if (array_sum($suiviRembourse) + array_sum($suiviImpaye) === 0.0): ?>
                    <p class="text-muted small mb-0">Aucune donnée disponible.</p>
                <?php else: ?>
                    <canvas id="graphSuivi" height="180"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const canvasStatuts = document.getElementById('graphStatuts');
    if (canvasStatuts) {
        new Chart(canvasStatuts, {
            type: 'doughnut',
            data: {
                labels: <?= json_encode($statutLabels, $jsonOptions) ?>,
                datasets: [{
                    data: <?= json_encode($statutData, $jsonOptions) ?>,
                    backgroundColor: <?= json_encode($statutColors, $jsonOptions) ?>,
                }],
            },
            options: {
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
            },
        });
    }

    const canvasEvolution = document.getElementById('graphEvolution');
    if (canvasEvolution) {
        new Chart(canvasEvolution, {
            type: 'line',
            data: {
                labels: <?= json_encode($moisLabels, $jsonOptions) ?>,
                datasets: [{
                    label: 'Demandes déposées',
                    data: <?= json_encode($moisData, $jsonOptions) ?>,
                    borderColor: '#1a2b4c',
                    backgroundColor: 'rgba(26, 43, 76, 0.12)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: 4,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    const canvasGrades = document.getElementById('graphGrades');
    if (canvasGrades) {
        new Chart(canvasGrades, {
            type: 'bar',
            data: {
                labels: <?= json_encode($gradeLabels, $jsonOptions) ?>,
                datasets: [{
                    label: 'Nombre de demandes',
                    data: <?= json_encode($gradeData, $jsonOptions) ?>,
                    backgroundColor: <?= json_encode($gradeColors, $jsonOptions) ?>,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    const canvasNotes = document.getElementById('graphNotes');
    if (canvasNotes) {
        new Chart(canvasNotes, {
            type: 'bar',
            data: {
                labels: <?= json_encode($noteLabels, $jsonOptions) ?>,
                datasets: [{
                    label: 'Nombre de demandes',
                    data: <?= json_encode($noteData, $jsonOptions) ?>,
                    backgroundColor: <?= json_encode($noteColors, $jsonOptions) ?>,
                }],
            },
            options: {
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
            },
        });
    }

    const canvasSuivi = document.getElementById('graphSuivi');
    if (canvasSuivi) {
        new Chart(canvasSuivi, {
            type: 'line',
            data: {
                labels: <?= json_encode($suiviLabels, $jsonOptions) ?>,
                datasets: [
                    {
                        label: 'Remboursements encaissés',
                        data: <?= json_encode($suiviRembourse, $jsonOptions) ?>,
                        borderColor: '#198754',
                        backgroundColor: 'rgba(25, 135, 84, 0.1)',
                        fill: true,
                        tension: 0.3,
                    },
                    {
                        label: 'Échéances impayées / en retard',
                        data: <?= json_encode($suiviImpaye, $jsonOptions) ?>,
                        borderColor: '#dc3545',
                        backgroundColor: 'rgba(220, 53, 69, 0.1)',
                        fill: true,
                        tension: 0.3,
                    },
                ],
            },
            options: {
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
