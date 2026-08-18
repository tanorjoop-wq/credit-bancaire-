<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$idEcheance = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT e.*, c.numero_contrat, c.id_contrat, cl.nom_raison_sociale, cl.prenom, cl.telephone, cl.email,
            DATEDIFF(CURDATE(), e.date_echeance) AS jours_retard
     FROM echeancier e
     JOIN contrats c ON c.id_contrat = e.id_contrat
     JOIN demandes_credit d ON d.id_demande = c.id_demande
     JOIN clients cl ON cl.id_client = d.id_client
     WHERE e.id_echeance = :id"
);
$stmt->execute(['id' => $idEcheance]);
$echeance = $stmt->fetch();

if (!$echeance) {
    http_response_code(404);
    die('Échéance introuvable.');
}

$stmtRelances = $pdo->prepare(
    'SELECT r.*, u.nom, u.prenom FROM relances_recouvrement r JOIN utilisateurs u ON u.id_utilisateur = r.effectue_par
     WHERE r.id_echeance = :id ORDER BY r.date_relance DESC'
);
$stmtRelances->execute(['id' => $idEcheance]);
$relances = $stmtRelances->fetchAll();

$libellesType = ['appel' => 'Appel téléphonique', 'sms' => 'SMS', 'mise_en_demeure' => 'Mise en demeure'];

$jetonCSRF = genererJetonCSRF();
$titrePage = 'Recouvrement — ' . $echeance['numero_contrat'];
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0">Recouvrement — <?= e($echeance['numero_contrat']) ?> — échéance #<?= (int) $echeance['numero_echeance'] ?></h1>
    <a href="liste.php" class="btn btn-outline-secondary btn-sm">&larr; Retour</a>
</div>

<?php if (isset($_GET['succes'])): ?><div class="alert alert-success small"><?= e($_GET['succes']) ?></div><?php endif; ?>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold">Client</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-4">Nom</dt><dd class="col-sm-8"><?= e($echeance['nom_raison_sociale']) ?> <?= e($echeance['prenom'] ?? '') ?></dd>
                    <dt class="col-sm-4">Téléphone</dt><dd class="col-sm-8"><?= e($echeance['telephone']) ?></dd>
                    <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= e($echeance['email'] ?: '—') ?></dd>
                    <dt class="col-sm-4">Montant dû</dt><dd class="col-sm-8 fw-semibold text-danger"><?= formaterMontant($echeance['montant_echeance']) ?></dd>
                    <dt class="col-sm-4">Échéance</dt><dd class="col-sm-8"><?= e(date('d/m/Y', strtotime($echeance['date_echeance']))) ?></dd>
                    <dt class="col-sm-4">Retard</dt><dd class="col-sm-8"><?= (int) $echeance['jours_retard'] ?> jours</dd>
                    <dt class="col-sm-4">Contrat</dt><dd class="col-sm-8"><a href="<?= BASE_URL ?>/modules/contrats/voir.php?id=<?= (int) $echeance['id_contrat'] ?>"><?= e($echeance['numero_contrat']) ?></a></dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Enregistrer une relance</div>
            <div class="card-body">
                <form method="post" action="relance.php">
                    <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                    <input type="hidden" name="id_echeance" value="<?= (int) $idEcheance ?>">
                    <div class="mb-2">
                        <select name="type_relance" class="form-select form-select-sm" required>
                            <option value="appel">Appel téléphonique</option>
                            <option value="sms">SMS</option>
                            <option value="mise_en_demeure">Mise en demeure</option>
                        </select>
                    </div>
                    <textarea name="commentaire" class="form-control form-control-sm mb-2" rows="2" placeholder="Commentaire (résultat de l'échange...)"></textarea>
                    <button type="submit" class="btn btn-navy btn-sm w-100">Enregistrer la relance</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Historique des relances (<?= count($relances) ?>)</div>
            <div class="card-body">
                <?php if (empty($relances)): ?>
                    <p class="text-muted small mb-0">Aucune relance enregistrée.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0 small">
                        <?php foreach ($relances as $r): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <span class="badge bg-secondary"><?= e($libellesType[$r['type_relance']] ?? $r['type_relance']) ?></span>
                                <?= e($r['prenom'] . ' ' . $r['nom']) ?> — <?= e(date('d/m/Y H:i', strtotime($r['date_relance']))) ?>
                                <?php if ($r['commentaire']): ?><div class="text-muted"><?= e($r['commentaire']) ?></div><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
