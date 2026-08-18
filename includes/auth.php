<?php
/**
 * Authentification, session sécurisée et contrôle d'accès par rôle.
 * Rôles gérés : administrateur, charge_clientele, comite_direction.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

// Préfixe de déploiement détecté dynamiquement (racine '' ou sous-dossier
// '/credit_bancaire') — élimine tout chemin absolu durci dans le reste du code.
if (!defined('BASE_URL')) {
    define('BASE_URL', preg_replace('#/(public|modules)/.*$#', '', $_SERVER['SCRIPT_NAME'] ?? ''));
}

// Durée maximale d'inactivité avant expiration de session (en secondes)
const DUREE_INACTIVITE_MAX = 20 * 60; // 20 minutes

// Paramètres de cookie de session sécurisés — à définir AVANT session_start()
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,   // inaccessible en JavaScript (anti-vol de session via XSS)
    'samesite' => 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Vérifie l'expiration de session par inactivité et la met à jour.
 * Doit être appelée sur chaque page protégée.
 */
function verifierExpirationSession(): void
{
    if (isset($_SESSION['derniere_activite'])
        && (time() - $_SESSION['derniere_activite']) > DUREE_INACTIVITE_MAX) {
        session_unset();
        session_destroy();
        rediriger(BASE_URL . '/public/login.php?expire=1');
    }
    $_SESSION['derniere_activite'] = time();
}

/**
 * Tente de connecter un utilisateur à partir de son email et mot de passe.
 * Retourne true si succès, false sinon.
 */
function connecterUtilisateur(string $email, string $motDePasse): bool
{
    global $pdo;

    $stmt = $pdo->prepare(
        'SELECT id_utilisateur, nom, prenom, email, mot_de_passe_hash, role, actif
         FROM utilisateurs WHERE email = :email LIMIT 1'
    );
    $stmt->execute(['email' => $email]);
    $utilisateur = $stmt->fetch();

    if (!$utilisateur || !$utilisateur['actif']) {
        return false;
    }

    // Vérification du hash — jamais de comparaison directe de mots de passe
    if (!password_verify($motDePasse, $utilisateur['mot_de_passe_hash'])) {
        return false;
    }

    // Régénère l'identifiant de session (anti fixation de session)
    session_regenerate_id(true);

    $_SESSION['id_utilisateur']     = $utilisateur['id_utilisateur'];
    $_SESSION['nom_complet']        = $utilisateur['prenom'] . ' ' . $utilisateur['nom'];
    $_SESSION['role']               = $utilisateur['role'];
    $_SESSION['derniere_activite']  = time();

    // Mise à jour de la date de dernière connexion
    $maj = $pdo->prepare('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id_utilisateur = :id');
    $maj->execute(['id' => $utilisateur['id_utilisateur']]);

    enregistrerAudit('CONNEXION', 'utilisateurs', $utilisateur['id_utilisateur'], 'Connexion réussie');

    return true;
}

/**
 * Déconnecte l'utilisateur courant et détruit la session.
 */
function deconnecterUtilisateur(): void
{
    if (isset($_SESSION['id_utilisateur'])) {
        enregistrerAudit('DECONNEXION', 'utilisateurs', $_SESSION['id_utilisateur'], 'Déconnexion');
    }
    session_unset();
    session_destroy();
}

/**
 * Bloque l'accès à la page si l'utilisateur n'est pas authentifié.
 */
function exigerConnexion(): void
{
    if (empty($_SESSION['id_utilisateur'])) {
        rediriger(BASE_URL . '/public/login.php');
    }
    verifierExpirationSession();
}

/**
 * Bloque l'accès à la page si l'utilisateur connecté n'a pas l'un des
 * rôles autorisés. Exemple d'utilisation :
 *   exigerRole(['administrateur', 'comite_direction']);
 *
 * @param string[] $rolesAutorises
 */
function exigerRole(array $rolesAutorises): void
{
    exigerConnexion();

    if (!in_array($_SESSION['role'], $rolesAutorises, true)) {
        http_response_code(403);
        die('Accès refusé : vous n\'avez pas les droits nécessaires pour cette page.');
    }
}

/**
 * Enregistre une action sensible dans le journal d'audit.
 */
function enregistrerAudit(string $action, string $table, ?int $idEnregistrement, string $details = ''): void
{
    global $pdo;

    $idUtilisateur = $_SESSION['id_utilisateur'] ?? null;
    if ($idUtilisateur === null) {
        return; // pas d'audit pour une action non authentifiée (ex: tentative de connexion échouée)
    }

    $stmt = $pdo->prepare(
        'INSERT INTO journal_audit (id_utilisateur, action, table_concernee, id_enregistrement, details)
         VALUES (:id_utilisateur, :action, :table_concernee, :id_enregistrement, :details)'
    );
    $stmt->execute([
        'id_utilisateur'    => $idUtilisateur,
        'action'            => $action,
        'table_concernee'   => $table,
        'id_enregistrement' => $idEnregistrement,
        'details'           => $details,
    ]);
}
