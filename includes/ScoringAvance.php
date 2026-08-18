<?php
/**
 * Scoring multidimensionnel explicable — couche additive au-dessus du
 * MoteurScoring de base. Combine trois dimensions (35% financier, 25%
 * patrimonial, 40% comportemental) en une note globale A+ à F, avec les
 * 3 facteurs positifs et 3 facteurs de risque les plus déterminants
 * (règles de seuils explicites — pas de boîte noire).
 */
class MoteurScoringAvance
{
    // Valeurs par défaut (utilisées si aucun PDO n'est fourni, ou si la table
    // parametres_scoring — Module 15/Administration — est vide/inaccessible).
    private const POIDS_FINANCIER_DEFAUT      = 0.35;
    private const POIDS_PATRIMONIAL_DEFAUT    = 0.25;
    private const POIDS_COMPORTEMENTAL_DEFAUT = 0.40;

    private const POINTS_COULEUR = ['vert' => 100, 'orange' => 55, 'rouge' => 15];

    private float $poidsFinancier;
    private float $poidsPatrimonial;
    private float $poidsComportemental;

    public function __construct(?PDO $pdo = null)
    {
        $this->poidsFinancier = self::POIDS_FINANCIER_DEFAUT;
        $this->poidsPatrimonial = self::POIDS_PATRIMONIAL_DEFAUT;
        $this->poidsComportemental = self::POIDS_COMPORTEMENTAL_DEFAUT;

        if ($pdo !== null) {
            try {
                $params = $pdo->query(
                    "SELECT cle, valeur FROM parametres_scoring WHERE cle IN ('poids_financier', 'poids_patrimonial', 'poids_comportemental')"
                )->fetchAll(PDO::FETCH_KEY_PAIR);
                $this->poidsFinancier = isset($params['poids_financier']) ? (float) $params['poids_financier'] : self::POIDS_FINANCIER_DEFAUT;
                $this->poidsPatrimonial = isset($params['poids_patrimonial']) ? (float) $params['poids_patrimonial'] : self::POIDS_PATRIMONIAL_DEFAUT;
                $this->poidsComportemental = isset($params['poids_comportemental']) ? (float) $params['poids_comportemental'] : self::POIDS_COMPORTEMENTAL_DEFAUT;
            } catch (PDOException $e) {
                // Table absente (installation antérieure à la migration v3) : on garde les valeurs par défaut.
            }
        }
    }

    /**
     * @param array $ratios Sortie normalisée d'AnalyseFinanciere :
     *   ['couleur_dscr', 'couleur_endettement', 'couleur_liquidite', 'dscr', 'taux_endettement']
     */
    public function evaluer(
        array $ratios,
        float $patrimoineNet,
        float $montantDemande,
        ?float $tauxPaiementATemps,
        int $anciennete
    ): array {
        $scoreFinancier = $this->calculerScoreFinancier($ratios);
        $scorePatrimonial = $this->calculerScorePatrimonial($patrimoineNet, $montantDemande);
        $scoreComportemental = $this->calculerScoreComportemental($tauxPaiementATemps, $anciennete);

        $scoreGlobal = round(
            $scoreFinancier * $this->poidsFinancier
            + $scorePatrimonial * $this->poidsPatrimonial
            + $scoreComportemental * $this->poidsComportemental,
            2
        );

        [$facteursPositifs, $facteursRisque] = $this->genererExplications(
            $ratios,
            $patrimoineNet,
            $montantDemande,
            $tauxPaiementATemps,
            $anciennete
        );

        return [
            'score_financier'      => $scoreFinancier,
            'score_patrimonial'    => $scorePatrimonial,
            'score_comportemental' => $scoreComportemental,
            'score_global'         => $scoreGlobal,
            'note_globale'         => $this->determinerNote($scoreGlobal),
            'facteurs_positifs'    => $facteursPositifs,
            'facteurs_risque'      => $facteursRisque,
        ];
    }

    private function calculerScoreFinancier(array $ratios): float
    {
        $pointsDscr = self::POINTS_COULEUR[$ratios['couleur_dscr']] ?? 55;
        $pointsEndettement = self::POINTS_COULEUR[$ratios['couleur_endettement']] ?? 55;
        $pointsLiquidite = self::POINTS_COULEUR[$ratios['couleur_liquidite']] ?? 55;

        return round($pointsDscr * 0.4 + $pointsEndettement * 0.3 + $pointsLiquidite * 0.3, 2);
    }

    private function calculerScorePatrimonial(float $patrimoineNet, float $montantDemande): float
    {
        if ($montantDemande <= 0) {
            return 10.0;
        }
        $couverture = $patrimoineNet / $montantDemande;

        return match (true) {
            $couverture >= 1.5 => 100.0,
            $couverture >= 1.0 => 80.0,
            $couverture >= 0.5 => 55.0,
            $couverture > 0    => 25.0,
            default            => 10.0,
        };
    }

