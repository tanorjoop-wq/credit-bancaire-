<?php
/**
 * Upload générique de document (Module 13 — GED), rattaché à un client,
 * une demande OU un contrat. Complète modules/demandes/document_upload.php
 * (conservé tel quel pour la compatibilité de l'existant) sans le remplacer.
 */
require_once __DIR__ . '/../../includes/auth.php';
exigerRole(['administrateur', 'charge_clientele']);

global $pdo;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rediriger(BASE_URL . '/modules/documents/liste.php');
}
verifierJetonCSRF();

$idClient = (int) ($_POST['id_client'] ?? 0) ?: null;
$idDemande = (int) ($_POST['id_demande'] ?? 0) ?: null;
$idContrat = (int) ($_POST['id_contrat'] ?? 0) ?: null;
$typeDocument = trim($_POST['type_document'] ?? '');
$dateExpiration = trim($_POST['date_expiration'] ?? '') ?: null;
$retour = trim($_POST['retour'] ?? '') ?: (BASE_URL . '/modules/documents/liste.php');

if (!$idClient && !$idDemande && !$idContrat) {
    rediriger($retour . (str_contains($retour, '?') ? '&' : '?') . 'erreur=' . urlencode('Aucune entité cible spécifiée.'));
}
if ($typeDocument === '') {
    rediriger($retour . (str_contains($retour, '?') ? '&' : '?') . 'erreur=' . urlencode('Veuillez préciser le type de document.'));
}
if (empty($_FILES['fichier']) || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK) {
    rediriger($retour . (str_contains($retour, '?') ? '&' : '?') . 'erreur=' . urlencode('Aucun fichier valide reçu.'));
}

$fichier = $_FILES['fichier'];
$extensionsAutorisees = ['pdf', 'jpg', 'jpeg', 'png'];
$tailleMaxOctets = 5 * 1024 * 1024;

$extension = strtolower(pathinfo($fichier['name'], PATHINFO_EXTENSION));
if (!in_array($extension, $extensionsAutorisees, true) || $fichier['size'] > $tailleMaxOctets) {
    rediriger($retour . (str_contains($retour, '?') ? '&' : '?') . 'erreur=' . urlencode('Format non autorisé (PDF/JPG/PNG) ou fichier trop volumineux (5 Mo max).'));
}

$mimesAutorises = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeReel = finfo_file($finfo, $fichier['tmp_name']);
finfo_close($finfo);
if ($mimeReel !== $mimesAutorises[$extension]) {
    rediriger($retour . (str_contains($retour, '?') ? '&' : '?') . 'erreur=' . urlencode('Le contenu du fichier ne correspond pas à son extension.'));
}

// Version : incrémentée si un document du même type existe déjà pour la même entité
$condEntite = $idClient ? 'id_client = :ent' : ($idDemande ? 'id_demande = :ent' : 'id_contrat = :ent');
$entiteId = $idClient ?: ($idDemande ?: $idContrat);
$stmtVersion = $pdo->prepare("SELECT COALESCE(MAX(version), 0) FROM documents WHERE $condEntite AND type_document = :type");
$stmtVersion->execute(['ent' => $entiteId, 'type' => $typeDocument]);
$nouvelleVersion = (int) $stmtVersion->fetchColumn() + 1;

$sousDossier = $idClient ? "client_$idClient" : ($idDemande ? "demande_$idDemande" : "contrat_$idContrat");
$dossier = __DIR__ . '/../../storage/documents_ged/' . $sousDossier;
if (!is_dir($dossier)) {
    mkdir($dossier, 0775, true);
}
$nomFichierStocke = bin2hex(random_bytes(16)) . '.' . $extension;
move_uploaded_file($fichier['tmp_name'], $dossier . '/' . $nomFichierStocke);

$statutValidation = ($dateExpiration && strtotime($dateExpiration) < time()) ? 'expire' : 'valide';

$stmt = $pdo->prepare(
    'INSERT INTO documents (id_demande, id_client, id_contrat, type_document, nom_fichier, chemin_fichier, statut_validation, date_expiration, version, uploade_par)
     VALUES (:demande, :client, :contrat, :type, :nom, :chemin, :statut, :expiration, :version, :par)'
);
$stmt->execute([
    'demande' => $idDemande, 'client' => $idClient, 'contrat' => $idContrat,
    'type' => $typeDocument, 'nom' => basename($fichier['name']),
    'chemin' => 'documents_ged/' . $sousDossier . '/' . $nomFichierStocke,
    'statut' => $statutValidation, 'expiration' => $dateExpiration, 'version' => $nouvelleVersion,
    'par' => $_SESSION['id_utilisateur'],
]);

enregistrerAudit('AJOUT_DOCUMENT_GED', 'documents', (int) $pdo->lastInsertId(), "Dépôt document \"$typeDocument\" (v$nouvelleVersion)");

rediriger($retour . (str_contains($retour, '?') ? '&' : '?') . 'succes=' . urlencode('Document déposé avec succès.'));
