<?php
/**
 * Connexion PDO à la base MySQL — Projet Crédit Bancaire
 * Adapter les identifiants ci-dessous à votre environnement XAMPP.
 */

$DB_HOST = 'localhost';
$DB_NAME = 'credit_bancaire';
$DB_USER = 'root';
$DB_PASS = '';       // vide par défaut sur XAMPP
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // remonte les erreurs SQL
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // résultats en tableaux associatifs
    PDO::ATTR_EMULATE_PREPARES   => false,                    // vraies requêtes préparées côté MySQL
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // En production, ne jamais afficher le message d'erreur brut à l'utilisateur
    die('Erreur de connexion à la base de données : ' . $e->getMessage());
}
