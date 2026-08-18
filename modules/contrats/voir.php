<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

detecterEcheancesImpayees($pdo);

$idContrat = (int) ($_GET['id'] ?? 0);
if ($idContrat <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare(
    'SELECT c.*, d.reference, d.id_demande, cl.nom_raison_sociale, cl.prenom, cl.telephone, cl.email
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

$stmtEcheances = $pdo->prepare('SELECT * FROM echeancier WHERE id_contrat = :id ORDER BY numero_echeance');
$stmtEcheances->execute(['id' => $idContrat]);
$echeances = $stmtEcheances->fetchAll();

$stmtRemb = $pdo->prepare(
    'SELECT r.*, e.numero_echeance, u.nom, u.prenom
     FROM remboursements r
     JOIN echeancier e ON e.id_echeance = r.id_echeance
     JOIN utilisateurs u ON u.id_utilisateur = r.enregistre_par
     WHERE e.id_contrat = :id
     ORDER BY r.date_paiement DESC'
);
$stmtRemb->execute(['id' => $idContrat]);
$remboursements = $stmtRemb->fetchAll();

$libellesStatutsContrat = [
    'en_preparation' => 'En préparation', 'signe' => 'Signé', 'decaisse' => 'Décaissé',
    'solde' => 'Soldé', 'en_defaut' => 'En défaut',
];
$libellesStatutsEcheance = [
    'a_venir' => 'À venir', 'payee' => 'Payée', 'impayee' => 'Impayée', 'en_retard' => 'En retard', 'annulee' => 'Annulée (restructuration)',
];
$couleursEcheance = [
    'a_venir' => 'bg-secondary', 'payee' => 'bg-success', 'impayee' => 'bg-danger', 'en_retard' => 'bg-warning text-dark', 'annulee' => 'bg-dark',
];

$role = $_SESSION['role'];
$jetonCSRF = genererJetonCSRF();
$titrePage = 'Contrat ' . $contrat['numero_contrat'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h4 mb-0"><?= e($contrat['numero_contrat']) ?></h1>
        <span class="badge bg-secondary mt-1"><?= e($libellesStatutsContrat[$contrat['statut']] ?? $contrat['statut']) ?></span>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/exports/contrat_pdf.php?id=<?= (int) $idContrat ?>" class="btn btn-outline-secondary btn-sm" target="_blank">Attestation PDF</a>
        <a href="liste.php" class="btn btn-outline-secondary btn-sm">&larr; Retour à la liste</a>
    </div>
</div>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['erreur'])): ?>
    <div class="alert alert-danger small"><?= e($_GET['erreur']) ?></div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Informations du contrat</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4">Demande liée</dt>
                    <dd class="col-sm-8"><a href="<?= BASE_URL ?>/modules/demandes/voir.php?id=<?= (int) $contrat['id_demande'] ?>"><?= e($contrat['reference']) ?></a></dd>

                    <dt class="col-sm-4">Client</dt>
                    <dd class="col-sm-8"><?= e($contrat['nom_raison_sociale']) ?> <?= e($contrat['prenom'] ?? '') ?> — <?= e($contrat['telephone']) ?></dd>

                    <dt class="col-sm-4">Montant accordé</dt>
                    <dd class="col-sm-8"><?= formaterMontant($contrat['montant_accorde']) ?></dd>

                    <dt class="col-sm-4">Taux final</dt>
                    <dd class="col-sm-8"><?= e($contrat['taux_final']) ?> %</dd>

                    <dt class="col-sm-4">Durée</dt>
                    <dd class="col-sm-8"><?= (int) $contrat['duree_mois'] ?> mois</dd>

                    <dt class="col-sm-4">Date de signature</dt>
                    <dd class="col-sm-8"><?= $contrat['date_signature'] ? e(date('d/m/Y', strtotime($contrat['date_signature']))) : '—' ?></dd>

                    <dt class="col-sm-4">Date de décaissement</dt>
                    <dd class="col-sm-8"><?= $contrat['date_decaissement'] ? e(date('d/m/Y', strtotime($contrat['date_decaissement']))) : '—' ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Actions</div>
            <div class="card-body d-flex flex-column gap-2">
                <?php if (in_array($role, ['administrateur', 'charge_clientele'], true) && $contrat['statut'] === 'en_preparation'): ?>
                    <form method="post" action="signer.php">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_contrat" value="<?= (int) $idContrat ?>">
                        <button type="submit" class="btn btn-navy btn-sm w-100">Signer le contrat</button>
                    </form>
                <?php endif; ?>

                <?php if ($role === 'administrateur' && $contrat['statut'] === 'signe'): ?>
                    <form method="post" action="decaisser.php">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_contrat" value="<?= (int) $idContrat ?>">
                        <button type="submit" class="btn btn-success btn-sm w-100">Décaisser le crédit</button>
                    </form>
                    <?php if (empty($contrat['email'])): ?>
                        <p class="text-muted small mb-0">⚠ Aucun email client : la notification automatique ne pourra pas être envoyée.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if (!in_array($contrat['statut'], ['en_preparation'], true)): ?>
                    <p class="text-muted small mb-0">
                        Un email est envoyé automatiquement au client (PHPMailer) lors du décaissement.
                    </p>
                <?php endif; ?>

                <?php if (in_array($role, ['administrateur', 'comite_direction'], true) && in_array($contrat['statut'], ['decaisse', 'en_defaut'], true)): ?>
                    <a href="restructurer.php?id=<?= (int) $idContrat ?>" class="btn btn-outline-warning btn-sm w-100">
                        <i class="bi bi-arrow-repeat me-1"></i>Restructurer le contrat
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-header bg-white fw-semibold">Échéancier d'amortissement</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date</th>
                    <th class="text-end">Capital</th>
                    <th class="text-end">Intérêt</th>
                    <th class="text-end">Échéance</th>
                    <th class="text-end">Capital restant dû</th>
                    <th>Statut</th>
                    <th>Retard</th>
                    <?php if (in_array($role, ['administrateur', 'charge_clientele'], true)): ?>
                        <th class="text-end">Action</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($echeances as $echeance): ?>
                    <?php $joursRetard = in_array($echeance['statut'], ['en_retard', 'impayee'], true) ? (int) ((strtotime(date('Y-m-d')) - strtotime($echeance['date_echeance'])) / 86400) : 0; ?>
                    <tr>
                        <td><?= (int) $echeance['numero_echeance'] ?></td>
                        <td><?= e(date('d/m/Y', strtotime($echeance['date_echeance']))) ?></td>
                        <td class="text-end"><?= formaterMontant($echeance['capital']) ?></td>
                        <td class="text-end"><?= formaterMontant($echeance['interet']) ?></td>
                        <td class="text-end fw-semibold"><?= formaterMontant($echeance['montant_echeance']) ?></td>
                        <td class="text-end"><?= formaterMontant($echeance['capital_restant_du']) ?></td>
                        <td><span class="badge <?= $couleursEcheance[$echeance['statut']] ?? 'bg-secondary' ?>"><?= e($libellesStatutsEcheance[$echeance['statut']] ?? $echeance['statut']) ?></span></td>
                        <td><?= $joursRetard > 0 ? $joursRetard . ' j' : '—' ?></td>
                        <?php if (in_array($role, ['administrateur', 'charge_clientele'], true)): ?>
                            <td class="text-end">
                                <?php if (!in_array($echeance['statut'], ['payee', 'annulee'], true) && $contrat['statut'] === 'decaisse'): ?>
                                    <form method="post" action="payer.php" class="d-flex gap-1 justify-content-end">
                                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                                        <input type="hidden" name="id_echeance" value="<?= (int) $echeance['id_echeance'] ?>">
                                        <input type="number" step="1" min="1" name="montant_paye" class="form-control form-control-sm" style="width: 110px;"
                                               value="<?= (int) $echeance['montant_echeance'] ?>" required>
                                        <select name="mode_paiement" class="form-select form-select-sm" style="width: 130px;" required>
                                            <option value="virement">Virement</option>
                                            <option value="especes">Espèces</option>
                                            <option value="mobile_money">Mobile money</option>
                                            <option value="prelevement">Prélèvement</option>
                                        </select>
                                        <button type="submit" class="btn btn-outline-success btn-sm">Payer</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white fw-semibold">Historique des remboursements</div>
    <div class="card-body">
        <?php if (empty($remboursements)): ?>
            <p class="text-muted small mb-0">Aucun remboursement enregistré.</p>
        <?php else: ?>
            <table class="table table-sm mb-0">
                <thead><tr><th>Échéance</th><th>Date</th><th class="text-end">Montant</th><th>Mode</th><th>Enregistré par</th></tr></thead>
                <tbody>
                <?php foreach ($remboursements as $remb): ?>
                    <tr>
                        <td>#<?= (int) $remb['numero_echeance'] ?></td>
                        <td><?= e(date('d/m/Y', strtotime($remb['date_paiement']))) ?></td>
                        <td class="text-end"><?= formaterMontant($remb['montant_paye']) ?></td>
                        <td><?= e(ucfirst(str_replace('_', ' ', $remb['mode_paiement']))) ?></td>
                        <td><?= e($remb['prenom'] . ' ' . $remb['nom']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
