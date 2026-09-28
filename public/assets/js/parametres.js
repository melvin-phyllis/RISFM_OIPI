$(function () {
    const configurationWorkspace = document.querySelector('.configuration-workspace');
    if (configurationWorkspace) {
        const configurationTabs = Array.from(document.querySelectorAll('[data-configuration-panel]'));
        const configurationPanels = Array.from(configurationWorkspace.querySelectorAll('.configuration-panel'));

        const activateConfigurationPanel = function (panelId, updateAddress) {
            const targetExists = configurationPanels.some(function (panel) {
                return panel.id === panelId;
            });
            const safePanelId = targetExists ? panelId : 'identite-application';

            configurationTabs.forEach(function (tab) {
                const active = tab.getAttribute('data-configuration-panel') === safePanelId;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.setAttribute('tabindex', active ? '0' : '-1');
            });
            configurationPanels.forEach(function (panel) {
                const active = panel.id === safePanelId;
                panel.classList.toggle('is-active', active);
                panel.hidden = !active;
            });

            if (updateAddress && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + safePanelId);
            }
        };

        configurationWorkspace.classList.add('is-tabbed');
        activateConfigurationPanel(window.location.hash.replace(/^#/, ''), false);

        configurationTabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activateConfigurationPanel(String(tab.getAttribute('data-configuration-panel') || ''), true);
            });
            tab.addEventListener('keydown', function (event) {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                let targetIndex = index;
                if (event.key === 'ArrowLeft') targetIndex = (index - 1 + configurationTabs.length) % configurationTabs.length;
                if (event.key === 'ArrowRight') targetIndex = (index + 1) % configurationTabs.length;
                if (event.key === 'Home') targetIndex = 0;
                if (event.key === 'End') targetIndex = configurationTabs.length - 1;
                const targetTab = configurationTabs[targetIndex];
                activateConfigurationPanel(String(targetTab.getAttribute('data-configuration-panel') || ''), true);
                targetTab.focus();
            });
        });

        window.addEventListener('hashchange', function () {
            activateConfigurationPanel(window.location.hash.replace(/^#/, ''), false);
        });
    }

    $('.js-configuration-color').on('input change', function () {
        const value = String($(this).val() || '').toUpperCase();
        $(this).closest('.configuration-color-control').find('.js-configuration-color-code').text(value);
    });

    $('.js-configuration-logo-input').on('change', function () {
        const input = this;
        const file = input.files && input.files.length > 0 ? input.files[0] : null;
        const filename = $('.js-configuration-logo-filename');
        const preview = $('.js-configuration-logo-preview');

        if (!file) {
            filename.text('Aucun nouveau fichier selectionne');
            return;
        }

        filename.text(file.name);
        if (!file.type.startsWith('image/')) return;

        const reader = new FileReader();
        reader.addEventListener('load', function () {
            if (typeof reader.result === 'string') preview.attr('src', reader.result);
        });
        reader.readAsDataURL(file);
    });

    const modal = $('#modal-parametre-liste');
    const form = $('#form-parametre-liste');
    if (!modal.length || !form.length) return;

    modal.on('show.bs.modal', function (event) {
        const button = $(event.relatedTarget);
        const mode = String(button.data('mode') || 'add');
        const type = String(button.data('type') || '');
        const id = String(button.data('id') || '');
        const title = String(button.data('title') || 'Liste metier');
        const isEdit = mode === 'edit';
        const hasOrder = type === 'types_titres';
        const isStatus = type === 'statuts';

        const nativeForm = form.get(0);
        if (nativeForm) nativeForm.reset();

        const route = isEdit
            ? 'parametres/liste/' + type + '/modifier/' + encodeURIComponent(id)
            : 'parametres/liste/' + type + '/ajouter';
        form.attr('action', window.RISFM_BASE_URL + route);

        $('#modal-parametre-liste-titre span').text(isStatus
            ? 'Personnaliser un statut système'
            : (isEdit ? 'Modifier un element' : 'Ajouter un element'));
        $('#modal-parametre-liste-sous-titre').text(title);
        $('#parametre-submit-label').text(isEdit ? 'Enregistrer les modifications' : 'Ajouter');
        $('#parametre_liste_libelle').val(isEdit ? String(button.data('libelle') || '') : '');
        $('#parametre_liste_ordre').val(isEdit ? Number(button.data('ordre') || 0) : 0);
        $('#parametre_liste_couleur').val(isEdit ? String(button.data('couleur') || 'secondary') : 'secondary');
        $('#parametre-statut-nature').text(Number(button.data('resolu') || 0) === 1
            ? 'Cette étape marque le dossier comme résolu.'
            : 'Cette étape maintient le dossier ouvert.');

        $('.js-order-field').toggleClass('d-none', !hasOrder);
        $('.js-status-fields').toggleClass('d-none', !isStatus);
        $('#parametre-statut-warning').toggleClass('d-none', !isStatus);
        $('#parametre-ordre-aide').text(isEdit
            ? 'Le code technique existant est conserve automatiquement.'
            : 'Le code technique sera genere automatiquement a l’enregistrement.');
    });

    modal.on('shown.bs.modal', function () {
        $('#parametre_liste_libelle').trigger('focus');
    });

    modal.on('hidden.bs.modal', function () {
        form.attr('action', '');
    });
});
