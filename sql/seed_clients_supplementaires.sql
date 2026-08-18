-- =====================================================================
-- Données de démonstration supplémentaires — 22 nouveaux clients
-- (15 particuliers + 7 entreprises) pour enrichir la démo/soutenance.
-- Additive : n'affecte pas les données existantes.
-- =====================================================================

USE credit_bancaire;

INSERT INTO clients
    (type_client, nom_raison_sociale, prenom, numero_piece, telephone, email, adresse,
     revenu_mensuel, chiffre_affaires, anciennete_bancaire_mois, cree_par)
VALUES
-- --- Particuliers ---
('particulier', 'Ndour', 'Cheikh', '3234567890124', '775552201', 'cheikh.ndour@mail.sn', 'Mermoz, Dakar', 720000, NULL, 48, 2),
('particulier', 'Diouf', 'Aminata', '3234567890125', '765552202', 'aminata.diouf@mail.sn', 'Point E, Dakar', 950000, NULL, 72, 2),
('particulier', 'Fall', 'Ousmane', '3234567890126', '705552203', 'ousmane.fall@mail.sn', 'Ouakam, Dakar', 380000, NULL, 6, 2),
('particulier', 'Sow', 'Mariama', '3234567890127', '775552204', 'mariama.sow@mail.sn', 'Yoff, Dakar', 550000, NULL, 24, 2),
('particulier', 'Diallo', 'Ibrahima', '3234567890128', '765552205', NULL, 'Grand Dakar', 290000, NULL, 3, 2),
('particulier', 'Ba', 'Fatoumata', '3234567890129', '705552206', 'fatoumata.ba@mail.sn', 'Liberté 6, Dakar', 1150000, NULL, 96, 2),
('particulier', 'Gueye', 'Moussa', '3234567890130', '775552207', 'moussa.gueye@mail.sn', 'Ngor, Dakar', 420000, NULL, 18, 2),
('particulier', 'Sy', 'Khadija', '3234567890131', '765552208', NULL, 'Almadies, Dakar', 680000, NULL, 30, 2),
('particulier', 'Diop', 'Modou', '3234567890132', '705552209', 'modou.diop@mail.sn', 'Pikine, Dakar', 210000, NULL, 2, 2),
('particulier', 'Faye', 'Awa', '3234567890133', '775552210', 'awa.faye@mail.sn', 'Guédiawaye, Dakar', 340000, NULL, 12, 2),
('particulier', 'Niang', 'Abdoulaye', '3234567890134', '765552211', 'abdoulaye.niang@mail.sn', 'Rufisque', 480000, NULL, 42, 2),
('particulier', 'Sarr', 'Bineta', '3234567890135', '705552212', NULL, 'Thiès Nord', 390000, NULL, 9, 2),
('particulier', 'Thiam', 'Serigne', '3234567890136', '775552213', 'serigne.thiam@mail.sn', 'Sicap Baobabs, Dakar', 890000, NULL, 60, 2),
('particulier', 'Kane', 'Ndeye', '3234567890137', '765552214', 'ndeye.kane@mail.sn', 'Parcelles Assainies U21, Dakar', 520000, NULL, 15, 2),
('particulier', 'Camara', 'Souleymane', '3234567890138', '705552215', 'souleymane.camara@mail.sn', 'Saint-Louis', 310000, NULL, 5, 2),

-- --- Entreprises ---
('entreprise', 'SARL Baobab Import Export', NULL, 'SN-DKR-2018-B-4521', '338452201', 'contact@baobabimport.sn', 'Zone industrielle, Dakar', NULL, 145000000, 84, 2),
('entreprise', 'Etablissements Fall & Fils', NULL, 'SN-DKR-2015-A-1187', '338452202', 'contact@fallfils.sn', 'Colobane, Dakar', NULL, 62000000, 108, 2),
('entreprise', 'SUARL Dakar Tech Solutions', NULL, 'SN-DKR-2022-C-3390', '338452203', 'contact@dakartech.sn', 'Ngor, Dakar', NULL, 38000000, 24, 2),
('entreprise', 'GIE Femmes Commerçantes de Thiès', NULL, 'SN-THI-2019-G-0765', '339452204', 'gie.femmes.thies@mail.sn', 'Marché central, Thiès', NULL, 18000000, 36, 2),
('entreprise', 'SA Sénégal Agro Industries', NULL, 'SN-DKR-2012-S-0234', '338452205', 'contact@senagroindustries.sn', 'Zone industrielle, Rufisque', NULL, 320000000, 132, 2),
('entreprise', 'SARL Teranga Construction', NULL, 'SN-DKR-2020-B-2678', '338452206', 'contact@terangaconstruction.sn', 'Point E, Dakar', NULL, 95000000, 48, 2),
('entreprise', 'Entreprise Individuelle Diagne Transport', NULL, 'SN-DKR-2021-E-4102', '338452207', 'diagne.transport@mail.sn', 'Pikine, Dakar', NULL, 27000000, 30, 2);
