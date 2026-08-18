<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Enveloppe OOP autour de PHPMailer pour les notifications automatiques
 * de la plateforme (ex : confirmation de décaissement).
 */
class Mailer
{
    private array $config;
    private string $derniereErreur = '';

    public function __construct()
    {
        $this->config = require __DIR__ . '/../config/mail.php';
    }

    /**
     * Envoie un email. Retourne true si l'envoi a réussi, false sinon
     * (l'erreur est stockée dans $this->derniereErreur).
     */
    public function envoyer(string $destinataire, string $nomDestinataire, string $sujet, string $corpsHtml): bool
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $this->config['host'];
            $mail->SMTPAuth   = true;
            $mail->Username   = $this->config['username'];
            $mail->Password   = $this->config['password'];
            $mail->SMTPSecure = $this->config['encryption'];
            $mail->Port       = $this->config['port'];
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->addAddress($destinataire, $nomDestinataire);

            $mail->isHTML(true);
            $mail->Subject = $sujet;
            $mail->Body    = $corpsHtml;
            $mail->AltBody = strip_tags($corpsHtml);

            $mail->send();
            return true;
        } catch (PHPMailerException $e) {
            $this->derniereErreur = $mail->ErrorInfo;
            return false;
        }
    }

    public function getDerniereErreur(): string
    {
        return $this->derniereErreur;
    }

    /**
     * Notification de décaissement envoyée au client.
     */
    public function envoyerNotificationDecaissement(
        string $emailClient,
        string $nomClient,
        string $numeroContrat,
        float $montant,
        string $dateDecaissement
    ): bool {
        $sujet = 'Décaissement de votre crédit — Contrat ' . $numeroContrat;
        $corps = "
            <p>Bonjour {$nomClient},</p>
            <p>Nous vous confirmons le <strong>décaissement</strong> de votre crédit
               (contrat <strong>{$numeroContrat}</strong>) d'un montant de
               <strong>" . number_format($montant, 0, ',', ' ') . " FCFA</strong>,
               effectué le " . date('d/m/Y', strtotime($dateDecaissement)) . ".</p>
            <p>Cordialement,<br>Plateforme Crédit Bancaire</p>
        ";

        return $this->envoyer($emailClient, $nomClient, $sujet, $corps);
    }
}