    private function calculerScoreComportemental(?float $tauxPaiementATemps, int $anciennete): float
    {
        $scoreAnciennete = min(1, $anciennete / 60) * 50;
        $scorePaiement = $tauxPaiementATemps !== null ? $tauxPaiementATemps * 50 : 25.0;

        return round(min(100, $scoreAnciennete + $scorePaiement), 2);
    }

    private function determinerNote(float $score): string
    {
        return match (true) {
            $score >= 90 => 'A+',
            $score >= 80 => 'A',
            $score >= 70 => 'B+',
            $score >= 60 => 'B',
            $score >= 50 => 'C+',
            $score >= 40 => 'C',
            $score >= 30 => 'D',
            $score >= 20 => 'E',
            default      => 'F',
        };
    }

    /**
     * @return array{0: string[], 1: string[]} [facteurs positifs (max 3), facteurs de risque (max 3)]
     */
    private function genererExplications(
        array $ratios,
        float $patrimoineNet,
        float $montantDemande,
        ?float $tauxPaiementATemps,
        int $anciennete
    ): array {
        $positifs = [];
        $risques = [];

        if ($ratios['couleur_dscr'] === 'vert') {
            $positifs[] = ['force' => 3, 'texte' => 'DSCR confortable (' . number_format($ratios['dscr'] ?? 0, 2) . ') : capacité de remboursement solide'];
        } elseif ($ratios['couleur_dscr'] === 'rouge') {
            $risques[] = ['force' => 3, 'texte' => 'DSCR insuffisant (' . ($ratios['dscr'] !== null ? number_format($ratios['dscr'], 2) : '—') . ') : capacité de remboursement fragile'];
        }

        if ($ratios['couleur_endettement'] === 'vert') {
            $positifs[] = ['force' => 2, 'texte' => 'Taux d\'endettement maîtrisé (' . number_format($ratios['taux_endettement'] ?? 0, 1) . ' %)'];
        } elseif ($ratios['couleur_endettement'] === 'rouge') {
            $risques[] = ['force' => 2, 'texte' => 'Taux d\'endettement élevé (' . number_format($ratios['taux_endettement'] ?? 0, 1) . ' %)'];
        }

        if ($ratios['couleur_liquidite'] === 'vert') {
            $positifs[] = ['force' => 2, 'texte' => 'Trésorerie / reste à vivre positif'];
        } elseif ($ratios['couleur_liquidite'] === 'rouge') {
            $risques[] = ['force' => 2, 'texte' => 'Trésorerie / reste à vivre négatif ou insuffisant'];
        }

        $couverture = $montantDemande > 0 ? $patrimoineNet / $montantDemande : 0;
        if ($couverture >= 1.0) {
            $positifs[] = ['force' => 3, 'texte' => 'Garanties patrimoniales solides (couverture ' . number_format($couverture * 100, 0) . ' % du prêt)'];
        } elseif ($couverture < 0.3) {
            $risques[] = ['force' => 3, 'texte' => 'Couverture patrimoniale insuffisante (' . number_format($couverture * 100, 0) . ' % du prêt)'];
        }

        if ($tauxPaiementATemps !== null) {
            if ($tauxPaiementATemps >= 0.9) {
                $positifs[] = ['force' => 3, 'texte' => 'Historique de remboursement excellent (' . number_format($tauxPaiementATemps * 100, 0) . ' % à temps)'];
            } elseif ($tauxPaiementATemps < 0.7) {
                $risques[] = ['force' => 3, 'texte' => 'Retards de paiement récurrents (' . number_format($tauxPaiementATemps * 100, 0) . ' % à temps seulement)'];
            }
        }

        if ($anciennete >= 36) {
            $positifs[] = ['force' => 1, 'texte' => 'Relation bancaire ancienne (' . $anciennete . ' mois)'];
        } elseif ($anciennete < 6) {
            $risques[] = ['force' => 1, 'texte' => 'Client récent, peu d\'historique (' . $anciennete . ' mois)'];
        }

        usort($positifs, fn($a, $b) => $b['force'] <=> $a['force']);
        usort($risques, fn($a, $b) => $b['force'] <=> $a['force']);

        $textesPositifs = array_column(array_slice($positifs, 0, 3), 'texte');
        $textesRisques = array_column(array_slice($risques, 0, 3), 'texte');

        while (count($textesPositifs) < 3) {
            $textesPositifs[] = null;
        }
        while (count($textesRisques) < 3) {
            $textesRisques[] = null;
        }

        return [$textesPositifs, $textesRisques];
    }
}
