<?php
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger('liste.php');
}
verifierJetonCSRF();

$idDemande = (int) ($_POST['id_demande'] ?? 0);
$typeDocument = trim($_POST['type_document'] ?? '');

$stmt = $pdo->prepare('SELECT id_demande, reference FROM demandes_credit WHERE id_demande = :id');
$stmt->execute(['id' => $idDemande]);
$demande = $stmt->fetch();

if (!$demande) {
    http_response_code(404);
    die('Demande introuvable.');
}

if ($typeDocument === '') {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Veuillez préciser le type de document.'));
}

if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Aucun fichier valide reçu.'));
}

$fichier = $_FILES['fichier'];

// --- Validation stricte du fichier ---
$extensionsAutorisees = ['pdf', 'jpg', 'jpeg', 'png'];
$tailleMaxOctets = 5 * 1024 * 1024; // 5 Mo

$extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $extensionsAutorisees, true)) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Format non autorisé (PDF, JPG ou PNG uniquement).'));
}
if ($fichier['size'] > $tailleMaxOctets) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Fichier trop volumineux (5 Mo maximum).'));
}

// Vérifie le type MIME réel du fichier (pas seulement l'extension déclarée)
$mimesAutorises = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeReel = finfo_file($finfo, $fichier['tmp_name']);
finfo_close($finfo);
if ($mimeReel !== $mimesAutorises[$extension]) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Le contenu du fichier ne correspond pas à son extension.'));
}

// --- Stockage hors racine web servable directement (protégé par storage/.htaccess) ---
$dossierDemande = __DIR__ . '/../../storage/documents/' . $idDemande;
if (!is_dir($dossierDemande)) {
    mkdir($dossierDemande, 0775, true);
}

$nomFichierStocke = bin2hex(random_bytes(16)) . '.' . $extension;
$cheminComplet = $dossierDemande . '/' . $nomFichierStocke;

if (!move_uploaded_file($fichier['tmp_name'], $cheminComplet)) {
    rediriger('voir.php?id=' . $idDemande . '&erreur=' . urlencode('Échec de l\'enregistrement du fichier.'));
}

$stmtInsert = $pdo->prepare(
    'INSERT INTO documents (id_demande, type_document, nom_fichier, chemin_fichier, uploade_par)
     VALUES (:id_demande, :type_document, :nom_fichier, :chemin_fichier, :uploade_par)'
);
$stmtInsert->execute([
    'id_demande'     => $idDemande,
    'type_document'  => $typeDocument,
    'nom_fichier'    => basename($fichier['name']),
    'chemin_fichier' => 'documents/' . $idDemande . '/' . $nomFichierStocke,
    'uploade_par'    => $_SESSION['id_utilisateur'],
]);

enregistrerAudit('AJOUT_DOCUMENT', 'documents', (int) $pdo->lastInsertId(), 'Ajout du document "' . $typeDocument . '" sur la demande ' . $demande['reference']);

rediriger('voir.php?id=' . $idDemande . '&succes=' . urlencode('Document ajouté avec succès.'));
