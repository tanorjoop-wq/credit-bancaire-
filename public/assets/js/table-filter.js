/**
 * Filtre rapide côté client — filtre les lignes visibles d'un tableau à la
 * frappe, sans aller-retour serveur. Complète (ne remplace pas) les
 * recherches serveur déjà en place sur certaines listes.
 */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-table-filter]').forEach(function (input) {
        var table = document.querySelector(input.getAttribute('data-table-filter'));
        if (!table) {
            return;
        }
        input.addEventListener('input', function () {
            var requete = input.value.trim().toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (ligne) {
                var texte = ligne.textContent.toLowerCase();
                ligne.style.display = requete === '' || texte.includes(requete) ? '' : 'none';
            });
        });
    });
});
