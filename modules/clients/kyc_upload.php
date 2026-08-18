<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idClient = (int) ($_POST['id_client'] ?? 0);
$type = $_POST['type'] ?? '';

$stmt = $pdo->prepare('SELECT id_client FROM clients WHERE id_client = :id');
$stmt->execute(['id' => $idClient]);
if (!$stmt->fetch()) {
    http_response_code(404);
    die('Client introuvable.');
}

$dossierClient = __DIR__ . '/../../storage/clients/' . $idClient;
if (!is_dir($dossierClient)) {
    mkdir($dossierClient, 0775, true);
}

if ($type === 'photo') {
    if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
        rediriger('voir.php?id=' . $idClient . '&erreur=' . urlencode('Aucune photo valide reçue.'));
    }

    $fichier = $_FILES['fichier'];
    $extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
    $mimesAutorises = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    if (!isset($mimesAutorises[$extension]) || $fichier['size'] > 3 * 1024 * 1024) {
        rediriger('voir.php?id=' . $idClient . '&erreur=' . urlencode('Format non autorisé ou fichier trop volumineux (JPG/PNG, 3 Mo max).'));
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeReel = finfo_file($finfo, $fichier['tmp_name']);
    finfo_close($finfo);
    if ($mimeReel !== $mimesAutorises[$extension]) {
        rediriger('voir.php?id=' . $idClient . '&erreur=' . urlencode('Le contenu du fichier ne correspond pas à son extension.'));
    }

    $nomFichier = 'photo_' . bin2hex(random_bytes(8)) . '.' . $extension;
    move_uploaded_file($fichier['tmp_name'], $dossierClient . '/' . $nomFichier);

    $chemin = 'clients/' . $idClient . '/' . $nomFichier;
    $pdo->prepare('UPDATE clients SET photo_path = :chemin WHERE id_client = :id')
        ->execute(['chemin' => $chemin, 'id' => $idClient]);

    enregistrerAudit('MAJ_PHOTO_CLIENT', 'clients', $idClient, 'Mise à jour de la photo KYC');
    rediriger('voir.php?id=' . $idClient . '&succes=' . urlencode('Photo mise à jour.'));
} elseif ($type === 'signature') {
    $donnees = $_POST['signature_data'] ?? '';
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $donnees, $matches)) {
        rediriger('voir.php?id=' . $idClient . '&erreur=' . urlencode('Signature invalide.'));
    }

    $binaire = base64_decode($matches[1], true);
    if ($binaire === false || strlen($binaire) > 500 * 1024) {
        rediriger('voir.php?id=' . $idClient . '&erreur=' . urlencode('Signature invalide ou trop volumineuse.'));
    }

    $nomFichier = 'signature_' . bin2hex(random_bytes(8)) . '.png';
    file_put_contents($dossierClient . '/' . $nomFichier, $binaire);

    $chemin = 'clients/' . $idClient . '/' . $nomFichier;
    $pdo->prepare('UPDATE clients SET signature_path = :chemin WHERE id_client = :id')
        ->execute(['chemin' => $chemin, 'id' => $idClient]);

    enregistrerAudit('MAJ_SIGNATURE_CLIENT', 'clients', $idClient, 'Enregistrement de la signature');
    rediriger('voir.php?id=' . $idClient . '&succes=' . urlencode('Signature enregistrée.'));
} else {
    rediriger('voir.php?id=' . $idClient);
}
