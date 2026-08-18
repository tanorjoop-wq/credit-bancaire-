<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AnalyseFinanciere.php';
require_once __DIR__ . '/../../includes/MoteurIntelligenceCredit.php';
exigerRole(['administrateur', 'comite_direction']);

global $pdo;

$idDemande = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT d.*, c.* FROM demandes_credit d JOIN clients c ON c.id_client = d.id_client WHERE d.id_demande = :id'
);
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

$scoring = $pdo->prepare('SELECT * FROM scoring WHERE id_demande = :id');
$scoring->execute(['id' => $idDemande]);
$scoring = $scoring->fetch();

$scoringAvance = $pdo->prepare('SELECT * FROM vue_scoring_avance_actuel WHERE id_demande = :id');
$scoringAvance->execute(['id' => $idDemande]);
$scoringAvance = $scoringAvance->fetch();

$garanties = $pdo->prepare("SELECT * FROM garanties WHERE id_demande = :id AND statut != 'rejetee'");
$garanties->execute(['id' => $idDemande]);
$garanties = $garanties->fetchAll();
$totalGaranties = array_sum(array_column($garanties, 'valeur_estimee'));

$patrimoine = $pdo->prepare('SELECT COALESCE(SUM(valeur_estimee), 0) FROM patrimoine_client WHERE id_client = :id');
$patrimoine->execute(['id' => $demande['id_client']]);
$patrimoineNet = (float) $patrimoine->fetchColumn();

$donneesFinancieres = $pdo->prepare('SELECT * FROM donnees_financieres WHERE id_client = :id ORDER BY date_exercice DESC LIMIT 1');
$donneesFinancieres->execute(['id' => $demande['id_client']]);
$donneesFinancieres = $donneesFinancieres->fetch();

$ratiosFinanciers = null;
if ($donneesFinancieres) {
    $analyseur = new AnalyseFinanciere($pdo);
    $ratiosFinanciers = $demande['type_client'] === 'entreprise'
        ? $analyseur->calculerEntreprise($donneesFinancieres, 0)
        : $analyseur->calculerParticulier($demande, $donneesFinancieres, 0);
}

$moteurIA = new MoteurIntelligenceCredit();
$avisIA = $moteurIA->evaluer($demande, $demande, $ratiosFinanciers, $scoringAvance ?: null, $patrimoineNet, $totalGaranties);

// --- Cycle de vote courant ---
$dateTransmission = $pdo->prepare("SELECT COALESCE(MAX(date_decision), '1970-01-01') FROM workflow_approbation WHERE id_demande = :id AND niveau = 'charge_clientele'");
$dateTransmission->execute(['id' => $idDemande]);
$dateTransmission = $dateTransmission->fetchColumn();

$stmtVotes = $pdo->prepare(
    "SELECT w.*, u.nom, u.prenom FROM workflow_approbation w
     JOIN utilisateurs u ON u.id_utilisateur = w.decideur_id
     WHERE w.id_demande = :id AND w.niveau = 'comite' AND w.date_decision > :date
     ORDER BY w.date_decision"
);
$stmtVotes->execute(['id' => $idDemande, 'date' => $dateTransmission]);
$votes = $stmtVotes->fetchAll();

$dejaVote = false;
foreach ($votes as $v) {
    if ((int) $v['decideur_id'] === (int) $_SESSION['id_utilisateur']) {
        $dejaVote = true;
        break;
    }
}

$nbComiteActifs = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs WHERE role = 'comite_direction' AND actif = 1")->fetchColumn();
$quorum = intdiv($nbComiteActifs, 2) + 1;

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Synthèse comité — ' . $demande['reference'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= e($demande['reference']) ?> — <?= e($demande['nom_raison_sociale']) ?> <?= e($demande['prenom'] ?? '') ?></h1>
    <a href="file_attente.php" class="btn btn-outline-secondary btn-sm">&larr; File d'attente</a>
</div>

