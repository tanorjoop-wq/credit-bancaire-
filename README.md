# Plateforme de gestion des demandes de crédit bancaire

Projet 38 — Master CCA, ESP Dakar.

Plateforme de gestion du cycle de vie complet du crédit bancaire : de la demande à l'analyse financière, au scoring, à la décision en comité, au décaissement, au suivi des remboursements et au recouvrement. Architecture PHP orientée objet, base MySQL/MariaDB unique (22 tables), 16 modules fonctionnels.

## Installation rapide (XAMPP)

⚠️ **Important** : le code utilise des chemins absolus (`/public/...`, `/modules/...`) pour les
redirections, le menu et les assets CSS/JS. Le projet doit donc être servi à la **racine** du
document root d'Apache, pas dans un sous-dossier.

1. Copier le **contenu** du dossier `credit_bancaire/` directement dans `C:\xampp\htdocs\`
   (Windows) ou `/opt/lampp/htdocs/` (Linux/Mac) — pas dans un sous-dossier `htdocs/credit_bancaire/`.
2. Installer les dépendances PHP : `composer install` (DOMPDF, PhpSpreadsheet, PHPMailer — nécessaires aux exports PDF/Excel et aux emails).
3. Démarrer Apache et MySQL depuis le panneau XAMPP.
4. Ouvrir phpMyAdmin → onglet **Importer** → exécuter dans l'ordre :
   1. `sql/schema.sql` (schéma de base)
   2. `sql/migration_v2_analyse_risque.sql` (analyse financière, scoring avancé, rentabilité, restructuration, stress-tests)
   3. `sql/migration_v3_core_banking.sql` (agences, notifications, recouvrement, produits, paramètres de scoring, documents, audit avant/après)
5. Vérifier `config/database.php` (identifiants par défaut XAMPP : user `root`, mot de passe vide).
6. (Optionnel, pour les emails de décaissement) copier `config/mail.example.php` en `config/mail.php` et renseigner vos identifiants SMTP.
7. (Optionnel, pour des données de démonstration) exécuter `sql/seed_v3_core_banking.php` en CLI ou via le navigateur pour peupler la base avec un portefeuille réaliste.
8. Accéder à `http://localhost/public/login.php`.

## Comptes de test

| Rôle | Email | Mot de passe |
|---|---|---|
| Administrateur | admin@creditbanque.sn | Admin@2026 |
| Chargé de clientèle | charge@creditbanque.sn | Charge@2026 |
| Comité/Direction | comite@creditbanque.sn | Comite@2026 |

## Fonctionnalités — 16 modules

1. **Tableau de bord** — vue globale du portefeuille, production des 30 derniers jours, indicateurs de risque (PAR/NPL), rentabilité, pipeline commercial, alertes précoces, répartition par produit, activité récente
2. **Clients** — dossier client complet, KYC (photo + signature), patrimoine, documents rattachés
3. **Analyse financière** — SIG, EBE, FDR, BFR, DSCR, ROE, ROA, marge EBITDA, ratio dette/EBITDA, cycle de conversion cash
4. **Demandes de crédit** — création et suivi, scoring, pipeline visuel en plusieurs étapes
5. **Simulateur de crédit** — simulation d'amortissement à annuités constantes
6. **Comité de crédit** — file d'attente des dossiers, synthèse assistée par le moteur d'intelligence crédit, vote multi-membres avec quorum, décision conditionnelle
7. **Contrats** — classification Performing / Watchlist / At Risk / Default, suivi de progression, restructuration
8. **Paiements & échéanciers** — suivi des échéances, jours de retard, recalcul instantané
9. **Recouvrement** — segmentation des impayés par ancienneté, relances
10. **Risque** — PAR 30/60/90, perte attendue (Expected Loss), matrice de migration, provisions
11. **Rentabilité** — calcul RAROC, agrégats par produit et par agence, comparatif prévisionnel/réel
12. **Reporting (BI)** — export PDF de l'état des demandes, export Excel de la liste clients, rapport de portefeuille PDF, performance des analystes en Excel, échéancier en Excel, attestation de contrat en PDF, fiche de synthèse en PDF
13. **Documents (GED)** — gestion documentaire par client / demande / contrat, statut de validation, expiration, versioning
14. **Notifications** — alertes déclenchées sur les décisions du comité
15. **Administration** — gestion des utilisateurs et des rôles, agences, produits de crédit, paramètres de scoring éditables
16. **Audit** — journal d'audit avec filtres (action, utilisateur, rôle, date) et traçabilité avant/après modification

## Moteur d'intelligence crédit

`includes/MoteurIntelligenceCredit.php` est un moteur **déterministe, explicable et paramétrable** (pas de LLM externe) : il détecte des incohérences dans un dossier par un système de règles (montant vs revenu, ancienneté vs montant demandé, garanties vs patrimoine), synthétise les forces et faiblesses, et propose une recommandation — la décision finale reste toujours celle du comité.

## Architecture technique

- PHP 8+ orienté objet, PDO avec requêtes préparées
- MySQL/MariaDB — 22 tables couvrant l'ensemble du cycle de vie du crédit
- Bootstrap 5 pour l'interface, Chart.js pour les graphiques
- DOMPDF (exports PDF), PhpSpreadsheet (exports Excel), PHPMailer (notifications par email)

## Sécurité en place

- Requêtes préparées PDO partout (anti-injection SQL)
- `htmlspecialchars()` systématique à l'affichage (anti-XSS)
- Jeton CSRF sur tous les formulaires POST
- Mots de passe hachés avec `password_hash()` / vérifiés avec `password_verify()`
- Cookie de session `httponly`, expiration automatique après 20 minutes d'inactivité
- Contrôle d'accès par rôle (RBAC), appliqué côté backend et côté interface (menus filtrés selon le rôle)
- Journal d'audit horodaté sur les actions sensibles, avec traçabilité avant/après modification
