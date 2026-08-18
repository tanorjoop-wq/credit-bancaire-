<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$idDemande = (int) ($_GET['id'] ?? 0);
if ($idDemande <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare(
    'SELECT d.*, c.type_client, c.nom_raison_sociale, c.prenom, c.numero_piece, c.telephone,
            c.email, c.revenu_mensuel, c.chiffre_affaires, c.anciennete_bancaire_mois,
            u.nom AS charge_nom, u.prenom AS charge_prenom
     FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     JOIN utilisateurs u ON u.id_utilisateur = d.charge_id
     WHERE d.id_demande = :id'
);
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

$stmtScoring = $pdo->prepare('SELECT * FROM scoring WHERE id_demande = :id');
$stmtScoring->execute(['id' => $idDemande]);
$scoring = $stmtScoring->fetch();

$stmtScoringAvance = $pdo->prepare('SELECT * FROM vue_scoring_avance_actuel WHERE id_demande = :id');
$stmtScoringAvance->execute(['id' => $idDemande]);
$scoringAvance = $stmtScoringAvance->fetch();

$stmtDonneesFinancieres = $pdo->prepare('SELECT COUNT(*) FROM donnees_financieres WHERE id_client = :id');
$stmtDonneesFinancieres->execute(['id' => $demande['id_client']]);
$aDesDonneesFinancieres = (int) $stmtDonneesFinancieres->fetchColumn() > 0;

$stmtGaranties = $pdo->prepare('SELECT * FROM garanties WHERE id_demande = :id ORDER BY id_garantie');
$stmtGaranties->execute(['id' => $idDemande]);
$garanties = $stmtGaranties->fetchAll();
$totalGaranties = array_sum(array_column($garanties, 'valeur_estimee'));

$stmtWorkflow = $pdo->prepare(
    "SELECT w.*, u.nom, u.prenom FROM workflow_approbation w
     JOIN utilisateurs u ON u.id_utilisateur = w.decideur_id
     WHERE w.id_demande = :id ORDER BY w.id_workflow"
);
$stmtWorkflow->execute(['id' => $idDemande]);
$workflow = $stmtWorkflow->fetchAll();

$stmtContrat = $pdo->prepare('SELECT id_contrat, numero_contrat FROM contrats WHERE id_demande = :id');
$stmtContrat->execute(['id' => $idDemande]);
$contratLie = $stmtContrat->fetch();

$stmtDocuments = $pdo->prepare(
    "SELECT doc.*, u.nom, u.prenom FROM documents doc
     JOIN utilisateurs u ON u.id_utilisateur = doc.uploade_par
     WHERE doc.id_demande = :id ORDER BY doc.id_document DESC"
);
$stmtDocuments->execute(['id' => $idDemande]);
$documents = $stmtDocuments->fetchAll();

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

$role = $_SESSION['role'];
$peutGererDossier = in_array($role, ['administrateur', 'charge_clientele'], true);
$peutDeciderComite = in_array($role, ['administrateur', 'comite_direction'], true);

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Demande ' . $demande['reference'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e($demande['reference']) ?></h1>
        <span class="badge badge-statut-<?= e($demande['statut']) ?> mt-1"><?= e($libellesStatuts[$demande['statut']] ?? $demande['statut']) ?></span>
    </div>
    <div>
        <?php if ($peutGererDossier && in_array($demande['statut'], ['en_attente', 'en_analyse'], true)): ?>
            <a href="modifier.php?id=<?= (int) $idDemande ?>" class="btn btn-outline-secondary btn-sm">Modifier</a>
        <?php endif; ?>
        <a href="stress_test.php?id=<?= (int) $idDemande ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tsunami me-1"></i>Stress-test</a>
        <?php if (in_array($role, ['administrateur', 'comite_direction'], true)): ?>
            <a href="<?= BASE_URL ?>/modules/rentabilite/voir.php?id_demande=<?= (int) $idDemande ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-cash-coin me-1"></i>Rentabilité</a>
        <?php endif; ?>
        <?php if ($role === 'administrateur' && $demande['statut'] === 'en_attente'): ?>
            <a href="supprimer.php?id=<?= (int) $idDemande ?>" class="btn btn-outline-danger btn-sm"
               onclick="return confirm('Confirmez-vous la suppression de cette demande ?');">Supprimer</a>
        <?php endif; ?>
        <a href="liste.php" class="btn btn-outline-secondary btn-sm">&larr; Retour à la liste</a>
    </div>
</div>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['erreur'])): ?>
    <div class="alert alert-danger small"><?= e($_GET['erreur']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Informations sur la demande</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-5">Client</dt>
                    <dd class="col-sm-7"><?= e($demande['nom_raison_sociale']) ?> <?= e($demande['prenom'] ?? '') ?> (<?= e(ucfirst($demande['type_client'])) ?>)</dd>

                    <dt class="col-sm-5">Type de crédit</dt>
                    <dd class="col-sm-7"><?= e(ucfirst($demande['type_credit'])) ?></dd>

                    <dt class="col-sm-5">Montant demandé</dt>
                    <dd class="col-sm-7"><?= formaterMontant($demande['montant_demande']) ?></dd>

                    <dt class="col-sm-5">Durée</dt>
                    <dd class="col-sm-7"><?= (int) $demande['duree_mois'] ?> mois</dd>

                    <dt class="col-sm-5">Taux d'intérêt proposé</dt>
                    <dd class="col-sm-7"><?= e($demande['taux_interet_propose']) ?> %</dd>

                    <dt class="col-sm-5">Objet</dt>
                    <dd class="col-sm-7"><?= e($demande['objet_credit'] ?: '—') ?></dd>

                    <dt class="col-sm-5">Chargé de clientèle</dt>
                    <dd class="col-sm-7"><?= e($demande['charge_prenom'] . ' ' . $demande['charge_nom']) ?></dd>

                    <dt class="col-sm-5">Date de la demande</dt>
                    <dd class="col-sm-7"><?= e(date('d/m/Y à H:i', strtotime($demande['date_demande']))) ?></dd>

                    <?php if ($demande['date_decision']): ?>
                        <dt class="col-sm-5">Date de décision</dt>
                        <dd class="col-sm-7"><?= e(date('d/m/Y à H:i', strtotime($demande['date_decision']))) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Garanties</div>
            <div class="card-body">
                <?php if (empty($garanties)): ?>
                    <p class="text-muted small mb-3">Aucune garantie enregistrée.</p>
                <?php else: ?>
                    <table class="table table-sm mb-3">
                        <thead><tr><th>Type</th><th>Description</th><th class="text-end">Valeur estimée</th><th>Statut</th></tr></thead>
                        <tbody>
                        <?php foreach ($garanties as $garantie): ?>
                            <tr>
                                <td><?= e(ucfirst(str_replace('_', ' ', $garantie['type_garantie']))) ?></td>
                                <td><?= e($garantie['description'] ?: '—') ?></td>
                                <td class="text-end"><?= formaterMontant($garantie['valeur_estimee']) ?></td>
                                <td><span class="badge bg-secondary"><?= e($garantie['statut']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold"><td colspan="2">Total</td><td class="text-end"><?= formaterMontant($totalGaranties) ?></td><td></td></tr>
                        </tfoot>
                    </table>
                <?php endif; ?>

                <?php if ($peutGererDossier && !in_array($demande['statut'], ['decaisse', 'solde'], true)): ?>
                    <form method="post" action="garantie.php" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <div class="col-md-3">
                            <select name="type_garantie" class="form-select form-select-sm" required>
                                <option value="hypotheque">Hypothèque</option>
                                <option value="caution">Caution</option>
                                <option value="nantissement">Nantissement</option>
                                <option value="gage">Gage</option>
                                <option value="domiciliation_salaire">Domiciliation salaire</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Description">
                        </div>
                        <div class="col-md-3">
                            <input type="number" step="1" min="1" name="valeur_estimee" class="form-control form-control-sm" placeholder="Valeur estimée" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Ajouter</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Pièces justificatives</div>
            <div class="card-body">
                <?php if (empty($documents)): ?>
                    <p class="text-muted small mb-3">Aucun document déposé.</p>
                <?php else: ?>
                    <table class="table table-sm mb-3">
                        <thead><tr><th>Type</th><th>Fichier</th><th>Déposé par</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr>
                                <td><?= e($document['type_document']) ?></td>
                                <td><?= e($document['nom_fichier']) ?></td>
                                <td><?= e($document['prenom'] . ' ' . $document['nom']) ?></td>
                                <td class="small text-muted"><?= e(date('d/m/Y', strtotime($document['date_upload']))) ?></td>
                                <td class="text-end">
                                    <a href="document_telecharger.php?id=<?= (int) $document['id_document'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Voir</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($peutGererDossier && !in_array($demande['statut'], ['decaisse', 'solde'], true)): ?>
                    <form method="post" action="document_upload.php" enctype="multipart/form-data" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <div class="col-md-4">
                            <input type="text" name="type_document" class="form-control form-control-sm" placeholder="Type (ex: bulletin de salaire)" required>
                        </div>
                        <div class="col-md-5">
                            <input type="file" name="fichier" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Déposer</button>
                        </div>
                        <div class="col-12 text-muted small">PDF, JPG ou PNG — 5 Mo maximum.</div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Historique du workflow</div>
            <div class="card-body">
                <?php if (empty($workflow)): ?>
                    <p class="text-muted small mb-0">Aucune décision enregistrée pour le moment.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($workflow as $etape): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <span class="badge <?= $etape['decision'] === 'favorable' ? 'bg-success' : ($etape['decision'] === 'defavorable' ? 'bg-danger' : 'bg-secondary') ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', $etape['niveau']))) ?> — <?= e(ucfirst($etape['decision'])) ?>
                                </span>
                                <div class="mt-1"><?= e($etape['commentaire'] ?: '—') ?></div>
                                <div class="text-muted"><?= e($etape['prenom'] . ' ' . $etape['nom']) ?> · <?= e(date('d/m/Y H:i', strtotime($etape['date_decision']))) ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Scoring</div>
            <div class="card-body">
                <?php if ($scoring): ?>
                    <div class="text-center mb-3">
                        <div class="display-6 fw-bold text-navy"><?= e($scoring['grade']) ?></div>
                        <div class="text-muted small"><?= e($scoring['score_total']) ?> / 100</div>
                    </div>
                    <dl class="row mb-0 small">
                        <dt class="col-sm-7">Capacité de remboursement</dt>
                        <dd class="col-sm-5 text-end"><?= formaterMontant($scoring['capacite_remboursement']) ?></dd>

                        <dt class="col-sm-7">Taux d'endettement</dt>
                        <dd class="col-sm-5 text-end"><?= e($scoring['taux_endettement']) ?> %</dd>

                        <dt class="col-sm-7">Valeur des garanties</dt>
                        <dd class="col-sm-5 text-end"><?= formaterMontant($scoring['valeur_garanties']) ?></dd>

                        <dt class="col-sm-7">Probabilité de défaut</dt>
                        <dd class="col-sm-5 text-end"><?= e($scoring['probabilite_defaut']) ?> %</dd>

                        <dt class="col-sm-7">Calculé le</dt>
                        <dd class="col-sm-5 text-end"><?= e(date('d/m/Y H:i', strtotime($scoring['date_calcul']))) ?></dd>
                    </dl>
                <?php else: ?>
                    <p class="text-muted small">Le scoring n'a pas encore été calculé pour cette demande.</p>
                <?php endif; ?>

                <?php if ($peutGererDossier && !in_array($demande['statut'], ['en_comite', 'approuve', 'refuse', 'decaisse', 'solde'], true)): ?>
                    <form method="post" action="scoring.php" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <button type="submit" class="btn btn-navy btn-sm w-100">
                            <?= $scoring ? 'Recalculer le scoring' : 'Calculer le scoring' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 me-1"></i>Scoring avancé (multidimensionnel)</div>
            <div class="card-body">
                <?php if ($scoringAvance): ?>
                    <div class="text-center mb-3">
                        <div class="display-6 fw-bold text-navy"><?= e($scoringAvance['note_globale']) ?></div>
                        <div class="text-muted small"><?= e($scoringAvance['score_global']) ?> / 100</div>
                    </div>
                    <dl class="row mb-3 small">
                        <dt class="col-sm-7">Score financier</dt><dd class="col-sm-5 text-end"><?= e($scoringAvance['score_financier']) ?> /100</dd>
                        <dt class="col-sm-7">Score patrimonial</dt><dd class="col-sm-5 text-end"><?= e($scoringAvance['score_patrimonial']) ?> /100</dd>
                        <dt class="col-sm-7">Score comportemental</dt><dd class="col-sm-5 text-end"><?= e($scoringAvance['score_comportemental']) ?> /100</dd>
                    </dl>
                    <div class="mb-2">
                        <div class="small fw-semibold text-success mb-1">Facteurs positifs</div>
                        <ul class="small mb-0 ps-3">
                            <?php foreach ([$scoringAvance['facteur_positif_1'], $scoringAvance['facteur_positif_2'], $scoringAvance['facteur_positif_3']] as $f): ?>
                                <?php if ($f): ?><li><?= e($f) ?></li><?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div>
                        <div class="small fw-semibold text-danger mb-1">Facteurs de risque</div>
                        <ul class="small mb-0 ps-3">
                            <?php foreach ([$scoringAvance['facteur_risque_1'], $scoringAvance['facteur_risque_2'], $scoringAvance['facteur_risque_3']] as $f): ?>
                                <?php if ($f): ?><li><?= e($f) ?></li><?php endif; ?>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php elseif (!$aDesDonneesFinancieres): ?>
                    <p class="text-muted small mb-0">
                        Saisissez d'abord les <a href="<?= BASE_URL ?>/modules/analyse/saisie.php?id_client=<?= (int) $demande['id_client'] ?>">données financières du client</a>.
                    </p>
                <?php else: ?>
                    <p class="text-muted small">Le scoring avancé n'a pas encore été calculé.</p>
                <?php endif; ?>

                <?php if ($peutGererDossier && $aDesDonneesFinancieres && !in_array($demande['statut'], ['approuve', 'refuse', 'decaisse', 'solde'], true)): ?>
                    <form method="post" action="scoring_avance.php" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">
                            <?= $scoringAvance ? 'Recalculer le scoring avancé' : 'Calculer le scoring avancé' ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($peutGererDossier && $demande['statut'] === 'scoring_effectue'): ?>
            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white fw-semibold">Transmission au comité</div>
                <div class="card-body">
                    <form method="post" action="decision.php">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <input type="hidden" name="action" value="transmettre">
                        <textarea name="commentaire" class="form-control form-control-sm mb-2" rows="2" placeholder="Commentaire (optionnel)"></textarea>
                        <button type="submit" class="btn btn-navy btn-sm w-100">Transmettre au comité</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($peutDeciderComite && $demande['statut'] === 'en_comite'): ?>
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-semibold">Décision du comité</div>
                <div class="card-body">
                    <form method="post" action="decision.php">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                        <input type="hidden" name="action" value="decision_comite">
                        <textarea name="commentaire" class="form-control form-control-sm mb-2" rows="2" placeholder="Commentaire de décision"></textarea>
                        <div class="d-flex gap-2">
                            <button type="submit" name="decision" value="favorable" class="btn btn-success btn-sm flex-fill">Approuver</button>
                            <button type="submit" name="decision" value="defavorable" class="btn btn-danger btn-sm flex-fill">Refuser</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($demande['statut'] === 'approuve' || $contratLie): ?>
            <div class="card shadow-sm border-0 mt-3">
                <div class="card-header bg-white fw-semibold">Contrat</div>
                <div class="card-body">
                    <?php if ($contratLie): ?>
                        <a href="<?= BASE_URL ?>/modules/contrats/voir.php?id=<?= (int) $contratLie['id_contrat'] ?>" class="btn btn-navy btn-sm w-100">
                            Voir le contrat <?= e($contratLie['numero_contrat']) ?>
                        </a>
                    <?php elseif ($peutGererDossier): ?>
                        <form method="post" action="<?= BASE_URL ?>/modules/contrats/generer.php">
                            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                            <input type="hidden" name="id_demande" value="<?= (int) $idDemande ?>">
                            <button type="submit" class="btn btn-navy btn-sm w-100">Générer le contrat</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/copilote_ia.php'; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
