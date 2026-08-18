<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerRole(['administrateur']);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

global $pdo;

$analystes = $pdo->query(
    "SELECT u.id_utilisateur, u.nom, u.prenom,
            COUNT(d.id_demande) AS nb_dossiers,
            SUM(CASE WHEN d.statut IN ('approuve','decaisse','solde') THEN 1 ELSE 0 END) AS nb_approuves,
            SUM(CASE WHEN d.statut = 'refuse' THEN 1 ELSE 0 END) AS nb_refuses,
            AVG(CASE WHEN d.date_decision IS NOT NULL THEN DATEDIFF(d.date_decision, d.date_demande) END) AS delai_moyen
     FROM utilisateurs u
     LEFT JOIN demandes_credit d ON d.charge_id = u.id_utilisateur
     WHERE u.role = 'charge_clientele'
     GROUP BY u.id_utilisateur, u.nom, u.prenom
     ORDER BY nb_dossiers DESC"
)->fetchAll();

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()->setTitle('Performance des analystes')->setCreator('Plateforme Crédit Bancaire');
$feuille = $spreadsheet->getActiveSheet();
$feuille->setTitle('Performance');

$entetes = ['A1' => 'Chargé de clientèle', 'B1' => 'Dossiers traités', 'C1' => 'Approuvés', 'D1' => 'Refusés', 'E1' => "Taux d'approbation", 'F1' => 'Délai moyen (jours)'];
foreach ($entetes as $cellule => $texte) {
    $feuille->setCellValue($cellule, $texte);
}
$feuille->getStyle('A1:F1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$feuille->getStyle('A1:F1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('1A2B4C');
$feuille->getStyle('A1:F1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$ligne = 2;
foreach ($analystes as $a) {
    $decisions = (int) $a['nb_approuves'] + (int) $a['nb_refuses'];
    $tauxApprobation = $decisions > 0 ? round((int) $a['nb_approuves'] / $decisions * 100, 1) : 0;

    $feuille->setCellValue('A' . $ligne, $a['prenom'] . ' ' . $a['nom']);
    $feuille->setCellValue('B' . $ligne, (int) $a['nb_dossiers']);
    $feuille->setCellValue('C' . $ligne, (int) $a['nb_approuves']);
    $feuille->setCellValue('D' . $ligne, (int) $a['nb_refuses']);
    $feuille->setCellValue('E' . $ligne, $tauxApprobation . ' %');
    $feuille->setCellValue('F' . $ligne, $a['delai_moyen'] !== null ? round((float) $a['delai_moyen'], 1) : '—');
    $ligne++;
}

foreach (range('A', 'F') as $colonne) {
    $feuille->getColumnDimension($colonne)->setAutoSize(true);
}
$feuille->freezePane('A2');

enregistrerAudit('EXPORT_PERFORMANCE_ANALYSTES', 'utilisateurs', null, 'Export Excel de la performance des analystes');

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="performance_analystes_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
