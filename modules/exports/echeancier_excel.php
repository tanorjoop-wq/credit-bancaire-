<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerConnexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

global $pdo;

$idContrat = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT c.*, d.reference, cl.nom_raison_sociale, cl.prenom
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

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setTitle('Échéancier ' . $contrat['numero_contrat'])->setCreator('Plateforme Crédit Bancaire');
$feuille = $spreadsheet->getActiveSheet();
$feuille->setTitle('Echeancier');

$feuille->setCellValue('A1', 'Échéancier — ' . $contrat['numero_contrat'] . ' — ' . $contrat['nom_raison_sociale'] . ' ' . ($contrat['prenom'] ?? ''));
$feuille->mergeCells('A1:G1');
$feuille->getStyle('A1')->getFont()->setBold(true)->setSize(13);

$entetes = ['A3' => '#', 'B3' => 'Date', 'C3' => 'Capital', 'D3' => 'Intérêt', 'E3' => 'Échéance', 'F3' => 'Capital restant dû', 'G3' => 'Statut'];
foreach ($entetes as $cellule => $texte) {
    $feuille->setCellValue($cellule, $texte);
}
$feuille->getStyle('A3:G3')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$feuille->getStyle('A3:G3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2B4C');
$feuille->getStyle('A3:G3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$ligne = 4;
foreach ($echeances as $echeance) {
    $feuille->setCellValue('A' . $ligne, (int) $echeance['numero_echeance']);
    $feuille->setCellValue('B' . $ligne, date('d/m/Y', strtotime($echeance['date_echeance'])));
    $feuille->setCellValue('C' . $ligne, (float) $echeance['capital']);
    $feuille->setCellValue('D' . $ligne, (float) $echeance['interet']);
    $feuille->setCellValue('E' . $ligne, (float) $echeance['montant_echeance']);
    $feuille->setCellValue('F' . $ligne, (float) $echeance['capital_restant_du']);
    $feuille->setCellValue('G' . $ligne, ucfirst(str_replace('_', ' ', $echeance['statut'])));
    $ligne++;
}
$feuille->getStyle('C4:F' . ($ligne - 1))->getNumberFormat()->setFormatCode('#,##0 "FCFA"');

foreach (range('A', 'G') as $colonne) {
    $feuille->getColumnDimension($colonne)->setAutoSize(true);
}
$feuille->freezePane('A4');

enregistrerAudit('EXPORT_EXCEL_ECHEANCIER', 'contrats', $idContrat, 'Export Excel de l\'échéancier du contrat ' . $contrat['numero_contrat']);

$nomFichier = 'echeancier_' . $contrat['numero_contrat'] . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
