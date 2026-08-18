<?php
/**
 * Génère le tableau d'amortissement d'un crédit (annuité constante).
 */
class GenerateurEcheancier
{
    /**
     * @return array<int, array{numero_echeance:int,date_echeance:string,capital:float,
     *               interet:float,montant_echeance:float,capital_restant_du:float}>
     */
    public function genererTableau(float $montant, int $dureeMois, float $tauxAnnuel, string $dateDebut): array
    {
        $tauxMensuel = $tauxAnnuel / 100 / 12;

        $echeanceConstante = $tauxMensuel == 0.0
            ? $montant / $dureeMois
            : $montant * $tauxMensuel / (1 - (1 + $tauxMensuel) ** (-$dureeMois));

        $capitalRestant = $montant;
        $tableau = [];

        for ($numero = 1; $numero <= $dureeMois; $numero++) {
            $interet = round($capitalRestant * $tauxMensuel, 2);
            $capital = round($echeanceConstante - $interet, 2);

            // Dernière échéance : on absorbe l'écart d'arrondi pour solder exactement à 0
            if ($numero === $dureeMois) {
                $capital = $capitalRestant;
            }

            $capitalRestant = round($capitalRestant - $capital, 2);
            if ($capitalRestant < 0) {
                $capitalRestant = 0.0;
            }

            $tableau[] = [
                'numero_echeance'     => $numero,
                'date_echeance'       => date('Y-m-d', strtotime($dateDebut . " +{$numero} months")),
                'capital'             => $capital,
                'interet'             => $interet,
                'montant_echeance'    => round($capital + $interet, 2),
                'capital_restant_du'  => $capitalRestant,
            ];
        }

        return $tableau;
    }

    /**
     * Régénère un tableau d'amortissement de reprise après restructuration,
     * à partir du capital restant dû, avec un différé d'amortissement optionnel
     * (mois pendant lesquels seul l'intérêt est payé).
     *
     * @return array<int, array{numero_echeance:int,date_echeance:string,capital:float,
     *               interet:float,montant_echeance:float,capital_restant_du:float}>
     */
    public function genererTableauReprise(
        float $capitalRestant,
        int $nouvelleDureeMois,
        float $nouveauTauxAnnuel,
        string $dateDebut,
        int $differeMois,
        int $numeroDepart
    ): array {
        $tauxMensuel = $nouveauTauxAnnuel / 100 / 12;
        $tableau = [];
        $numero = $numeroDepart;

        // Phase de différé : intérêts seuls, capital restant inchangé
        for ($i = 1; $i <= $differeMois; $i++) {
            $interet = round($capitalRestant * $tauxMensuel, 2);
            $tableau[] = [
                'numero_echeance'    => $numero,
                'date_echeance'      => date('Y-m-d', strtotime($dateDebut . " +{$i} months")),
                'capital'            => 0.0,
                'interet'            => $interet,
                'montant_echeance'   => $interet,
                'capital_restant_du' => $capitalRestant,
            ];
            $numero++;
        }

        // Phase d'amortissement classique sur la durée restante
        $dureeAmortissement = $nouvelleDureeMois - $differeMois;
        if ($dureeAmortissement > 0) {
            $dateDebutAmortissement = date('Y-m-d', strtotime($dateDebut . " +{$differeMois} months"));
            $tableauAmortissement = $this->genererTableau($capitalRestant, $dureeAmortissement, $nouveauTauxAnnuel, $dateDebutAmortissement);
            foreach ($tableauAmortissement as $ligne) {
                $ligne['numero_echeance'] = $numero;
                $tableau[] = $ligne;
                $numero++;
            }
        }

        return $tableau;
    }
}
