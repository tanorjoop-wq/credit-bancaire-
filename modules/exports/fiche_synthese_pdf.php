<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerConnexion();

use Dompdf\Dompdf;
use Dompdf\Options;

global $pdo;

$idDemande = (int) ($_GET['id' ] ?? 0);

$stmt = $pdo->prepare(
    'SELECT d.*, c.type_client, c.nom_raison_sociale, c.prenom, c.numero_piece, c.telephone, c.email,
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

$scoring = $pdo->prepare('SELECT * FROM scoring WHERE id_demande = :id');
$scoring->execute(['id' => $idDemande]);
$scoring = $scoring->fetch();

$scoringAvance = $pdo->prepare('SELECT * FROM vue_scoring_avance_actuel WHERE id_demande = :id');
$scoringAvance->execute(['id' => $idDemande]);
$scoringAvance = $scoringAvance->fetch();

$rentabilite = $pdo->prepare('SELECT * FROM rentabilite_demande WHERE id_demande = :id');
$rentabilite->execute(['id' => $idDemande]);
$rentabilite = $rentabilite->fetch();

$patrimoine = $pdo->prepare('SELECT * FROM patrimoine_client WHERE id_client = :id');
$patrimoine->execute(['id' => $demande['id_client']]);
$patrimoine = $patrimoine->fetchAll();
$patrimoineNet = array_sum(array_column($patrimoine, 'valeur_estimee'));

$garanties = $pdo->prepare("SELECT * FROM garanties WHERE id_demande = :id AND statut != 'rejetee'");
$garanties->execute(['id' => $idDemande]);
$garanties = $garanties->fetchAll();
$totalGaranties = array_sum(array_column($garanties, 'valeur_estimee'));

$workflow = $pdo->prepare(
    "SELECT w.*, u.nom, u.prenom FROM workflow_approbation w
     JOIN utilisateurs u ON u.id_utilisateur = w.decideur_id
     WHERE w.id_demande = :id ORDER BY w.id_workflow"
);
$workflow->execute(['id' => $idDemande]);
$workflow = $workflow->fetchAll();

$libellesStatuts = [
    'en_attente' => 'En attente', 'en_analyse' => 'En analyse', 'scoring_effectue' => 'Scoring effectué',
    'en_comite' => 'En comité', 'approuve' => 'Approuvée', 'refuse' => 'Refusée',
    'decaisse' => 'Décaissée', 'solde' => 'Soldée',
];

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
    h1 { font-size: 16px; color: #1a2b4c; margin: 0 0 2px 0; }
    .sous-titre { font-size: 9px; color: #666; margin-bottom: 12px; }
    .encadre { border: 1px solid #ccc; border-radius: 3px; padding: 8px 10px; margin-bottom: 8px; }
    .encadre h2 { font-size: 10px; color: #fff; background: #1a2b4c; margin: -8px -10px 6px -10px; padding: 4px 10px; text-transform: uppercase; }
    table.infos { width: 100%; }
    table.infos td { padding: 2px 4px; vertical-align: top; }
    table.infos td.label { color: #555; width: 45%; }
    .cols { width: 100%; }
    .cols td { vertical-align: top; width: 50%; padding-right: 6px; }
    .badge { display: inline-block; padding: 2px 6px; border-radius: 3px; color: #fff; font-size: 9px; }
    .bg-vert { background: #198754; } .bg-orange { background: #fd7e14; } .bg-rouge { background: #dc3545; } .bg-gris { background: #6c757d; }
    .grand-score { font-size: 22px; font-weight: bold; color: #1a2b4c; text-align: center; }
    table.wf { width: 100%; border-collapse: collapse; font-size: 8px; }
    table.wf th { background: #eef1f6; padding: 3px; text-align: left; }
    table.wf td { padding: 3px; border-bottom: 1px solid #eee; }
</style>
</head>
<body>
    <h1>Fiche de synthèse — Comité de crédit</h1>
    <div class="sous-titre">Généré le <?= date('d/m/Y à H:i') ?> par <?= e($_SESSION['nom_complet']) ?> — Demande <?= e($demande['reference']) ?></div>

    <table class="cols"><tr>
    <td>
        <div class="encadre">
            <h2>Demande</h2>
            <table class="infos">
                <tr><td class="label">Référence</td><td><?= e($demande['reference']) ?></td></tr>
                <tr><td class="label">Type de crédit</td><td><?= e(ucfirst($demande['type_credit'])) ?></td></tr>
                <tr><td class="label">Montant demandé</td><td><?= number_format((float) $demande['montant_demande'], 0, ',', ' ') ?> FCFA</td></tr>
                <tr><td class="label">Durée</td><td><?= (int) $demande['duree_mois'] ?> mois</td></tr>
                <tr><td class="label">Taux proposé</td><td><?= e($demande['taux_interet_propose']) ?> %</td></tr>
                <tr><td class="label">Statut</td><td><?= e($libellesStatuts[$demande['statut']] ?? $demande['statut']) ?></td></tr>
                <tr><td class="label">Chargé de clientèle</td><td><?= e($demande['charge_prenom'] . ' ' . $demande['charge_nom']) ?></td></tr>
            </table>
        </div>
    </td>
    <td>
        <div class="encadre">
            <h2>Client</h2>
            <table class="infos">
                <tr><td class="label">Nom</td><td><?= e($demande['nom_raison_sociale']) ?> <?= e($demande['prenom'] ?? '') ?></td></tr>
                <tr><td class="label">Type</td><td><?= e(ucfirst($demande['type_client'])) ?></td></tr>
                <tr><td class="label">N° pièce</td><td><?= e($demande['numero_piece']) ?></td></tr>
                <tr><td class="label">Téléphone</td><td><?= e($demande['telephone']) ?></td></tr>
                <tr><td class="label">Patrimoine net</td><td><?= number_format($patrimoineNet, 0, ',', ' ') ?> FCFA</td></tr>
                <tr><td class="label">Garanties proposées</td><td><?= number_format($totalGaranties, 0, ',', ' ') ?> FCFA</td></tr>
            </table>
        </div>
    </td>
    </tr></table>

    <table class="cols"><tr>
    <td>
        <div class="encadre">
            <h2>Scoring de base</h2>
            <?php if ($scoring): ?>
                <div class="grand-score"><?= e($scoring['grade']) ?> — <?= e($scoring['score_total']) ?>/100</div>
                <table class="infos">
                    <tr><td class="label">Capacité de remboursement</td><td><?= number_format((float) $scoring['capacite_remboursement'], 0, ',', ' ') ?> FCFA</td></tr>
                    <tr><td class="label">Taux d'endettement</td><td><?= e($scoring['taux_endettement']) ?> %</td></tr>
                    <tr><td class="label">Probabilité de défaut</td><td><?= e($scoring['probabilite_defaut']) ?> %</td></tr>
                </table>
            <?php else: ?>
                <p>Non calculé.</p>
            <?php endif; ?>
        </div>
    </td>
    <td>
        <div class="encadre">
            <h2>Scoring avancé (explicable)</h2>
            <?php if ($scoringAvance): ?>
                <div class="grand-score"><?= e($scoringAvance['note_globale']) ?> — <?= e($scoringAvance['score_global']) ?>/100</div>
                <p><strong>Facteurs positifs :</strong>
                <?php foreach ([$scoringAvance['facteur_positif_1'], $scoringAvance['facteur_positif_2'], $scoringAvance['facteur_positif_3']] as $f): ?>
                    <?php if ($f): ?>&bull; <?= e($f) ?><br><?php endif; ?>
                <?php endforeach; ?>
                </p>
                <p><strong>Facteurs de risque :</strong>
                <?php foreach ([$scoringAvance['facteur_risque_1'], $scoringAvance['facteur_risque_2'], $scoringAvance['facteur_risque_3']] as $f): ?>
                    <?php if ($f): ?>&bull; <?= e($f) ?><br><?php endif; ?>
                <?php endforeach; ?>
                </p>
            <?php else: ?>
                <p>Non calculé.</p>
            <?php endif; ?>
        </div>
    </td>
    </tr></table>

    <div class="encadre">
        <h2>Rentabilité (RAROC)</h2>
        <?php if ($rentabilite): ?>
            <table class="infos">
                <tr><td class="label">Marge Nette d'Intérêt</td><td><?= number_format((float) $rentabilite['marge_nette_interet'], 0, ',', ' ') ?> FCFA</td></tr>
                <tr><td class="label">Coût du risque</td><td><?= number_format((float) $rentabilite['cout_du_risque'], 0, ',', ' ') ?> FCFA</td></tr>
                <tr><td class="label">Gain net ajusté du risque</td><td><?= number_format((float) $rentabilite['gain_net_ajuste'], 0, ',', ' ') ?> FCFA</td></tr>
                <tr><td class="label">RAROC</td><td><strong><?= e($rentabilite['raroc']) ?> %</strong> (seuil cible <?= e($rentabilite['seuil_cible']) ?> %) —
                    <span class="badge <?= $rentabilite['verdict'] === 'rentable' ? 'bg-vert' : 'bg-rouge' ?>"><?= $rentabilite['verdict'] === 'rentable' ? 'RENTABLE' : 'MARGE INSUFFISANTE' ?></span>
                </td></tr>
            </table>
        <?php else: ?>
            <p>Non calculé.</p>
        <?php endif; ?>
    </div>

    <div class="encadre">
        <h2>Historique du workflow</h2>
        <?php if (!empty($workflow)): ?>
            <table class="wf">
                <thead><tr><th>Niveau</th><th>Décision</th><th>Commentaire</th><th>Par</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($workflow as $w): ?>
                    <tr>
                        <td><?= e(ucfirst(str_replace('_', ' ', $w['niveau']))) ?></td>
                        <td><?= e(ucfirst($w['decision'])) ?></td>
                        <td><?= e($w['commentaire'] ?: '—') ?></td>
                        <td><?= e($w['prenom'] . ' ' . $w['nom']) ?></td>
                        <td><?= e(date('d/m/Y', strtotime($w['date_decision']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>Aucune décision enregistrée.</p>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'Helvetica');
$options->set('tempDir', sys_get_temp_dir());

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

enregistrerAudit('EXPORT_FICHE_SYNTHESE', 'demandes_credit', $idDemande, 'Export PDF de la fiche de synthèse pour ' . $demande['reference']);

$dompdf->stream('fiche_synthese_' . $demande['reference'] . '.pdf', ['Attachment' => false]);
