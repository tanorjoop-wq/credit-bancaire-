-- =====================================================================
-- MIGRATION V3 — Core Banking "Credit Intelligence & Loan Management"
-- Additive : ne modifie ni ne supprime aucune donnée existante.
-- À exécuter après sql/schema.sql et sql/migration_v2_analyse_risque.sql.
-- =====================================================================

USE credit_bancaire;

-- ---------------------------------------------------------------------
-- 1. AGENCES — dimension transverse (filtres/rollups par agence)
-- ---------------------------------------------------------------------
CREATE TABLE agences (
    id_agence     INT AUTO_INCREMENT PRIMARY KEY,
    nom            VARCHAR(100) NOT NULL,
    ville          VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE utilisateurs
    ADD COLUMN id_agence INT NULL AFTER role,
    ADD CONSTRAINT fk_utilisateur_agence FOREIGN KEY (id_agence) REFERENCES agences(id_agence);

-- ---------------------------------------------------------------------
-- 2. NOTIFICATIONS (Module 14)
-- ---------------------------------------------------------------------
CREATE TABLE notifications (
    id_notification          INT AUTO_INCREMENT PRIMARY KEY,
    id_utilisateur_destinataire INT NOT NULL,
    niveau                    ENUM('critique','important','info') NOT NULL DEFAULT 'info',
    titre                      VARCHAR(150) NOT NULL,
    message                    VARCHAR(255) NOT NULL,
    lien_cible                 VARCHAR(255) NULL,
    lu                         TINYINT(1) NOT NULL DEFAULT 0,
    date_creation              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_utilisateur FOREIGN KEY (id_utilisateur_destinataire)
        REFERENCES utilisateurs(id_utilisateur) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. RELANCES DE RECOUVREMENT (Module 9)
-- ---------------------------------------------------------------------
CREATE TABLE relances_recouvrement (
    id_relance      INT AUTO_INCREMENT PRIMARY KEY,
    id_echeance      INT NOT NULL,
    type_relance      ENUM('appel','sms','mise_en_demeure') NOT NULL,
    commentaire        VARCHAR(255) NULL,
    effectue_par        INT NOT NULL,
    date_relance         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_relance_echeance FOREIGN KEY (id_echeance)
        REFERENCES echeancier(id_echeance) ON DELETE CASCADE,
    CONSTRAINT fk_relance_utilisateur FOREIGN KEY (effectue_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. PRODUITS DE CRÉDIT (Module 15 — Administration)
-- ---------------------------------------------------------------------
CREATE TABLE produits_credit (
    id_produit      INT AUTO_INCREMENT PRIMARY KEY,
    nom              VARCHAR(100) NOT NULL,
    type_credit       ENUM('consommation','immobilier','investissement','tresorerie') NOT NULL,
    taux_min           DECIMAL(5,2) NOT NULL,
    taux_max           DECIMAL(5,2) NOT NULL,
    duree_min_mois      INT NOT NULL,
    duree_max_mois       INT NOT NULL,
    plafond               DECIMAL(15,2) NOT NULL,
    actif                  TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. PARAMÈTRES DE SCORING (Module 15 — pondérations/seuils déportés)
-- ---------------------------------------------------------------------
CREATE TABLE parametres_scoring (
    cle           VARCHAR(60) PRIMARY KEY,
    valeur         DECIMAL(10,4) NOT NULL,
    description     VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

INSERT INTO parametres_scoring (cle, valeur, description) VALUES
('poids_financier', 0.35, 'Pondération du score financier dans le score global avancé'),
('poids_patrimonial', 0.25, 'Pondération du score patrimonial dans le score global avancé'),
('poids_comportemental', 0.40, 'Pondération du score comportemental dans le score global avancé'),
('dscr_min_acceptable', 1.20, 'Seuil DSCR minimum recommandé (early warning en-dessous)'),
('taux_endettement_max_particulier', 45.00, 'Taux d\'endettement maximum recommandé pour un particulier (%)'),
('taux_endettement_max_entreprise', 200.00, 'Taux d\'endettement net maximum recommandé pour une entreprise (%)');

-- ---------------------------------------------------------------------
-- 6. DOCUMENTS — généralisation (Module 13, GED)
-- ---------------------------------------------------------------------
ALTER TABLE documents
    MODIFY id_demande INT NULL,
    ADD COLUMN id_client INT NULL AFTER id_demande,
    ADD COLUMN id_contrat INT NULL AFTER id_client,
    ADD COLUMN statut_validation ENUM('valide','manquant','expire') NOT NULL DEFAULT 'valide' AFTER chemin_fichier,
    ADD COLUMN date_expiration DATE NULL AFTER statut_validation,
    ADD COLUMN version INT NOT NULL DEFAULT 1 AFTER date_expiration,
    ADD CONSTRAINT fk_document_client FOREIGN KEY (id_client) REFERENCES clients(id_client) ON DELETE CASCADE,
    ADD CONSTRAINT fk_document_contrat FOREIGN KEY (id_contrat) REFERENCES contrats(id_contrat) ON DELETE CASCADE;

-- ---------------------------------------------------------------------
-- 7. WORKFLOW D'APPROBATION — décision conditionnelle (Module 6)
-- ---------------------------------------------------------------------
ALTER TABLE workflow_approbation
    MODIFY decision ENUM('favorable','favorable_conditionnel','defavorable','en_attente') NOT NULL DEFAULT 'en_attente',
    ADD COLUMN conditions TEXT NULL AFTER commentaire;

-- ---------------------------------------------------------------------
-- 8. CONTRATS — verrou de conditions avant décaissement
-- ---------------------------------------------------------------------
ALTER TABLE contrats
    ADD COLUMN conditions_remplies TINYINT(1) NULL AFTER statut;

-- ---------------------------------------------------------------------
-- 9. JOURNAL D'AUDIT — traçabilité avant/après (Module 16)
-- ---------------------------------------------------------------------
ALTER TABLE journal_audit
    ADD COLUMN ancienne_valeur TEXT NULL AFTER details,
    ADD COLUMN nouvelle_valeur TEXT NULL AFTER ancienne_valeur;

-- ---------------------------------------------------------------------
-- 10. SCORING AVANCÉ — historisation (Module 10, matrice de migration)
-- ---------------------------------------------------------------------
ALTER TABLE scoring_avance
    DROP INDEX id_demande,
    ADD INDEX idx_scoringavance_demande (id_demande);

-- Vue "score courant" = dernière évaluation par demande — tout le code
-- applicatif qui lisait `scoring_avance` (une ligne par demande, avant
-- historisation) lit désormais cette vue pour un comportement identique.
CREATE OR REPLACE VIEW vue_scoring_avance_actuel AS
SELECT sa.*
FROM scoring_avance sa
INNER JOIN (
    SELECT id_demande, MAX(date_calcul) AS date_max
    FROM scoring_avance
    GROUP BY id_demande
) dernier ON dernier.id_demande = sa.id_demande AND dernier.date_max = sa.date_calcul;
