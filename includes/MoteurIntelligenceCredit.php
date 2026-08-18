<?php
/**
 * Moteur d'Intelligence Crédit — synthèse transversale pour le Comité (Module 6)
 * et l'Analyse financière (Module 3).
 *
 * Volontairement **déterministe, explicable et auditable** : un système de règles
 * métier paramétrables, pas un modèle génératif. Il ne décide jamais à la place de
 * l'analyste/comité — il synthétise ce qui est déjà calculé (ratios, scoring,
 * patrimoine, garanties) et signale des incohérences par des règles explicites.
 * Interface stable (`evaluer()`) : une évolution future vers un modèle ML/LLM
 * pourrait remplacer l'implémentation interne sans toucher aux modules appelants.
 */
class MoteurIntelligenceCredit
{
    /**
     * @param array      $client           Ligne de la table clients
     * @param array      $demande          Ligne de la table demandes_credit
     * @param array|null $ratiosFinanciers Sortie normalisée d'AnalyseFinanciere (ou null si non saisie)
     * @param array|null $scoringAvance    Ligne de vue_scoring_avance_actuel (ou null si non calculé)
     * @param float      $patrimoineNet    Somme patrimoine_client
     * @param float      $totalGaranties   Somme garanties non rejetées
     *
     * @return array{forces: string[], faiblesses: string[], incoherences: string[], recommandation: string}
     */
    public function evaluer(
        array $client,
        array $demande,
        ?array $ratiosFinanciers,
        ?array $scoringAvance,
        float $patrimoineNet,
        float $totalGaranties
    ): array {
        $forces = [];
        $faiblesses = [];
        $incoherences = $this->detecterIncoherences($client, $demande, $ratiosFinanciers, $patrimoineNet, $totalGaranties);

        if ($scoringAvance) {
            foreach ([$scoringAvance['facteur_positif_1'], $scoringAvance['facteur_positif_2'], $scoringAvance['facteur_positif_3']] as $f) {
                if ($f) {
                    $forces[] = $f;
                }
            }
            foreach ([$scoringAvance['facteur_risque_1'], $scoringAvance['facteur_risque_2'], $scoringAvance['facteur_risque_3']] as $f) {
                if ($f) {
                    $faiblesses[] = $f;
                }
            }
        }

        $couvertureGaranties = (float) $demande['montant_demande'] > 0
            ? $totalGaranties / (float) $demande['montant_demande']
            : 0.0;
        if ($couvertureGaranties >= 1.0) {
            $forces[] = 'Garanties couvrant intégralement le montant demandé (' . round($couvertureGaranties * 100) . ' %)';
        } elseif ($couvertureGaranties < 0.2 && $totalGaranties < $patrimoineNet) {
            $faiblesses[] = 'Garanties formelles faibles malgré un patrimoine déclaré plus élevé — à formaliser';
        }

        $recommandation = $this->genererRecommandation($scoringAvance, $incoherences, $couvertureGaranties);

        return [
            'forces'          => array_values(array_unique($forces)),
            'faiblesses'      => array_values(array_unique($faiblesses)),
            'incoherences'    => $incoherences,
            'recommandation'  => $recommandation,
        ];
    }

    /**
     * @return string[]
     */
    private function detecterIncoherences(array $client, array $demande, ?array $ratios, float $patrimoineNet, float $totalGaranties): array
    {
        $incoherences = [];
        $montant = (float) $demande['montant_demande'];
        $estEntreprise = $client['type_client'] === 'entreprise';

        if ($estEntreprise) {
            $ca = (float) ($client['chiffre_affaires'] ?? 0);
            if ($ca > 0 && $montant > $ca) {
                $incoherences[] = 'Le montant demandé dépasse le chiffre d\'affaires annuel déclaré — capacité d\'absorption à questionner.';
            }
            if ($ratios && isset($ratios['couleur_liquidite'], $ratios['couleur_dscr'])
                && $ratios['couleur_liquidite'] === 'rouge' && $ratios['couleur_dscr'] === 'vert') {
                $incoherences[] = 'Trésorerie nette négative malgré un DSCR favorable — vérifier la cohérence des données financières déclarées.';
            }
        } else {
            $revenu = (float) ($client['revenu_mensuel'] ?? 0);
            if ($revenu > 0 && $montant > $revenu * 15) {
                $incoherences[] = 'Montant demandé supérieur à 15 mois de revenu déclaré — proportion inhabituelle pour un profil particulier.';
            }
        }

        $anciennete = (int) ($client['anciennete_bancaire_mois'] ?? 0);
        if ($anciennete < 3 && $montant > 50000000) {
            $incoherences[] = 'Client très récent (< 3 mois d\'ancienneté) pour un montant élevé (> 50M FCFA) — historique insuffisant pour l\'évaluer sereinement.';
        }

        if ($totalGaranties > $patrimoineNet * 1.5 && $patrimoineNet > 0) {
            $incoherences[] = 'Valeur des garanties proposées significativement supérieure au patrimoine net déclaré du client — à faire valider par une expertise indépendante.';
        }

        return $incoherences;
    }

    private function genererRecommandation(?array $scoringAvance, array $incoherences, float $couvertureGaranties): string
    {
        if (!empty($incoherences)) {
            return 'Avis consultatif : ' . count($incoherences) . ' incohérence(s) détectée(s) — recommandation de demander des justificatifs complémentaires avant décision, quel que soit le score.';
        }

        if (!$scoringAvance) {
            return 'Avis consultatif : scoring avancé non encore calculé — recommandation de compléter l\'analyse financière avant présentation en comité.';
        }

        $note = $scoringAvance['note_globale'];
        $noteFavorable = in_array($note, ['A+', 'A', 'B+', 'B'], true);
        $noteRisquee = in_array($note, ['D', 'E', 'F'], true);

        if ($noteFavorable && $couvertureGaranties >= 0.5) {
            return "Avis consultatif : dossier robuste (note {$note}), garanties correctement couvertes — favorable à l'octroi sous réserve de la vérification documentaire standard.";
        }
        if ($noteRisquee) {
            return "Avis consultatif : profil à risque élevé (note {$note}) — recommandation de refus ou d'octroi conditionnel avec renforcement substantiel des garanties et plafonnement du montant.";
        }
        return "Avis consultatif : dossier intermédiaire (note {$note}) — envisager un octroi sous conditions (garantie additionnelle, montant réduit, ou différé initial).";
    }
}
