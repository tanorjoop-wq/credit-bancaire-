-- =====================================================================
-- MIGRATION V2 — Analyse de risque bancaire avancée
-- Additive : ne modifie ni ne supprime aucune donnée existante.
-- À exécuter après sql/schema.sql.
-- =====================================================================

USE credit_bancaire;

-- ---------------------------------------------------------------------
-- 1. CLIENTS — dossier KYC (photo + signature)
-- ---------------------------------------------------------------------
ALTER TABLE clients
    ADD COLUMN photo_path VARCHAR(255) NULL AFTER adresse,
    ADD COLUMN signature_path VARCHAR(255) NULL AFTER photo_path;

-- ---------------------------------------------------------------------
-- 2. PATRIMOINE CLIENT — inventaire d'actifs (Onglet 2)
-- ---------------------------------------------------------------------
CREATE TABLE patrimoine_client (
    id_patrimoine      INT AUTO_INCREMENT PRIMARY KEY,
    id_client           INT NOT NULL,
    type_actif           ENUM('immobilier','vehicule','epargne','autre') NOT NULL,
    description           VARCHAR(255),
    valeur_estimee        DECIMAL(15,2) NOT NULL,
    date_evaluation       DATE NOT NULL,
    cree_par              INT NOT NULL,
    date_creation         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_patrimoine_client FOREIGN KEY (id_client)
        REFERENCES clients(id_client) ON DELETE CASCADE,
    CONSTRAINT fk_patrimoine_utilisateur FOREIGN KEY (cree_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. DONNEES FINANCIERES — SIG/EBE (entreprise) et budget (particulier)
--    (Onglet 3 — Agrégats & ratios financiers)
-- ---------------------------------------------------------------------
CREATE TABLE donnees_financieres (
    id_donnee                  INT AUTO_INCREMENT PRIMARY KEY,
    id_client                   INT NOT NULL,
    date_exercice                DATE NOT NULL,
    -- Champs entreprise (cascade SIG)
    chiffre_affaires             DECIMAL(18,2) NULL,
    achats_consommes             DECIMAL(18,2) NULL,
    charges_personnel            DECIMAL(18,2) NULL,
    dotations_amortissements     DECIMAL(18,2) NULL,
    charges_financieres          DECIMAL(18,2) NULL,
    produits_financiers          DECIMAL(18,2) NULL,
    resultat_exceptionnel        DECIMAL(18,2) NULL,
    impots_societe                DECIMAL(18,2) NULL,
    stocks                        DECIMAL(18,2) NULL,
    creances_clients              DECIMAL(18,2) NULL,
    dettes_fournisseurs           DECIMAL(18,2) NULL,
    dettes_financieres_lt         DECIMAL(18,2) NULL,
    capitaux_propres              DECIMAL(18,2) NULL,
    actif_immobilise               DECIMAL(18,2) NULL,
    tresorerie                     DECIMAL(18,2) NULL,
    -- Champs particulier
    charges_mensuelles_fixes       DECIMAL(15,2) NULL,
    autres_revenus                 DECIMAL(15,2) NULL,
    saisi_par                       INT NOT NULL,
    date_saisie                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_financieres_client FOREIGN KEY (id_client)
        REFERENCES clients(id_client) ON DELETE CASCADE,
    CONSTRAINT fk_financieres_utilisateur FOREIGN KEY (saisi_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. SCORING AVANCE — couche additive au-dessus de `scoring`
--    (Onglet 4 — Scoring multidimensionnel explicable)
-- ---------------------------------------------------------------------
CREATE TABLE scoring_avance (
    id_scoring_avance      INT AUTO_INCREMENT PRIMARY KEY,
    id_demande               INT NOT NULL UNIQUE,
    note_globale              ENUM('A+','A','B+','B','C+','C','D','E','F') NOT NULL,
    score_financier            DECIMAL(5,2) NOT NULL,
    score_patrimonial          DECIMAL(5,2) NOT NULL,
    score_comportemental       DECIMAL(5,2) NOT NULL,
    score_global                DECIMAL(5,2) NOT NULL,
    facteur_positif_1            VARCHAR(150),
    facteur_positif_2            VARCHAR(150),
    facteur_positif_3            VARCHAR(150),
    facteur_risque_1              VARCHAR(150),
    facteur_risque_2              VARCHAR(150),
    facteur_risque_3              VARCHAR(150),
    calcule_par                    INT NOT NULL,
    date_calcul                    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_scoringavance_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE,
    CONSTRAINT fk_scoringavance_utilisateur FOREIGN KEY (calcule_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. RENTABILITE DEMANDE — RAROC (Onglet 6)
-- ---------------------------------------------------------------------
CREATE TABLE rentabilite_demande (
    id_rentabilite          INT AUTO_INCREMENT PRIMARY KEY,
    id_demande                INT NOT NULL UNIQUE,
    interets_bruts              DECIMAL(18,2) NOT NULL,
    cout_refinancement          DECIMAL(18,2) NOT NULL,
    marge_nette_interet         DECIMAL(18,2) NOT NULL COMMENT 'MNI',
    probabilite_defaut          DECIMAL(5,2) NOT NULL COMMENT 'PD %',
    perte_en_cas_defaut         DECIMAL(5,2) NOT NULL COMMENT 'LGD %',
    exposition_defaut           DECIMAL(18,2) NOT NULL COMMENT 'EAD',
    cout_du_risque               DECIMAL(18,2) NOT NULL COMMENT 'PD x LGD x EAD',
    charges_operatoires          DECIMAL(18,2) NOT NULL,
    capital_economique           DECIMAL(18,2) NOT NULL,
    gain_net_ajuste               DECIMAL(18,2) NOT NULL,
    raroc                          DECIMAL(6,2) NOT NULL COMMENT '%',
    seuil_cible                    DECIMAL(5,2) NOT NULL,
    verdict                        ENUM('rentable','marge_insuffisante') NOT NULL,
    calcule_par                     INT NOT NULL,
    date_calcul                     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rentabilite_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE,
    CONSTRAINT fk_rentabilite_utilisateur FOREIGN KEY (calcule_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. RESTRUCTURATIONS — rééchelonnement de créance (Onglet 7)
-- ---------------------------------------------------------------------
CREATE TABLE restructurations (
    id_restructuration          INT AUTO_INCREMENT PRIMARY KEY,
    id_contrat                    INT NOT NULL,
    ancienne_duree_mois             INT NOT NULL,
    nouvelle_duree_mois             INT NOT NULL,
    ancien_taux                     DECIMAL(5,2) NOT NULL,
    nouveau_taux                    DECIMAL(5,2) NOT NULL,
    differe_mois                    INT NOT NULL DEFAULT 0,
    capital_restant_avant            DECIMAL(15,2) NOT NULL,
    motif                            VARCHAR(255) NOT NULL,
    decide_par                       INT NOT NULL,
    date_restructuration             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_restructuration_contrat FOREIGN KEY (id_contrat)
        REFERENCES contrats(id_contrat),
    CONSTRAINT fk_restructuration_utilisateur FOREIGN KEY (decide_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. SIMULATIONS STRESS — stress-testing comité (Onglet 5)
-- ---------------------------------------------------------------------
CREATE TABLE simulations_stress (
    id_simulation           INT AUTO_INCREMENT PRIMARY KEY,
    id_demande                INT NOT NULL,
    choc_taux                   DECIMAL(5,2) NOT NULL COMMENT 'points de % ajoutés au taux',
    choc_revenu                 DECIMAL(5,2) NOT NULL COMMENT '% de baisse du revenu',
    echeance_avant                DECIMAL(15,2) NOT NULL,
    echeance_apres                DECIMAL(15,2) NOT NULL,
    dscr_avant                     DECIMAL(6,2) NULL,
    dscr_apres                     DECIMAL(6,2) NULL,
    viable_apres_choc               TINYINT(1) NOT NULL,
    teste_par                       INT NOT NULL,
    date_test                       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_stress_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE,
    CONSTRAINT fk_stress_utilisateur FOREIGN KEY (teste_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. ECHEANCIER — ajout du statut 'annulee' (restructuration)
-- ---------------------------------------------------------------------
ALTER TABLE echeancier
    MODIFY statut ENUM('a_venir','payee','impayee','en_retard','annulee')
    NOT NULL DEFAULT 'a_venir';
