<?php
/**
 * Configuration SMTP — utilisée par includes/Mailer.php (PHPMailer).
 * Copier ce fichier en config/mail.php et renseigner vos propres identifiants
 * (Gmail nécessite un mot de passe d'application, pas votre mot de passe habituel).
 */

return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls', // 'tls' (port 587) ou 'ssl' (port 465)
    'username'   => 'votre.email@gmail.com',
    'password'   => 'votre_mot_de_passe_application',
    'from_email' => 'votre.email@gmail.com',
    'from_name'  => 'Plateforme Crédit Bancaire',
];
