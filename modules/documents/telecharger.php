<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerConnexion();

global $pdo;

$idDocument = (int) ($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM documents WHERE id_document = :id');
$stmt->execute(['id' => $idDocument]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    die('Document introuvable.');
}

// Rétro-compatibilité : anciens documents liés uniquement à une demande, stockés
// sous storage/documents/{id_demande}/ (chemin déjà relatif à storage/).
$cheminComplet = __DIR__ . '/../../storage/' . $document['chemin_fichier'];
$cheminReel = realpath($cheminComplet);
$racinesAutorisees = array_filter([
    realpath(__DIR__ . '/../../storage/documents'),
    realpath(__DIR__ . '/../../storage/documents_ged'),
]);

$autorise = false;
foreach ($racinesAutorisees as $racine) {
    if ($cheminReel !== false && strpos($cheminReel, $racine) === 0) {
        $autorise = true;
        break;
    }
}

if (!$autorise || !is_file($cheminReel)) {
    http_response_code(404);
    die('Fichier introuvable.');
}

enregistrerAudit('TELECHARGEMENT_DOCUMENT_GED', 'documents', $idDocument, 'Téléchargement de "' . $document['nom_fichier'] . '"');

$extension = strtolower(pathinfo($cheminReel, PATHINFO_EXTENSION));
$typesMime = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

header('Content-Type: ' . ($typesMime[$extension] ?? 'application/octet-stream'));
header('Content-Disposition: inline; filename="' . basename($document['nom_fichier']) . '"');
header('Content-Length: ' . filesize($cheminReel));
readfile($cheminReel);
