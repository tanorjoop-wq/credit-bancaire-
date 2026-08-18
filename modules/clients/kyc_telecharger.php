<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$idClient = (int) ($_GET['id'] ?? 0);
$type = $_GET['type'] ?? '';

$colonne = $type === 'photo' ? 'photo_path' : ($type === 'signature' ? 'signature_path' : null);
if ($colonne === null) {
    http_response_code(404);
    die('Type invalide.');
}

$stmt = $pdo->prepare("SELECT $colonne AS chemin FROM clients WHERE id_client = :id");
$stmt->execute(['id' => $idClient]);
$chemin = $stmt->fetchColumn();

if (!$chemin) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$cheminComplet = __DIR__ . '/../../storage/' . $chemin;
$cheminReel = realpath($cheminComplet);
$racineAutorisee = realpath(__DIR__ . '/../../storage/clients');

if ($cheminReel === false || strpos($cheminReel, $racineAutorisee) !== 0 || !is_file($cheminReel)) {
    http_response_code(404);
    die('Fichier introuvable.');
}

$extension = strtolower(pathinfo($cheminReel, PATHINFO_EXTENSION));
$typesMime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

header('Content-Type: ' . ($typesMime[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($cheminReel));
readfile($cheminReel);
