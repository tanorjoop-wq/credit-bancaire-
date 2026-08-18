<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$idClient = (int) ($_GET['id'] ?? 0);
if ($idClient <= 0) {
    rediriger('liste.php');
}

$stmt = $pdo->prepare('SELECT * FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
$client = $stmt->fetch();

if (!$client) {
    http_response_code(404);
    die('Client introuvable.');
}

$stmtDemandes = $pdo->prepare(
    "SELECT d.id_demande, d.reference, d.type_credit, d.montant_demande, d.statut, d.date_demande
     FROM demandes_credit d WHERE d.id_client = :id ORDER BY d.id_demande DESC"
);
$stmtDemandes->execute(['id' => $idClient]);
$demandes = $stmtDemandes->fetchAll();

$stmtPatrimoine = $pdo->prepare('SELECT * FROM patrimoine_client WHERE id_client = :id ORDER BY id_patrimoine DESC');
$stmtPatrimoine->execute(['id' => $idClient]);
$patrimoine = $stmtPatrimoine->fetchAll();
$patrimoineNet = array_sum(array_column($patrimoine, 'valeur_estimee'));

detecterDocumentsExpires($pdo);
$stmtDocuments = $pdo->prepare('SELECT * FROM documents WHERE id_client = :id ORDER BY id_document DESC');
$stmtDocuments->execute(['id' => $idClient]);
$documentsClient = $stmtDocuments->fetchAll();
$libellesStatutDoc = ['valide' => 'Validé', 'manquant' => 'Manquant', 'expire' => 'Expiré'];
$couleursStatutDoc = ['valide' => 'bg-success', 'manquant' => 'bg-secondary', 'expire' => 'bg-danger'];

// Demande active la plus récente, pour contextualiser le taux de couverture
$demandeActive = null;
foreach ($demandes as $d) {
    if (!in_array($d['statut'], ['refuse'], true)) {
        $demandeActive = $d;
        break;
    }
}
$tauxCouverture = ($demandeActive && (float) $demandeActive['montant_demande'] > 0)
    ? round($patrimoineNet / (float) $demandeActive['montant_demande'] * 100, 1)
    : null;

$libellesStatuts = [
    'en_attente' => 'En attente', 'en_analyse' => 'En analyse', 'scoring_effectue' => 'Scoring effectué',
    'en_comite' => 'En comité', 'approuve' => 'Approuvée', 'refuse' => 'Refusée',
    'decaisse' => 'Décaissée', 'solde' => 'Soldée',
];
$libellesActifs = ['immobilier' => 'Immobilier', 'vehicule' => 'Véhicule', 'epargne' => 'Épargne', 'autre' => 'Autre'];

$role = $_SESSION['role'];
$peutGerer = in_array($role, ['administrateur', 'charge_clientele'], true);
$jetonCSRF = genererJetonCSRF();
$titrePage = 'Dossier client';
require __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= e($client['nom_raison_sociale']) ?> <?= e($client['prenom'] ?? '') ?></h1>
    <div>
        <a href="modifier.php?id=<?= (int) $idClient ?>" class="btn btn-outline-secondary btn-sm">Modifier</a>
        <a href="liste.php" class="btn btn-outline-secondary btn-sm">&larr; Retour</a>
    </div>
</div>

<?php if (isset($_GET['succes'])): ?>
    <div class="alert alert-success small"><?= e($_GET['succes']) ?></div>
<?php endif; ?>
<?php if (isset($_GET['erreur'])): ?>
    <div class="alert alert-danger small"><?= e($_GET['erreur']) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person-badge me-1"></i>Profil KYC</div>
            <div class="card-body text-center">
                <?php if ($client['photo_path']): ?>
                    <img src="kyc_telecharger.php?id=<?= (int) $idClient ?>&type=photo" alt="Photo" class="rounded mb-2" style="width:120px;height:120px;object-fit:cover;">
                <?php else: ?>
                    <div class="bg-light rounded d-flex align-items-center justify-content-center mx-auto mb-2" style="width:120px;height:120px;">
                        <i class="bi bi-person text-muted" style="font-size:2.5rem;"></i>
                    </div>
                <?php endif; ?>

                <?php if ($peutGerer): ?>
                    <form method="post" action="kyc_upload.php" enctype="multipart/form-data" class="mt-2">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_client" value="<?= (int) $idClient ?>">
                        <input type="hidden" name="type" value="photo">
                        <input type="file" name="fichier" class="form-control form-control-sm mb-2" accept=".jpg,.jpeg,.png" required>
                        <button type="submit" class="btn btn-outline-secondary btn-sm w-100">Mettre à jour la photo</button>
                    </form>
                <?php endif; ?>

                <hr>

                <div class="small text-muted mb-1">Signature</div>
                <?php if ($client['signature_path']): ?>
                    <img src="kyc_telecharger.php?id=<?= (int) $idClient ?>&type=signature" alt="Signature" class="border rounded mb-2" style="width:100%;max-width:220px;background:#fff;">
                <?php else: ?>
                    <div class="text-muted small mb-2">Aucune signature enregistrée.</div>
                <?php endif; ?>

                <?php if ($peutGerer): ?>
                    <canvas id="padSignature" width="220" height="100" class="border rounded bg-white" style="touch-action:none;cursor:crosshair;"></canvas>
                    <div class="d-flex gap-1 mt-2">
                        <button type="button" id="btnEffacer" class="btn btn-outline-secondary btn-sm flex-fill">Effacer</button>
                        <button type="button" id="btnEnregistrerSignature" class="btn btn-navy btn-sm flex-fill">Enregistrer</button>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Informations</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-sm-5">Type</dt><dd class="col-sm-7"><?= e(ucfirst($client['type_client'])) ?></dd>
                    <dt class="col-sm-5">N° pièce</dt><dd class="col-sm-7"><?= e($client['numero_piece']) ?></dd>
                    <dt class="col-sm-5">Téléphone</dt><dd class="col-sm-7"><?= e($client['telephone']) ?></dd>
                    <dt class="col-sm-5">Email</dt><dd class="col-sm-7"><?= e($client['email'] ?: '—') ?></dd>
                    <dt class="col-sm-5">Ancienneté</dt><dd class="col-sm-7"><?= (int) $client['anciennete_bancaire_mois'] ?> mois</dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-briefcase me-1"></i>Bilan patrimonial</span>
                <div class="text-end">
                    <span class="badge bg-navy">Patrimoine net : <?= formaterMontant($patrimoineNet) ?></span>
                    <?php if ($tauxCouverture !== null): ?>
                        <span class="badge <?= $tauxCouverture >= 100 ? 'bg-success' : ($tauxCouverture >= 50 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                            Couverture prêt : <?= e((string) $tauxCouverture) ?> %
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($patrimoine)): ?>
                    <p class="text-muted small mb-3">Aucun actif enregistré.</p>
                <?php else: ?>
                    <table class="table table-sm mb-3">
                        <thead><tr><th>Type</th><th>Description</th><th class="text-end">Valeur estimée</th><th>Évalué le</th><?php if ($peutGerer): ?><th></th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($patrimoine as $actif): ?>
                            <tr>
                                <td><?= e($libellesActifs[$actif['type_actif']] ?? $actif['type_actif']) ?></td>
                                <td><?= e($actif['description'] ?: '—') ?></td>
                                <td class="text-end"><?= formaterMontant($actif['valeur_estimee']) ?></td>
                                <td class="small text-muted"><?= e(date('d/m/Y', strtotime($actif['date_evaluation']))) ?></td>
                                <?php if ($peutGerer): ?>
                                    <td class="text-end">
                                        <form method="post" action="patrimoine.php" onsubmit="return confirm('Supprimer cet actif ?');">
                                            <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="id_patrimoine" value="<?= (int) $actif['id_patrimoine'] ?>">
                                            <input type="hidden" name="id_client" value="<?= (int) $idClient ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Suppr.</button>
                                        </form>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($peutGerer): ?>
                    <form method="post" action="patrimoine.php" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="action" value="ajouter">
                        <input type="hidden" name="id_client" value="<?= (int) $idClient ?>">
                        <div class="col-md-3">
                            <select name="type_actif" class="form-select form-select-sm" required>
                                <option value="immobilier">Immobilier</option>
                                <option value="vehicule">Véhicule</option>
                                <option value="epargne">Épargne</option>
                                <option value="autre">Autre</option>
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
            <div class="card-header bg-white fw-semibold"><i class="bi bi-folder2-open me-1"></i>Documents</div>
            <div class="card-body">
                <?php if (empty($documentsClient)): ?>
                    <p class="text-muted small mb-3">Aucun document déposé.</p>
                <?php else: ?>
                    <table class="table table-sm mb-3">
                        <thead><tr><th>Type</th><th>Fichier</th><th>Version</th><th>Statut</th><th>Expiration</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($documentsClient as $doc): ?>
                            <tr>
                                <td><?= e($doc['type_document']) ?></td>
                                <td class="small"><?= e($doc['nom_fichier']) ?></td>
                                <td>v<?= (int) $doc['version'] ?></td>
                                <td><span class="badge <?= $couleursStatutDoc[$doc['statut_validation']] ?? 'bg-secondary' ?>"><?= e($libellesStatutDoc[$doc['statut_validation']] ?? $doc['statut_validation']) ?></span></td>
                                <td class="small"><?= $doc['date_expiration'] ? e(date('d/m/Y', strtotime($doc['date_expiration']))) : '—' ?></td>
                                <td class="text-end"><a href="<?= BASE_URL ?>/modules/documents/telecharger.php?id=<?= (int) $doc['id_document'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Voir</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <?php if ($peutGerer): ?>
                    <form method="post" action="<?= BASE_URL ?>/modules/documents/upload.php" enctype="multipart/form-data" class="row g-2">
                        <input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">
                        <input type="hidden" name="id_client" value="<?= (int) $idClient ?>">
                        <input type="hidden" name="retour" value="<?= BASE_URL ?>/modules/clients/voir.php?id=<?= (int) $idClient ?>">
                        <div class="col-md-3"><input type="text" name="type_document" class="form-control form-control-sm" placeholder="Type (ex: CNI)" required></div>
                        <div class="col-md-3"><input type="file" name="fichier" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required></div>
                        <div class="col-md-3"><input type="date" name="date_expiration" class="form-control form-control-sm" placeholder="Expiration (optionnel)"></div>
                        <div class="col-md-3"><button type="submit" class="btn btn-outline-secondary btn-sm w-100">Déposer</button></div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Historique des demandes de crédit</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Référence</th><th>Type</th><th class="text-end">Montant</th><th>Statut</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                        <?php if (empty($demandes)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Aucune demande.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($demandes as $demande): ?>
                            <tr>
                                <td><?= e($demande['reference']) ?></td>
                                <td><?= e(ucfirst($demande['type_credit'])) ?></td>
                                <td class="text-end"><?= formaterMontant($demande['montant_demande']) ?></td>
                                <td><span class="badge badge-statut-<?= e($demande['statut']) ?>"><?= e($libellesStatuts[$demande['statut']] ?? $demande['statut']) ?></span></td>
                                <td class="small text-muted"><?= e(date('d/m/Y', strtotime($demande['date_demande']))) ?></td>
                                <td class="text-end"><a href="<?= BASE_URL ?>/modules/demandes/voir.php?id=<?= (int) $demande['id_demande'] ?>" class="btn btn-sm btn-outline-secondary">Voir</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($peutGerer): ?>
<script>
(function () {
    const canvas = document.getElementById('padSignature');
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    ctx.strokeStyle = '#1a2b4c';
    ctx.lineWidth = 2;
    ctx.lineJoin = 'round';
    ctx.lineCap = 'round';
    let dessine = false;

    function position(evt) {
        const rect = canvas.getBoundingClientRect();
        const point = evt.touches ? evt.touches[0] : evt;
        return { x: point.clientX - rect.left, y: point.clientY - rect.top };
    }
    function demarrer(evt) {
        dessine = true;
        const p = position(evt);
        ctx.beginPath();
        ctx.moveTo(p.x, p.y);
        evt.preventDefault();
    }
    function tracer(evt) {
        if (!dessine) return;
        const p = position(evt);
        ctx.lineTo(p.x, p.y);
        ctx.stroke();
        evt.preventDefault();
    }
    function arreter() { dessine = false; }

    canvas.addEventListener('mousedown', demarrer);
    canvas.addEventListener('mousemove', tracer);
    canvas.addEventListener('mouseup', arreter);
    canvas.addEventListener('mouseleave', arreter);
    canvas.addEventListener('touchstart', demarrer);
    canvas.addEventListener('touchmove', tracer);
    canvas.addEventListener('touchend', arreter);

    document.getElementById('btnEffacer').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
    });

    document.getElementById('btnEnregistrerSignature').addEventListener('click', function () {
        const donnees = canvas.toDataURL('image/png');
        const formulaire = document.createElement('form');
        formulaire.method = 'post';
        formulaire.action = 'kyc_upload.php';
        formulaire.innerHTML =
            '<input type="hidden" name="csrf_token" value="<?= e($jetonCSRF) ?>">' +
            '<input type="hidden" name="id_client" value="<?= (int) $idClient ?>">' +
            '<input type="hidden" name="type" value="signature">' +
            '<input type="hidden" name="signature_data" value="' + donnees + '">';
        document.body.appendChild(formulaire);
        formulaire.submit();
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
