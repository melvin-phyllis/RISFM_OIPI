/* Case "Formulaire deja retrouve" : affiche les champs de la decouverte et ne
 * les soumet (ni ne les exige) que lorsqu'elle est cochee. */
(function () {
    'use strict';
    document.addEventListener('change', function (event) {
        var toggle = event.target;
        if (!toggle.classList || !toggle.classList.contains('js-retrouve-toggle')) {
            return;
        }
        var fields = document.querySelector(toggle.getAttribute('data-target'));
        if (!fields) {
            return;
        }
        fields.classList.toggle('d-none', !toggle.checked);
        fields.querySelectorAll('input, select, textarea').forEach(function (input) {
            input.disabled = !toggle.checked;
        });
        // Le statut initial Introuvable ne s'applique plus a un dossier retrouve.
        var form = toggle.closest('form');
        if (form) {
            form.querySelectorAll('.js-retrouve-hide').forEach(function (block) {
                block.classList.toggle('d-none', toggle.checked);
            });
        }
    });
})();
