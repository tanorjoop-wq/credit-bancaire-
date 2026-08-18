<?php
/**
 * Moteur de scoring — évalue une demande de crédit et produit une note sur 100,
 * un grade (A à E) et une probabilité de défaut estimée.
 *
 * Barème (100 points) :
 *   - Taux d'endettement post-crédit ......... 40 pts
 *   - Ancienneté bancaire du client ........... 20 pts
 *   - Couverture par garanties ................ 25 pts
 *   - Capacité de remboursement résiduelle .... 15 pts
 */
class MoteurScoring
{
    private const POIDS_ENDETTEMENT  = 40;
    private const POIDS_ANCIENNETE   = 20;
    private const POIDS_GARANTIES    = 25;
    private const POIDS_CAPACITE     = 15;

    private const ANCIENNETE_MAX_MOIS = 60; // 5 ans = score d'ancienneté maximal

    /**
     * Calcule l'échéance mensuelle d'un crédit (annuité constante).
     */
    public function calculerEcheanceMensuelle(float $montant, int $dureeMois, float $tauxAnnuel): float
    {
        if ($dureeMois <= 0) {
            return 0.0;
        }

        $tauxMensuel = $tauxAnnuel / 100 / 12;

        if ($tauxMensuel == 0.0) {
            return $montant / $dureeMois;
        }

        return $montant * $tauxMensuel / (1 - (1 + $tauxMensuel) ** (-$dureeMois));
    }

    /**
     * Évalue une demande de crédit à partir des données du client et de la demande.
     *
     * @param array $client  Ligne de la table clients
     * @param array $demande Ligne de la table demandes_credit
     * @param float $valeurGaranties Somme des garanties associées à la demande
     * @return array{echeance_mensuelle:float,capacite_remboursement:float,taux_endettement:float,
     *               score_total:float,grade:string,probabilite_defaut:float}
     */
    public function evaluer(array $client, array $demande, float $valeurGaranties = 0.0): array
    {
        $revenuMensuel = $client['type_client'] === 'entreprise'
            ? ((float) ($client['chiffre_affaires'] ?? 0) / 12)
            : (float) ($client['revenu_mensuel'] ?? 0);

        $echeance = $this->calculerEcheanceMensuelle(
            (float) $demande['montant_demande'],
            (int) $demande['duree_mois'],
            (float) $demande['taux_interet_propose']
        );

        $tauxEndettement = $revenuMensuel > 0 ? ($echeance / $revenuMensuel) * 100 : 100.0;
        $capaciteRemboursement = $revenuMensuel - $echeance;

        $anciennete = (int) ($client['anciennete_bancaire_mois'] ?? 0);

        $points = $this->noterEndettement($tauxEndettement)
            + $this->noterAnciennete($anciennete)
            + $this->noterGaranties($valeurGaranties, (float) $demande['montant_demande'])
            + $this->noterCapacite($capaciteRemboursement, $echeance);

        $scoreTotal = round(min(100, max(0, $points)), 2);
        $grade = $this->determinerGrade($scoreTotal);
        $probabiliteDefaut = $this->estimerProbabiliteDefaut($scoreTotal);

        return [
            'echeance_mensuelle'      => round($echeance, 2),
            'capacite_remboursement'  => round($capaciteRemboursement, 2),
            'taux_endettement'        => round(min(999.99, $tauxEndettement), 2),
            'score_total'             => $scoreTotal,
            'grade'                   => $grade,
            'probabilite_defaut'      => $probabiliteDefaut,
        ];
    }

    /**
     * Règle bancaire classique UEMOA : taux d'endettement max recommandé 33%.
     */
    private function noterEndettement(float $tauxEndettement): float
    {
        if ($tauxEndettement <= 33) {
            return self::POIDS_ENDETTEMENT;
        }
        if ($tauxEndettement <= 40) {
            return self::POIDS_ENDETTEMENT * 0.6;
        }
        if ($tauxEndettement <= 50)
        {
            return self::POIDS_ENDETTEMENT * 0.25;
        }
        return 0.0;
    }

    private function noterAnciennete(int $mois): float
    {
        return min(1, $mois / self::ANCIENNETE_MAX_MOIS) * self::POIDS_ANCIENNETE;
    }

    private function noterGaranties(float $valeurGaranties, float $montantDemande): float
    {
        if ($montantDemande <= 0) {
            return 0.0;
        }
        $couverture = $valeurGaranties / $montantDemande;
        return min(1, $couverture) * self::POIDS_GARANTIES;
    }

    private function noterCapacite(float $capacite, float $echeance): float
    {
        if ($capacite <= 0 || $echeance <= 0) {
            return 0.0;
        }
        // Multiple de l'échéance que représente la capacité résiduelle (plafonné à 3x)
        $multiple = min(3, $capacite / $echeance);
        return ($multiple / 3) * self::POIDS_CAPACITE;
    }

    private function determinerGrade(float $score): string
    {
        return match (true) {
            $score >= 85 => 'A',
            $score >= 70 => 'B',
            $score >= 55 => 'C',
            $score >= 40 => 'D',
            default      => 'E',
        };
    }

    private function estimerProbabiliteDefaut(float $score): float
    {
        // PD décroît de façon non linéaire avec le score (plus réaliste qu'une relation linéaire)
        $pd = 50 * (1 - $score / 100) ** 2;
        return round(max(1.0, min(50.0, $pd)), 2);
    }
}
