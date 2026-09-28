/**
 * Fonctions JS globales RISFM : jeton CSRF pour AJAX, confirmations SweetAlert2,
 * gestion des toasts de session expiree.
 */
document.addEventListener('DOMContentLoaded', function () {
    let flashMessagesDone = Promise.resolve();
    const flashContainer = document.getElementById('risfm-flash-messages');
    if (flashContainer) {
        try {
            const messages = JSON.parse(flashContainer.getAttribute('data-messages') || '[]');
            flashMessagesDone = risfmShowFlashMessages(messages);
        } catch (error) {
            console.error('Messages flash RISFM invalides.', error);
        }
    }

    const missionReminder = window.RISFM_LOGIN_MISSION_REMINDER;
    if (missionReminder && Number(missionReminder.total_actives) > 0) {
        // Le court delai laisse le DOM et la navigation d'ancre se stabiliser
        // sans obliger l'utilisateur a attendre la duree d'un eventuel toast.
        flashMessagesDone.finally(function () {
            window.setTimeout(function () {
                risfmShowLoginMissionReminder(missionReminder);
            }, 180);
        });
        delete window.RISFM_LOGIN_MISSION_REMINDER;
    }

    // Confirmation generique de suppression
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const message = form.getAttribute('data-confirm') || 'Confirmez-vous cette action ?';
            const title = form.getAttribute('data-confirm-title') || 'Confirmation';
            const confirmText = form.getAttribute('data-confirm-button') || 'Confirmer';
            const isDanger = form.getAttribute('data-confirm-variant') === 'danger';
            Swal.fire({
                title: title,
                text: message,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: isDanger
                    ? '#dc3545'
                    : (getComputedStyle(document.documentElement).getPropertyValue('--risfm-vert-700').trim() || '#007439'),
                cancelButtonColor: '#6c757d',
                confirmButtonText: confirmText,
                cancelButtonText: 'Annuler',
                reverseButtons: true,
                focusCancel: true,
            }).then(function (result) {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
});

function risfmShowLoginMissionReminder(reminder) {
    const total = Math.max(0, Number(reminder.total_actives) || 0);
    const overdue = Math.max(0, Number(reminder.total_en_retard) || 0);
    const urgent = Math.max(0, Number(reminder.total_urgentes) || 0);
    const actionUrl = typeof reminder.action_url === 'string' && reminder.action_url !== ''
        ? reminder.action_url
        : (window.RISFM_BASE_URL || '/') + 'dashboard#mes-missions-actives';
    const actionLabel = typeof reminder.action_label === 'string' && reminder.action_label !== ''
        ? reminder.action_label
        : 'Voir mes missions';

    let deadline = '';
    const isoDeadline = typeof reminder.prochaine_echeance === 'string'
        ? reminder.prochaine_echeance
        : '';
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDeadline);
    if (match) {
        deadline = match[3] + '/' + match[2] + '/' + match[1];
    }

    const missionWord = total === 1 ? 'mission active' : 'missions actives';
    const detailItems = [];
    if (overdue > 0) {
        detailItems.push(
            '<div class="risfm-login-reminder-stat is-overdue">' +
                '<i class="fas fa-clock" aria-hidden="true"></i>' +
                '<strong>' + overdue + '</strong><span>en retard</span>' +
            '</div>'
        );
    }
    if (urgent > 0) {
        detailItems.push(
            '<div class="risfm-login-reminder-stat is-urgent">' +
                '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i>' +
                '<strong>' + urgent + '</strong><span>urgente' + (urgent > 1 ? 's' : '') + '</span>' +
            '</div>'
        );
    }
    if (deadline !== '') {
        detailItems.push(
            '<div class="risfm-login-reminder-stat">' +
                '<i class="far fa-calendar-alt" aria-hidden="true"></i>' +
                '<strong>' + deadline + '</strong><span>échéance proche</span>' +
            '</div>'
        );
    }

    return Swal.fire({
        title: 'Vous avez des missions à effectuer',
        html:
            '<div class="risfm-login-reminder-total">' +
                '<span>' + total + '</span><strong>' + missionWord + '</strong>' +
            '</div>' +
            (detailItems.length > 0
                ? '<div class="risfm-login-reminder-grid">' + detailItems.join('') + '</div>'
                : '') +
            '<p class="risfm-login-reminder-help">Consultez les priorités et les échéances avant de commencer vos recherches.</p>',
        icon: overdue > 0 || urgent > 0 ? 'warning' : 'info',
        showCancelButton: true,
        showCloseButton: true,
        confirmButtonText: actionLabel,
        cancelButtonText: 'Plus tard',
        confirmButtonColor: getComputedStyle(document.documentElement)
            .getPropertyValue('--risfm-vert-700').trim() || '#007439',
        cancelButtonColor: '#6c757d',
        reverseButtons: true,
        focusConfirm: true,
        customClass: { popup: 'risfm-login-mission-reminder' },
    }).then(function (result) {
        if (result.isConfirmed) {
            window.location.assign(actionUrl);
        }
    });
}

function risfmCsrfToken() {
    const el = document.querySelector('meta[name="csrf-token"]');
    return el ? el.getAttribute('content') : '';
}

function risfmAjax(options) {
    return $.ajax(Object.assign({
        headers: { 'X-CSRF-TOKEN': risfmCsrfToken() },
    }, options));
}

function risfmToast(icon, message) {
    const durations = {
        success: 4000,
        error: 7000,
        warning: 6000,
        info: 5000,
    };
    const safeIcon = ['success', 'error', 'warning', 'info'].includes(icon) ? icon : 'info';
    const toastMessage = String(message || '');
    // Un message long (nom de sauvegarde, erreur metier detaillee...) reste
    // affiche assez longtemps pour etre lu sans bloquer l'interface.
    const readingTime = Math.min(10000, Math.max(durations[safeIcon], 2200 + toastMessage.length * 38));

    return Swal.fire({
        toast: true,
        position: 'top-end',
        icon: safeIcon,
        title: toastMessage,
        showConfirmButton: false,
        showCloseButton: true,
        timer: readingTime,
        timerProgressBar: true,
        customClass: {
            popup: 'risfm-swal-toast risfm-swal-toast-' + safeIcon,
        },
        didOpen: function (toast) {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        },
    });
}

async function risfmShowFlashMessages(messages) {
    if (!Array.isArray(messages)) {
        return;
    }
    for (const flash of messages) {
        if (flash && typeof flash.message === 'string' && flash.message !== '') {
            await risfmToast(flash.type, flash.message);
        }
    }
}
