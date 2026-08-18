<?php
/**
 * Analyse financière CCA : cascade des Soldes Intermédiaires de Gestion (SIG),
 * structure financière (FDR/BFR/Trésorerie nette) et ratios prudentiels
 * (DSCR, taux d'endettement net, reste à vivre), avec code couleur de risque.
 *
 * Hypothèses de simplification pédagogique (documentées pour la soutenance) :
 *   - EBE = Valeur Ajoutée − Charges de personnel (les impôts & taxes autres
 *     que l'IS ne sont pas isolés dans le formulaire de saisie)
 *   - CAF (capacité d'autofinancement) = Résultat net + Dotations aux amortissements
 */
class AnalyseFinanciere
{
    // Seuil DSCR "vert" par défaut — surchargeable via parametres_scoring
    // (Module 15/Administration, clé 'dscr_min_acceptable'). Comportement
    // strictement identique à avant si le paramètre n'est pas fourni.
    private float $dscrMinAcceptable = 1.25;

    public function __construct(?PDO $pdo = null)
    {
        if ($pdo !== null) {
            try {
                $stmt = $pdo->prepare("SELECT valeur FROM parametres_scoring WHERE cle = 'dscr_min_acceptable'");
                $stmt->execute();
                $valeur = $stmt->fetchColumn();
                if ($valeur !== false) {
                    $this->dscrMinAcceptable = (float) $valeur;
                }
            } catch (PDOException $e) {
                // Table absente : on garde le seuil par défaut.
            }
        }
    }

    /**
     * Cascade SIG + ratios prudentiels pour un client ENTREPRISE.
     */
    public function calculerEntreprise(array $donnees, float $echeanceMensuelle = 0): array
    {
        $ca = (float) ($donnees['chiffre_affaires'] ?? 0);
        $achats = (float) ($donnees['achats_consommes'] ?? 0);
        $chargesPersonnel = (float) ($donnees['charges_personnel'] ?? 0);
        $dotations = (float) ($donnees['dotations_amortissements'] ?? 0);
        $chargesFin = (float) ($donnees['charges_financieres'] ?? 0);
        $produitsFin = (float) ($donnees['produits_financiers'] ?? 0);
        $exceptionnel = (float) ($donnees['resultat_exceptionnel'] ?? 0);
        $impots = (float) ($donnees['impots_societe'] ?? 0);

        $stocks = (float) ($donnees['stocks'] ?? 0);
        $creances = (float) ($donnees['creances_clients'] ?? 0);
        $dettesFournisseurs = (float) ($donnees['dettes_fournisseurs'] ?? 0);
        $dettesLT = (float) ($donnees['dettes_financieres_lt'] ?? 0);
        $capitauxPropres = (float) ($donnees['capitaux_propres'] ?? 0);
        $actifImmobilise = (float) ($donnees['actif_immobilise'] ?? 0);
        $tresorerieBilan = (float) ($donnees['tresorerie'] ?? 0);

        // --- Cascade SIG ---
        $valeurAjoutee = $ca - $achats;
        $ebe = $valeurAjoutee - $chargesPersonnel;
        $resultatExploitation = $ebe - $dotations;
        $rcai = $resultatExploitation - $chargesFin + $produitsFin;
        $resultatNet = $rcai + $exceptionnel - $impots;
        $caf = $resultatNet + $dotations;

        // --- Structure financière ---
        $fdr = $capitauxPropres + $dettesLT - $actifImmobilise;
        $bfr = ($stocks + $creances) - $dettesFournisseurs;
        $tresorerieNette = $fdr - $bfr;

        // --- Ratios prudentiels ---
        $serviceDetteAnnuel = $echeanceMensuelle * 12;
        $dscr = $serviceDetteAnnuel > 0 ? $caf / $serviceDetteAnnuel : null;
        $tauxEndettementNet = $capitauxPropres > 0 ? ($dettesLT / $capitauxPropres) * 100 : null;

        // --- Ratios de profitabilité complémentaires (Module 3 — Financial Intelligence) ---
        $actifTotalEstime = $actifImmobilise + $stocks + $creances + $tresorerieBilan;
        $roe = $capitauxPropres > 0 ? ($resultatNet / $capitauxPropres) * 100 : null;
        $roa = $actifTotalEstime > 0 ? ($resultatNet / $actifTotalEstime) * 100 : null;
        $margeEbitda = $ca > 0 ? ($ebe / $ca) * 100 : null;
        $detteSurEbitda = $ebe > 0 ? $dettesLT / $ebe : null;
        // Cycle de Conversion Cash (jours) : approximation classique DSO + DIO − DPO,
        // basée sur les postes de bilan disponibles (pas de détail achats/ventes journalier).
        $ccc = $ca > 0
            ? round((($creances / $ca) + ($stocks / max(1, $achats))) * 365 - (($dettesFournisseurs / max(1, $achats)) * 365))
            : null;

        return [
            'chiffre_affaires'        => $ca,
            'valeur_ajoutee'          => $valeurAjoutee,
            'ebe'                     => $ebe,
            'resultat_exploitation'   => $resultatExploitation,
            'rcai'                    => $rcai,
            'resultat_net'            => $resultatNet,
            'caf'                     => $caf,
            'fdr'                     => $fdr,
            'bfr'                     => $bfr,
            'tresorerie_nette'        => $tresorerieNette,
            'dscr'                    => $dscr,
            'taux_endettement_net'    => $tauxEndettementNet,
            'couleur_tresorerie'      => $this->classifierTresorerie($tresorerieNette),
            'couleur_dscr'            => $this->classifierDscr($dscr),
            'couleur_endettement'     => $this->classifierEndettement($tauxEndettementNet),
            'roe'                     => $roe,
            'roa'                     => $roa,
            'marge_ebitda'            => $margeEbitda,
            'dette_sur_ebitda'        => $detteSurEbitda,
            'ccc_jours'               => $ccc,
        ];
    }

