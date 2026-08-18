<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerConnexion();

use Dompdf\Dompdf;
use Dompdf\Options;

global $pdo;

// --- Filtre optionnel par statut, comme sur la liste des demandes ---
$statutFiltre = trim($_GET['statut'] ?? '');
$statutsValides = [
    'en_attente', 'en_analyse', 'scoring_effectue', 'en_comite',
    'approuve', 'refuse', 'decaisse', 'solde',
];

$conditions = [];
$parametres = [];
if ($statutFiltre !== '' && in_array($statutFiltre, $statutsValides, true)) {
    $conditions[] = 'd.statut = :statut';
    $parametres['statut'] = $statutFiltre;
}
$whereSql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$stmt = $pdo->prepare(
    "SELECT d.reference, d.type_credit, d.montant_demande, d.duree_mois, d.taux_interet_propose,
            d.statut, d.date_demande, c.nom_raison_sociale, c.prenom, c.type_client,
            s.score_total, s.grade
     FROM demandes_credit d
     JOIN clients c ON c.id_client = d.id_client
     LEFT JOIN scoring s ON s.id_demande = d.id_demande
     $whereSql
     ORDER BY d.id_demande DESC"
);
$stmt->execute($parametres);
$demandes = $stmt->fetchAll();

$libellesStatuts = [
    'en_attente' => 'En attente', 'en_analyse' => 'En analyse', 'scoring_effectue' => 'Scoring effectué',
    'en_comite' => 'En comité', 'approuve' => 'Approuvée', 'refuse' => 'Refusée',
    'decaisse' => 'Décaissée', 'solde' => 'Soldée',
];

$montantTotal = array_sum(array_column($demandes, 'montant_demande'));

// --- Construction du document HTML destiné à DOMPDF ---
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #1a1a1a; }
    h1 { font-size: 16px; color: #1a2b4c; margin-bottom: 2px; }
    .sous-titre { font-size: 10px; color: #555; margin-bottom: 14px; }
    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
    th { background-color: #1a2b4c; color: #fff; padding: 5px 4px; text-align: left; font-size: 9px; }
    td { padding: 4px; border-bottom: 1px solid #ddd; font-size: 9px; }
    tr:nth-child(even) td { background-color: #f4f6f9; }
    .text-end { text-align: right; }
    .footer-total { font-weight: bold; background-color: #eef1f6; }
    .entete-doc { width: 100%; margin-bottom: 10px; }
</style>
</head>
<body>
    <h1>Plateforme Crédit Bancaire — État des demandes de crédit</h1>
    <div class="sous-titre">
        Généré le <?= date('d/m/Y à H:i') ?> par <?= e($_SESSION['nom_complet']) ?>
        <?php if ($statutFiltre !== '' && isset($libellesStatuts[$statutFiltre])): ?>
            — Filtre : <?= e($libellesStatuts[$statutFiltre]) ?>
        <?php endif; ?>
        — <?= count($demandes) ?> demande(s)
    </div>

    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Client</th>
                <th>Type</th>
                <th class="text-end">Montant</th>
                <th>Durée</th>
                <th>Taux</th>
                <th>Score</th>
                <th>Statut</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($demandes as $d): ?>
                <tr>
                    <td><?= e($d['reference']) ?></td>
                    <td><?= e($d['nom_raison_sociale']) ?> <?= e($d['prenom'] ?? '') ?></td>
                    <td><?= e(ucfirst($d['type_credit'])) ?></td>
                    <td class="text-end"><?= number_format((float) $d['montant_demande'], 0, ',', ' ') ?> FCFA</td>
                    <td><?= (int) $d['duree_mois'] ?> mois</td>
                    <td><?= e($d['taux_interet_propose']) ?> %</td>
                    <td><?= $d['score_total'] !== null ? e($d['grade']) . ' (' . e($d['score_total']) . '/100)' : '—' ?></td>
                    <td><?= e($libellesStatuts[$d['statut']] ?? $d['statut']) ?></td>
                    <td><?= e(date('d/m/Y', strtotime($d['date_demande']))) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($demandes)): ?>
                <tr><td colspan="9">Aucune demande pour ce filtre.</td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr class="footer-total">
                <td colspan="3">Total</td>
                <td class="text-end"><?= number_format($montantTotal, 0, ',', ' ') ?> FCFA</td>
                <td colspan="5"></td>
            </tr>
        </tfoot>
    </table>
</body>
</html>
<?php
$html = ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', false);
// Police standard PDF (Helvetica) : pas d'intégration TrueType nécessaire,
// couvre les caractères accentués français (encodage WinAnsi).
$options->set('defaultFont', 'Helvetica');
$options->set('tempDir', sys_get_temp_dir());

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();

enregistrerAudit('EXPORT_PDF_DEMANDES', 'demandes_credit', null, 'Export PDF de l\'état des demandes (' . count($demandes) . ' lignes)');

$nomFichier = 'etat_demandes_credit_' . date('Y-m-d_His') . '.pdf';
$dompdf->stream($nomFichier, ['Attachment' => true]);
