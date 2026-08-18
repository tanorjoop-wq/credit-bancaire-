<?php
/**
 * Fonctions utilitaires transverses au projet.
 */

/**
 * Échappe une chaîne pour affichage HTML (protection XSS).
 */
function e(?string $valeur): string
{
    return htmlspecialchars($valeur ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Génère un jeton CSRF et le stocke en session s'il n'existe pas déjà.
 */
function genererJetonCSRF(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Vérifie le jeton CSRF soumis par un formulaire.
 * Arrête l'exécution si le jeton est invalide ou absent.
 */
function verifierJetonCSRF(): void
{
    $jeton = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $jeton)) {
        http_response_code(403);
        die('Jeton de sécurité invalide. Rechargez la page et réessayez.');
    }
}

/**
 * Formate un montant en FCFA avec séparateur de milliers.
 */
function formaterMontant($montant): string
{
    return number_format((float) $montant, 0, ',', ' ') . ' FCFA';
}

/**
 * Redirige vers une URL et arrête l'exécution.
 */
function rediriger(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Détecte les échéances impayées : passe en 'en_retard' toute échéance à venir
 * dont la date est dépassée, puis en 'impayee' si le retard dépasse 30 jours.
 * Appelée en lecture sur les pages du module contrats (idempotente).
 */
function detecterEcheancesImpayees(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE echeancier SET statut = 'en_retard'
         WHERE statut = 'a_venir' AND date_echeance < CURDATE()"
    );
    $pdo->exec(
        "UPDATE echeancier SET statut = 'impayee'
         WHERE statut = 'en_retard' AND date_echeance < DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
    );
}

/**
 * Journal d'audit avec traçabilité avant/après (Module 16), pour les mutations
 * à forte valeur explicitement identifiées (restructuration, décision comité,
 * paiement, recalcul de scoring) — pas systématisé sur toute l'application.
 */
function audit_avant_apres(PDO $pdo, string $action, string $table, ?int $idEnregistrement, string $ancienneValeur, string $nouvelleValeur): void
{
    $idUtilisateur = $_SESSION['id_utilisateur'] ?? null;
    if ($idUtilisateur === null) {
        return;
    }
    $pdo->prepare(
        'INSERT INTO journal_audit (id_utilisateur, action, table_concernee, id_enregistrement, details, ancienne_valeur, nouvelle_valeur)
         VALUES (:u, :a, :t, :id, :d, :av, :nv)'
    )->execute([
        'u' => $idUtilisateur, 'a' => $action, 't' => $table, 'id' => $idEnregistrement,
        'd' => "$ancienneValeur → $nouvelleValeur", 'av' => $ancienneValeur, 'nv' => $nouvelleValeur,
    ]);
}

/**
 * Fait passer en statut 'expire' tout document dont la date d'expiration
 * est dépassée (Module 13 — GED). Appelée en lecture, idempotente.
 */
function detecterDocumentsExpires(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE documents SET statut_validation = 'expire'
         WHERE date_expiration IS NOT NULL AND date_expiration < CURDATE() AND statut_validation != 'expire'"
    );
}

/**
 * Crée une notification pour un utilisateur (Module 14 — Notification Center).
 * Appelée depuis les points de mutation clés (paiement, restructuration, décision comité...).
 */
function creerNotification(PDO $pdo, int $idUtilisateur, string $niveau, string $titre, string $message, ?string $lienCible = null): void
{
    $pdo->prepare(
        'INSERT INTO notifications (id_utilisateur_destinataire, niveau, titre, message, lien_cible)
         VALUES (:id, :niveau, :titre, :message, :lien)'
    )->execute([
        'id' => $idUtilisateur, 'niveau' => $niveau, 'titre' => $titre, 'message' => $message, 'lien' => $lienCible,
    ]);
}

/**
 * Notifie tous les utilisateurs d'un rôle donné (ex : tous les comite_direction
 * quand un dossier arrive en file d'attente).
 */
function notifierRole(PDO $pdo, string $role, string $niveau, string $titre, string $message, ?string $lienCible = null): void
{
    $ids = $pdo->prepare('SELECT id_utilisateur FROM utilisateurs WHERE role = :role AND actif = 1');
    $ids->execute(['role' => $role]);
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $idUtilisateur) {
        creerNotification($pdo, (int) $idUtilisateur, $niveau, $titre, $message, $lienCible);
    }
}
