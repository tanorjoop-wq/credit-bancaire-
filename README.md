# Plateforme de gestion des demandes de crédit bancaire

Projet 38 — Master CCA, ESP Dakar.

## Installation rapide (XAMPP)

⚠️ **Important** : le code utilise des chemins absolus (`/public/...`, `/modules/...`) pour les
redirections, le menu et les assets CSS/JS. Le projet doit donc être servi à la **racine** du
document root d'Apache, pas dans un sous-dossier.

1. Copier le **contenu** du dossier `credit_bancaire/` directement dans `C:\xampp\htdocs\`
   (Windows) ou `/opt/lampp/htdocs/` (Linux/Mac) — pas dans un sous-dossier `htdocs/credit_bancaire/`.
2. Démarrer Apache et MySQL depuis le panneau XAMPP.
3. Ouvrir phpMyAdmin → onglet **Importer** → sélectionner `sql/schema.sql` → Exécuter.
4. Vérifier `config/database.php` (identifiants par défaut XAMPP : user `root`, mot de passe vide).
5. Accéder à `http://localhost/public/login.php`.

## Comptes de test

| Rôle | Email | Mot de passe |
|---|---|---|
| Administrateur | admin@creditbanque.sn | Admin@2026 |
| Chargé de clientèle | charge@creditbanque.sn | Charge@2026 |
| Comité/Direction | comite@creditbanque.sn | Comite@2026 |

## État d'avancement

- ✅ Architecture de dossiers
- ✅ Connexion PDO sécurisée
- ✅ Authentification + hash bcrypt + session + rôles (Prompt 5)
- ✅ CRUD complet : module **Clients**
- ✅ CRUD complet : module **Demandes de crédit** + moteur de scoring OOP (`includes/ScoringEngine.php`)
  + workflow d'approbation à 2 niveaux (chargé de clientèle → comité) (Prompt 6)
- ✅ Tableau de bord avec cartes KPI + graphiques Chart.js alimentés par la base réelle (Prompt 7)
- ⏳ À venir : exports PDF/Excel (Prompt 8)

## Sécurité déjà en place

- Requêtes préparées PDO partout (anti-injection SQL)
- `htmlspecialchars()` systématique à l'affichage (anti-XSS)
- Jeton CSRF sur tous les formulaires POST
- Mots de passe hachés avec `password_hash()` / vérifiés avec `password_verify()`
- Cookie de session `httponly`
- Journal d'audit horodaté sur les actions sensibles (connexion, création/modification/suppression client)
