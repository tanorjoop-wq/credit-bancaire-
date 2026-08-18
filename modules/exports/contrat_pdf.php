<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerConnexion();

use Dompdf\Dompdf;
use Dompdf\Options;

global $pdo;

$idContrat = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, d.reference, cl.nom_raison_sociale, cl.prenom, cl.numero_piece, cl.telephone, cl.email, cl.adresse
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

$libellesStatuts = [
    'en_preparation' => 'En préparation', 'signe' => 'Signé', 'decaisse' => 'Décaissé',
    'solde' => 'Soldé', 'en_defaut' => 'En défaut',
];

ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1a1a1a; }
    h1 { font-size: 18px; color: #1a2b4c; margin-bottom: 0; }
    .sous-titre { font-size: 11px; color: #555; margin-bottom: 20px; }
    .encadre { border: 1px solid #ccc; border-radius: 4px; padding: 10px 14px; margin-bottom: 14px; }
    .encadre h2 { font-size: 12px; color: #1a2b4c; margin: 0 0 8px 0; text-transform: uppercase; }
    table.infos { width: 100%; }
    table.infos td { padding: 3px 4px; vertical-align: top; }
    table.infos td.label { color: #555; width: 40%; }
    table.echeancier { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.echeancier th { background-color: #1a2b4c; color: #fff; padding: 4px; font-size: 9px; text-align: left; }
    table.echeancier td { padding: 3px 4px; border-bottom: 1px solid #ddd; font-size: 9px; }
    .text-end { text-align: right; }
    .signature-zone { margin-top: 40px; }
    .signature-box { display: inline-block; width: 45%; border-top: 1px solid #333; padding-top: 4px; font-size: 10px; }
    .footer-doc { margin-top: 30px; font-size: 8px; color: #888; text-align: center; }
</style>
</head>
<body>
    <h1>Attestation de contrat de crédit</h1>
    <div class="sous-titre">Plateforme Crédit Bancaire — Document généré le <?= date('d/m/Y à H:i') ?></div>

    <div class="encadre">
        <h2>Contrat</h2>
        <table class="infos">
            <tr><td class="label">Numéro de contrat</td><td><?= e($contrat['numero_contrat']) ?></td></tr>
            <tr><td class="label">Demande de crédit liée</td><td><?= e($contrat['reference']) ?></td></tr>
            <tr><td class="label">Statut</td><td><?= e($libellesStatuts[$contrat['statut']] ?? $contrat['statut']) ?></td></tr>
            <tr><td class="label">Montant accordé</td><td><?= number_format((float) $contrat['montant_accorde'], 0, ',', ' ') ?> FCFA</td></tr>
            <tr><td class="label">Taux d'intérêt annuel</td><td><?= e($contrat['taux_final']) ?> %</td></tr>
            <tr><td class="label">Durée</td><td><?= (int) $contrat['duree_mois'] ?> mois</td></tr>
            <tr><td class="label">Date de signature</td><td><?= $contrat['date_signature'] ? e(date('d/m/Y', strtotime($contrat['date_signature']))) : 'Non signé' ?></td></tr>
            <tr><td class="label">Date de décaissement</td><td><?= $contrat['date_decaissement'] ? e(date('d/m/Y', strtotime($contrat['date_decaissement']))) : 'Non décaissé' ?></td></tr>
        </table>
    </div>

    <div class="encadre">
        <h2>Client</h2>
        <table class="infos">
            <tr><td class="label">Nom / Raison sociale</td><td><?= e($contrat['nom_raison_sociale']) ?> <?= e($contrat['prenom'] ?? '') ?></td></tr>
            <tr><td class="label">N° pièce (CNI/NINEA)</td><td><?= e($contrat['numero_piece']) ?></td></tr>
            <tr><td class="label">Téléphone</td><td><?= e($contrat['telephone']) ?></td></tr>
            <tr><td class="label">Email</td><td><?= e($contrat['email'] ?: '—') ?></td></tr>
            <tr><td class="label">Adresse</td><td><?= e($contrat['adresse'] ?: '—') ?></td></tr>
        </table>
    </div>

    <?php if (!empty($echeances)): ?>
        <div class="encadre">
            <h2>Tableau d'amortissement</h2>
            <table class="echeancier">
                <thead>
                    <tr><th>#</th><th>Date</th><th class="text-end">Capital</th><th class="text-end">Intérêt</th><th class="text-end">Échéance</th><th class="text-end">Capital restant dû</th><th>Statut</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($echeances as $ech): ?>
                        <tr>
                            <td><?= (int) $ech['numero_echeance'] ?></td>
                            <td><?= e(date('d/m/Y', strtotime($ech['date_echeance']))) ?></td>
                            <td class="text-end"><?= number_format((float) $ech['capital'], 0, ',', ' ') ?></td>
                            <td class="text-end"><?= number_format((float) $ech['interet'], 0, ',', ' ') ?></td>
                            <td class="text-end"><?= number_format((float) $ech['montant_echeance'], 0, ',', ' ') ?></td>
                            <td class="text-end"><?= number_format((float) $ech['capital_restant_du'], 0, ',', ' ') ?></td>
                            <td><?= e(ucfirst(str_replace('_', ' ', $ech['statut']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <div class="signature-zone">
        <div class="signature-box">Le client</div>
        <div class="signature-box" style="float:right;">Pour la banque</div>
    </div>

    <div class="footer-doc">Document généré automatiquement — Plateforme Crédit Bancaire — Master CCA, ESP Dakar</div>
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

enregistrerAudit('EXPORT_PDF_CONTRAT', 'contrats', $idContrat, 'Export PDF de l\'attestation du contrat ' . $contrat['numero_contrat']);

$dompdf->stream('attestation_' . $contrat['numero_contrat'] . '.pdf', ['Attachment' => false]);