<?php if (isset($_GET['succes'])): ?><div class="alert alert-success small"><?= e($_GET['succes']) ?></div><?php endif; ?>
<?php if (isset($_GET['erreur'])): ?><div class="alert alert-danger small"><?= e($_GET['erreur']) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Résumé du dossier</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4">Montant demandé</dt><dd class="col-sm-8"><?= formaterMontant($demande['montant_demande']) ?> sur <?= (int) $demande['duree_mois'] ?> mois à <?= e($demande['taux_interet_propose']) ?> %</dd>
                    <dt class="col-sm-4">Objet</dt><dd class="col-sm-8"><?= e($demande['objet_credit'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Score de base</dt><dd class="col-sm-8"><?= $scoring ? e($scoring['grade']) . ' (' . e($scoring['score_total']) . '/100)' : 'Non calculé' ?></dd>
                    <dt class="col-sm-4">Score avancé</dt><dd class="col-sm-8"><?= $scoringAvance ? e($scoringAvance['note_globale']) . ' (' . e($scoringAvance['score_global']) . '/100)' : 'Non calculé' ?></dd>
                    <dt class="col-sm-4">Garanties</dt><dd class="col-sm-8"><?= formaterMontant($totalGaranties) ?></dd>
                    <dt class="col-sm-4">Patrimoine net client</dt><dd class="col-sm-8"><?= formaterMontant($patrimoineNet) ?></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3 border-info" style="border-width:2px;">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-robot me-1"></i>Avis du Moteur d'Intelligence Crédit <span class="badge bg-secondary ms-1">Déterministe · règles explicables</span></div>
            <div class="card-body">
                <p class="fw-semibold"><?= e($avisIA['recommandation']) ?></p>
                <?php if (!empty($avisIA['incoherences'])): ?>
                    <div class="alert alert-warning small mb-2"><strong>Incohérences détectées :</strong>
                        <ul class="mb-0"><?php foreach ($avisIA['incoherences'] as $inc): ?><li><?= e($inc) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="small fw-semibold text-success">Forces</div>
                        <ul class="small"><?php foreach ($avisIA['forces'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                            <?php if (empty($avisIA['forces'])): ?><li class="text-muted">Aucune force notable identifiée.</li><?php endif; ?>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <div class="small fw-semibold text-danger">Faiblesses</div>
                        <ul class="small"><?php foreach ($avisIA['faiblesses'] as $f): ?><li><?= e($f) ?></li><?php endforeach; ?>
                            <?php if (empty($avisIA['faiblesses'])): ?><li class="text-muted">Aucune faiblesse notable identifiée.</li><?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Votes exprimés (<?= count($votes) ?> / <?= $quorum ?> requis)</div>
            <div class="card-body">
                <?php if (empty($votes)): ?>
                    <p class="text-muted small mb-0">Aucun vote pour l'instant.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($votes as $v): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <span class="badge <?= $v['decision'] === 'favorable' ? 'bg-success' : ($v['decision'] === 'favorable_conditionnel' ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', $v['decision']))) ?>
                                </span>
                                <?= e($v['prenom'] . ' ' . $v['nom']) ?> — <?= e(date('d/m/Y H:i', strtotime($v['date_decision']))) ?>
                                <?php if ($v['commentaire']): ?><div class="text-muted"><?= e($v['commentaire']) ?></div><?php endif; ?>
                                <?php if ($v['conditions']): ?><div class="text-warning">Conditions : <?= e($v['conditions']) ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Mon vote</div>
            <div class="card-body">
                <?php if ($dejaVote): ?>
                    <p class="text-muted small mb-0">Vous avez déjà voté sur ce dossier pour ce cycle. En attente des autres membres.</p>
                <?php else: ?>
                    <form method="post" action="voter.php">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <textarea name="commentaire" class="form-control form-control-sm mb-2" rows="2" placeholder="Commentaire"></textarea>
                        <textarea name="conditions" class="form-control form-control-sm mb-2" rows="2" placeholder="Conditions exigées (si vote conditionnel)"></textarea>
                        <div class="d-grid gap-2">
                            <button type="submit" name="decision" value="favorable" class="btn btn-success btn-sm">Favorable</button>
                            <button type="submit" name="decision" value="favorable_conditionnel" class="btn btn-warning btn-sm">Favorable sous conditions</button>
                            <button type="submit" name="decision" value="defavorable" class="btn btn-danger btn-sm">Défavorable</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/copilote_ia.php'; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
