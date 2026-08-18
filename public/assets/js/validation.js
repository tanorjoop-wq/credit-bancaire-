/**
 * Validation côté client (complémentaire à la validation PHP côté serveur,
 * qui reste la seule protection réellement fiable).
 */
document.addEventListener('DOMContentLoaded', function () {
    const formulaires = document.querySelectorAll('form[data-validate="true"]');

    formulaires.forEach(function (form) {
        form.addEventListener('submit', function (event) {
            let valide = true;

            form.querySelectorAll('[required]').forEach(function (champ) {
                if (!champ.value.trim()) {
                    valide = false;
                    champ.classList.add('is-invalid');
                } else {
                    champ.classList.remove('is-invalid');
                }
            });

            // Vérification spécifique : téléphone sénégalais (9 chiffres)
            const telephone = form.querySelector('[name="telephone"]');
            if (telephone && telephone.value && !/^\d{9}$/.test(telephone.value.trim())) {
                valide = false;
                telephone.classList.add('is-invalid');
            }

            // Vérification spécifique : montants positifs
            form.querySelectorAll('[data-type="montant"]').forEach(function (champ) {
                if (champ.value && parseFloat(champ.value) <= 0) {
                    valide = false;
                    champ.classList.add('is-invalid');
                }
            });

            if (!valide) {
                event.preventDefault();
                alert('Veuillez corriger les champs invalides avant de continuer.');
            }
        });
    });
});