    /**
     * Ratios budgétaires pour un client PARTICULIER.
     */
    public function calculerParticulier(array $client, array $donnees, float $echeanceMensuelle = 0): array
    {
        $revenuMensuel = (float) ($client['revenu_mensuel'] ?? 0);
        $autresRevenus = (float) ($donnees['autres_revenus'] ?? 0);
        $chargesFixes = (float) ($donnees['charges_mensuelles_fixes'] ?? 0);

        $revenuTotal = $revenuMensuel + $autresRevenus;
        $resteAVivre = $revenuTotal - $chargesFixes - $echeanceMensuelle;
        $resteAVivrePourcent = $revenuTotal > 0 ? ($resteAVivre / $revenuTotal) * 100 : null;
        $tauxEndettement = $revenuTotal > 0 ? (($chargesFixes + $echeanceMensuelle) / $revenuTotal) * 100 : null;

        $serviceDetteAnnuel = $echeanceMensuelle * 12;
        $capaciteAnnuelle = ($revenuTotal - $chargesFixes) * 12;
        $dscr = $serviceDetteAnnuel > 0 ? $capaciteAnnuelle / $serviceDetteAnnuel : null;

        return [
            'revenu_total'          => $revenuTotal,
            'reste_a_vivre'         => $resteAVivre,
            'reste_a_vivre_pourcent'=> $resteAVivrePourcent,
            'taux_endettement'      => $tauxEndettement,
            'dscr'                  => $dscr,
            'couleur_reste_a_vivre' => $this->classifierResteAVivre($resteAVivrePourcent),
            'couleur_dscr'          => $this->classifierDscr($dscr),
            'couleur_endettement'   => $this->classifierEndettementParticulier($tauxEndettement),
        ];
    }

    private function classifierTresorerie(float $valeur): string
    {
        if ($valeur > 0) return 'vert';
        if ($valeur === 0.0) return 'orange';
        return 'rouge';
    }

    private function classifierDscr(?float $dscr): string
    {
        if ($dscr === null) return 'orange';
        if ($dscr >= $this->dscrMinAcceptable) return 'vert';
        if ($dscr >= 1.0) return 'orange';
        return 'rouge';
    }

    private function classifierEndettement(?float $taux): string
    {
        if ($taux === null) return 'orange';
        if ($taux <= 100) return 'vert';
        if ($taux <= 200) return 'orange';
        return 'rouge';
    }

    private function classifierEndettementParticulier(?float $taux): string
    {
        if ($taux === null) return 'orange';
        if ($taux <= 33) return 'vert';
        if ($taux <= 45) return 'orange';
        return 'rouge';
    }

    private function classifierResteAVivre(?float $pourcent): string
    {
        if ($pourcent === null) return 'orange';
        if ($pourcent >= 30) return 'vert';
        if ($pourcent >= 15) return 'orange';
        return 'rouge';
    }
}
