<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerRole(['administrateur']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

global $pdo;

$stmt = $pdo->query(
    "SELECT j.date_action, u.prenom, u.nom, u.role, j.action, j.table_concernee, j.id_enregistrement, j.details
     FROM journal_audit j
     JOIN utilisateurs u ON u.id_utilisateur = j.id_utilisateur
     ORDER BY j.id_audit DESC"
);
$entrees = $stmt->fetchAll();

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setTitle('Journal d\'audit')->setCreator('Plateforme Crédit Bancaire');
$feuille = $spreadsheet->getActiveSheet();
$feuille->setTitle('Audit');

$entetes = ['A1' => 'Date', 'B1' => 'Utilisateur', 'C1' => 'Rôle', 'D1' => 'Action', 'E1' => 'Table', 'F1' => 'ID', 'G1' => 'Détails'];
foreach ($entetes as $cellule => $texte) {
    $feuille->setCellValue($cellule, $texte);
}
$feuille->getStyle('A1:G1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$feuille->getStyle('A1:G1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2B4C');
$feuille->getStyle('A1:G1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$ligne = 2;
foreach ($entrees as $entree) {
    $feuille->setCellValue('A' . $ligne, date('d/m/Y H:i:s', strtotime($entree['date_action'])));
    $feuille->setCellValue('B' . $ligne, $entree['prenom'] . ' ' . $entree['nom']);
    $feuille->setCellValue('C' . $ligne, $entree['role']);
    $feuille->setCellValue('D' . $ligne, $entree['action']);
    $feuille->setCellValue('E' . $ligne, $entree['table_concernee']);
    $feuille->setCellValue('F' . $ligne, $entree['id_enregistrement']);
    $feuille->setCellValue('G' . $ligne, $entree['details']);
    $ligne++;
}

foreach (range('A', 'G') as $colonne) {
    $feuille->getColumnDimension($colonne)->setAutoSize(true);
}
$feuille->freezePane('A2');

enregistrerAudit('EXPORT_EXCEL_AUDIT', 'journal_audit', null, 'Export Excel du journal d\'audit (' . count($entrees) . ' lignes)');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="journal_audit_' . date('Y-m-d_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
