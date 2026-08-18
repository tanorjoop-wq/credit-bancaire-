<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../vendor/autoload.php';
exigerConnexion();

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

global $pdo;

$stmt = $pdo->query(
    "SELECT c.type_client, c.nom_raison_sociale, c.prenom, c.numero_piece, c.telephone, c.email,
            c.adresse, c.revenu_mensuel, c.chiffre_affaires, c.anciennete_bancaire_mois,
            c.date_creation, u.nom AS cree_par_nom, u.prenom AS cree_par_prenom
     FROM clients c
     JOIN utilisateurs u ON u.id_utilisateur = c.cree_par
     ORDER BY c.id_client DESC"
);
$clients = $stmt->fetchAll();

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setTitle('Liste des clients')
    ->setCreator('Plateforme Crédit Bancaire');

$feuille = $spreadsheet->getActiveSheet();
$feuille->setTitle('Clients');

$entetes = [
    'A1' => 'Type', 'B1' => 'Nom / Raison sociale', 'C1' => 'Prénom', 'D1' => 'N° pièce (CNI/NINEA)',
    'E1' => 'Téléphone', 'F1' => 'Email', 'G1' => 'Adresse', 'H1' => 'Revenu mensuel (FCFA)',
    'I1' => "Chiffre d'affaires (FCFA)", 'J1' => 'Ancienneté (mois)', 'K1' => 'Date de création', 'L1' => 'Créé par',
];
foreach ($entetes as $cellule => $texte) {
    $feuille->setCellValue($cellule, $texte);
}
$feuille->getStyle('A1:L1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
$feuille->getStyle('A1:L1')->getFill()
    ->setFillType(Fill::FILL_SOLID)
    ->getStartColor()->setRGB('1A2B4C');
$feuille->getStyle('A1:L1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

$ligne = 2;
foreach ($clients as $client) {
    $feuille->setCellValue('A' . $ligne, ucfirst($client['type_client']));
    $feuille->setCellValue('B' . $ligne, $client['nom_raison_sociale']);
    $feuille->setCellValue('C' . $ligne, $client['prenom'] ?? '');
    $feuille->setCellValue('D' . $ligne, $client['numero_piece']);
    $feuille->setCellValue('E' . $ligne, $client['telephone']);
    $feuille->setCellValue('F' . $ligne, $client['email'] ?? '');
    $feuille->setCellValue('G' . $ligne, $client['adresse'] ?? '');
    $feuille->setCellValue('H' . $ligne, $client['revenu_mensuel'] !== null ? (float) $client['revenu_mensuel'] : null);
    $feuille->setCellValue('I' . $ligne, $client['chiffre_affaires'] !== null ? (float) $client['chiffre_affaires'] : null);
    $feuille->setCellValue('J' . $ligne, (int) $client['anciennete_bancaire_mois']);
    $feuille->setCellValue('K' . $ligne, date('d/m/Y', strtotime($client['date_creation'])));
    $feuille->setCellValue('L' . $ligne, $client['cree_par_prenom'] . ' ' . $client['cree_par_nom']);
    $ligne++;
}

$feuille->getStyle('H2:I' . ($ligne - 1))->getNumberFormat()->setFormatCode('#,##0 "FCFA"');

foreach (range('A', 'L') as $colonne) {
    $feuille->getColumnDimension($colonne)->setAutoSize(true);
}
$feuille->freezePane('A2');

enregistrerAudit('EXPORT_EXCEL_CLIENTS', 'clients', null, 'Export Excel de la liste des clients (' . count($clients) . ' lignes)');

$nomFichier = 'liste_clients_' . date('Y-m-d_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
