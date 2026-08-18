-- =====================================================================
-- PROJET 38 — Plateforme web de gestion des demandes de crédit bancaire
-- avec scoring et workflow d'approbation
-- Master CCA — ESP Dakar
-- Stack : XAMPP (MySQL 8+ / MariaDB compatible)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS credit_bancaire
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE credit_bancaire;

-- ---------------------------------------------------------------------
-- 1. UTILISATEURS (3 rôles obligatoires)
-- ---------------------------------------------------------------------
CREATE TABLE utilisateurs (
    id_utilisateur      INT AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(100) NOT NULL,
    prenom              VARCHAR(100) NOT NULL,
    email               VARCHAR(150) NOT NULL UNIQUE,
    mot_de_passe_hash   VARCHAR(255) NOT NULL,
    role                ENUM('administrateur','charge_clientele','comite_direction') NOT NULL,
    telephone           VARCHAR(20),
    actif               TINYINT(1) NOT NULL DEFAULT 1,
    date_creation       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    derniere_connexion  DATETIME NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. CLIENTS (particuliers ou entreprises)
-- ---------------------------------------------------------------------
CREATE TABLE clients (
    id_client           INT AUTO_INCREMENT PRIMARY KEY,
    type_client         ENUM('particulier','entreprise') NOT NULL,
    nom_raison_sociale  VARCHAR(150) NOT NULL,
    prenom              VARCHAR(100) NULL,
    numero_piece        VARCHAR(50) NOT NULL COMMENT 'CNI ou NINEA',
    telephone           VARCHAR(20) NOT NULL,
    email               VARCHAR(150),
    adresse             VARCHAR(255),
    revenu_mensuel      DECIMAL(15,2) NULL COMMENT 'particulier',
    chiffre_affaires    DECIMAL(18,2) NULL COMMENT 'entreprise',
    anciennete_bancaire_mois INT DEFAULT 0,
    cree_par            INT NOT NULL,
    date_creation       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_client_createur FOREIGN KEY (cree_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. DEMANDES DE CREDIT
-- ---------------------------------------------------------------------
CREATE TABLE demandes_credit (
    id_demande          INT AUTO_INCREMENT PRIMARY KEY,
    reference           VARCHAR(30) NOT NULL UNIQUE,
    id_client           INT NOT NULL,
    type_credit         ENUM('consommation','immobilier','investissement','tresorerie') NOT NULL,
    montant_demande      DECIMAL(15,2) NOT NULL,
    duree_mois           INT NOT NULL,
    taux_interet_propose DECIMAL(5,2) NOT NULL,
    objet_credit         VARCHAR(255),
    statut               ENUM('en_attente','en_analyse','scoring_effectue','en_comite',
                                'approuve','refuse','decaisse','solde')
                          NOT NULL DEFAULT 'en_attente',
    charge_id            INT NOT NULL COMMENT 'chargé de clientèle affecté',
    date_demande         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    date_decision        DATETIME NULL,
    CONSTRAINT fk_demande_client FOREIGN KEY (id_client)
        REFERENCES clients(id_client),
    CONSTRAINT fk_demande_charge FOREIGN KEY (charge_id)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. SCORING (résultat du calcul automatique)
-- ---------------------------------------------------------------------
CREATE TABLE scoring (
    id_scoring              INT AUTO_INCREMENT PRIMARY KEY,
    id_demande              INT NOT NULL UNIQUE,
    capacite_remboursement  DECIMAL(15,2) NOT NULL COMMENT 'revenu - charges - échéance',
    taux_endettement        DECIMAL(5,2) NOT NULL COMMENT '% charges/revenus',
    valeur_garanties        DECIMAL(15,2) DEFAULT 0,
    score_total              DECIMAL(5,2) NOT NULL COMMENT 'sur 100',
    grade                    ENUM('A','B','C','D','E') NOT NULL,
    probabilite_defaut       DECIMAL(5,2) NOT NULL COMMENT '% PD estimée',
    date_calcul              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    calcule_par               INT NOT NULL,
    CONSTRAINT fk_scoring_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE,
    CONSTRAINT fk_scoring_utilisateur FOREIGN KEY (calcule_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. GARANTIES
-- ---------------------------------------------------------------------
CREATE TABLE garanties (
    id_garantie          INT AUTO_INCREMENT PRIMARY KEY,
    id_demande           INT NOT NULL,
    type_garantie        ENUM('hypotheque','caution','nantissement','gage','domiciliation_salaire') NOT NULL,
    description           VARCHAR(255),
    valeur_estimee        DECIMAL(15,2) NOT NULL,
    statut                ENUM('proposee','validee','rejetee') NOT NULL DEFAULT 'proposee',
    date_ajout            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_garantie_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. DOCUMENTS (pièces justificatives)
-- ---------------------------------------------------------------------
CREATE TABLE documents (
    id_document           INT AUTO_INCREMENT PRIMARY KEY,
    id_demande            INT NOT NULL,
    type_document         VARCHAR(100) NOT NULL COMMENT 'bulletin salaire, états financiers, CNI...',
    nom_fichier           VARCHAR(255) NOT NULL,
    chemin_fichier        VARCHAR(255) NOT NULL,
    date_upload           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    uploade_par            INT NOT NULL,
    CONSTRAINT fk_document_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE,
    CONSTRAINT fk_document_utilisateur FOREIGN KEY (uploade_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. WORKFLOW D'APPROBATION (traçabilité multi-niveaux)
-- ---------------------------------------------------------------------
CREATE TABLE workflow_approbation (
    id_workflow           INT AUTO_INCREMENT PRIMARY KEY,
    id_demande            INT NOT NULL,
    niveau                ENUM('charge_clientele','comite','direction') NOT NULL,
    decideur_id            INT NOT NULL,
    decision               ENUM('favorable','defavorable','en_attente') NOT NULL DEFAULT 'en_attente',
    commentaire            TEXT,
    date_decision          DATETIME NULL,
    CONSTRAINT fk_workflow_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande) ON DELETE CASCADE,
    CONSTRAINT fk_workflow_decideur FOREIGN KEY (decideur_id)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. CONTRATS (après accord définitif)
-- ---------------------------------------------------------------------
CREATE TABLE contrats (
    id_contrat            INT AUTO_INCREMENT PRIMARY KEY,
    id_demande            INT NOT NULL UNIQUE,
    numero_contrat        VARCHAR(30) NOT NULL UNIQUE,
    montant_accorde        DECIMAL(15,2) NOT NULL,
    taux_final              DECIMAL(5,2) NOT NULL,
    duree_mois              INT NOT NULL,
    date_signature          DATE NULL,
    date_decaissement       DATE NULL,
    statut                  ENUM('en_preparation','signe','decaisse','solde','en_defaut') NOT NULL DEFAULT 'en_preparation',
    CONSTRAINT fk_contrat_demande FOREIGN KEY (id_demande)
        REFERENCES demandes_credit(id_demande)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. ECHEANCIER (tableau d'amortissement)
-- ---------------------------------------------------------------------
CREATE TABLE echeancier (
    id_echeance            INT AUTO_INCREMENT PRIMARY KEY,
    id_contrat              INT NOT NULL,
    numero_echeance          INT NOT NULL,
    date_echeance            DATE NOT NULL,
    capital                  DECIMAL(15,2) NOT NULL,
    interet                  DECIMAL(15,2) NOT NULL,
    montant_echeance         DECIMAL(15,2) NOT NULL,
    capital_restant_du       DECIMAL(15,2) NOT NULL,
    statut                   ENUM('a_venir','payee','impayee','en_retard') NOT NULL DEFAULT 'a_venir',
    CONSTRAINT fk_echeance_contrat FOREIGN KEY (id_contrat)
        REFERENCES contrats(id_contrat) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. REMBOURSEMENTS (paiements effectifs)
-- ---------------------------------------------------------------------
CREATE TABLE remboursements (
    id_remboursement        INT AUTO_INCREMENT PRIMARY KEY,
    id_echeance              INT NOT NULL,
    date_paiement             DATE NOT NULL,
    montant_paye              DECIMAL(15,2) NOT NULL,
    mode_paiement             ENUM('virement','especes','mobile_money','prelevement') NOT NULL,
    enregistre_par            INT NOT NULL,
    date_enregistrement       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_remb_echeance FOREIGN KEY (id_echeance)
        REFERENCES echeancier(id_echeance),
    CONSTRAINT fk_remb_utilisateur FOREIGN KEY (enregistre_par)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 11. JOURNAL D'AUDIT (traçabilité horodatée des actions sensibles)
-- ---------------------------------------------------------------------
CREATE TABLE journal_audit (
    id_audit               INT AUTO_INCREMENT PRIMARY KEY,
    id_utilisateur          INT NOT NULL,
    action                   VARCHAR(100) NOT NULL,
    table_concernee          VARCHAR(50) NOT NULL,
    id_enregistrement        INT NULL,
    details                  TEXT,
    date_action               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_utilisateur FOREIGN KEY (id_utilisateur)
        REFERENCES utilisateurs(id_utilisateur)
) ENGINE=InnoDB;

-- =====================================================================
-- DONNEES DE DEMONSTRATION
-- =====================================================================

-- Comptes de test pour les 3 rôles (hash bcrypt RÉELS, générés et
-- vérifiables avec password_verify() en PHP — pas de valeur inventée)
INSERT INTO utilisateurs (nom, prenom, email, mot_de_passe_hash, role, telephone) VALUES
('Diop', 'Awa', 'admin@creditbanque.sn',
 '$2b$10$IUYPWCk.GSHvbS/SjtC1G.MjWI83rWcHf3.EEVUs1W/P5rjBdPGK2',
 'administrateur', '771234501'),
('Fall', 'Mamadou', 'charge@creditbanque.sn',
 '$2b$10$8Mef/d2Hn.DOxxhVaeyrbOIYu7Fu7T4srV7m5H30.QQ/Rsk/4SoIK',
 'charge_clientele', '771234502'),
('Ndiaye', 'Fatou', 'comite@creditbanque.sn',
 '$2b$10$LzQx512lbkHT19.BgWUJyuk88FI0D0amJWXI1g21zw5yk.nP4raKu',
 'comite_direction', '771234503');

-- Identifiants de test (mots de passe EN CLAIR, à ne conserver que
-- dans le README, jamais en base) :
--   admin@creditbanque.sn      / Admin@2026
--   charge@creditbanque.sn     / Charge@2026
--   comite@creditbanque.sn     / Comite@2026

-- Clients de démonstration
INSERT INTO clients (type_client, nom_raison_sociale, prenom, numero_piece, telephone, email, adresse, revenu_mensuel, chiffre_affaires, anciennete_bancaire_mois, cree_par) VALUES
('particulier', 'Sarr', 'Ibrahima', '1234567890123', '775551122', 'ibrahima.sarr@mail.sn', 'Sicap Liberté 6, Dakar', 650000, NULL, 36, 2),
('particulier', 'Ba', 'Aissatou', '2234567890123', '775551133', 'aissatou.ba@mail.sn', 'Parcelles Assainies U15, Dakar', 480000, NULL, 12, 2),
('entreprise', 'SARL Teranga Distribution', NULL, 'SN-DKR-2019-B-1234', '338451122', 'contact@terangadistrib.sn', 'Zone industrielle, Dakar', NULL, 85000000, 60, 2);

-- Demandes de crédit
INSERT INTO demandes_credit (reference, id_client, type_credit, montant_demande, duree_mois, taux_interet_propose, objet_credit, statut, charge_id) VALUES
('CRD-2026-0001', 1, 'consommation', 5000000, 24, 9.50, 'Achat véhicule', 'scoring_effectue', 2),
('CRD-2026-0002', 2, 'immobilier', 25000000, 120, 7.80, 'Acquisition logement', 'en_analyse', 2),
('CRD-2026-0003', 3, 'tresorerie', 15000000, 12, 10.00, 'Besoin fonds de roulement', 'approuve', 2);

-- Scoring
INSERT INTO scoring (id_demande, capacite_remboursement, taux_endettement, valeur_garanties, score_total, grade, probabilite_defaut, calcule_par) VALUES
(1, 195000, 33.00, 0, 78.50, 'B', 4.20, 2),
(3, 4200000, 28.00, 18000000, 85.00, 'A', 2.10, 2);

-- Garanties
INSERT INTO garanties (id_demande, type_garantie, description, valeur_estimee, statut) VALUES
(1, 'domiciliation_salaire', 'Domiciliation salaire employeur', 650000, 'validee'),
(3, 'nantissement', 'Nantissement stock marchandises', 18000000, 'validee');

-- Documents
INSERT INTO documents (id_demande, type_document, nom_fichier, chemin_fichier, uploade_par) VALUES
(1, 'bulletin_salaire', 'bulletin_sarr_juin2026.pdf', '/uploads/demandes/1/bulletin_sarr_juin2026.pdf', 2),
(3, 'etats_financiers', 'bilan_teranga_2025.pdf', '/uploads/demandes/3/bilan_teranga_2025.pdf', 2);

-- Workflow d'approbation
INSERT INTO workflow_approbation (id_demande, niveau, decideur_id, decision, commentaire, date_decision) VALUES
(1, 'charge_clientele', 2, 'favorable', 'Dossier complet, capacité de remboursement suffisante', '2026-08-01 10:00:00'),
(3, 'charge_clientele', 2, 'favorable', 'Bon historique client, garanties solides', '2026-07-20 09:00:00'),
(3, 'comite', 3, 'favorable', 'Validation comité, montant dans les limites déléguées', '2026-07-22 14:30:00');

-- Contrat (pour la demande approuvée)
INSERT INTO contrats (id_demande, numero_contrat, montant_accorde, taux_final, duree_mois, date_signature, date_decaissement, statut) VALUES
(3, 'CTR-2026-0001', 15000000, 10.00, 12, '2026-07-25', '2026-07-28', 'decaisse');

-- Echéancier (extrait des 3 premières échéances sur 12)
INSERT INTO echeancier (id_contrat, numero_echeance, date_echeance, capital, interet, montant_echeance, capital_restant_du, statut) VALUES
(1, 1, '2026-08-28', 1195000, 125000, 1320000, 13805000, 'payee'),
(1, 2, '2026-09-28', 1205000, 115042, 1320042, 12600000, 'payee'),
(1, 3, '2026-10-28', 1215000, 105000, 1320000, 11385000, 'a_venir');

-- Remboursements
INSERT INTO remboursements (id_echeance, date_paiement, montant_paye, mode_paiement, enregistre_par) VALUES
(1, '2026-08-27', 1320000, 'virement', 2),
(2, '2026-09-28', 1320042, 'virement', 2);

-- Journal d'audit
INSERT INTO journal_audit (id_utilisateur, action, table_concernee, id_enregistrement, details) VALUES
(2, 'CREATION_DEMANDE', 'demandes_credit', 3, 'Création de la demande CRD-2026-0003'),
(3, 'VALIDATION_COMITE', 'workflow_approbation', 3, 'Validation favorable du comité sur CRD-2026-0003'),
(2, 'DECAISSEMENT', 'contrats', 1, 'Décaissement de 15 000 000 FCFA sur le contrat CTR-2026-0001');
