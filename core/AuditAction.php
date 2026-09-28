<?php
declare(strict_types=1);

/** Presentation centralisee des actions du journal d'audit. */
final class AuditAction
{
    private const META = [
        'connexion' => ['label' => 'Connexion reussie', 'class' => 'success', 'icon' => 'fas fa-sign-in-alt'],
        'connexion_echouee' => ['label' => 'Connexion echouee', 'class' => 'danger', 'icon' => 'fas fa-user-times'],
        'connexion_refusee' => ['label' => 'Connexion refusee', 'class' => 'danger', 'icon' => 'fas fa-ban'],
        'deconnexion' => ['label' => 'Deconnexion', 'class' => 'secondary', 'icon' => 'fas fa-sign-out-alt'],
        'securite' => ['label' => 'Securite', 'class' => 'danger', 'icon' => 'fas fa-shield-alt'],
        'ajout' => ['label' => 'Creation', 'class' => 'success', 'icon' => 'fas fa-plus-circle'],
        'modification' => ['label' => 'Modification', 'class' => 'primary', 'icon' => 'fas fa-edit'],
        'suppression' => ['label' => 'Suppression', 'class' => 'danger', 'icon' => 'fas fa-trash-alt'],
        'activation' => ['label' => 'Activation', 'class' => 'success', 'icon' => 'fas fa-toggle-on'],
        'desactivation' => ['label' => 'Desactivation', 'class' => 'secondary', 'icon' => 'fas fa-toggle-off'],
        'recherche' => ['label' => 'Compte rendu', 'class' => 'info', 'icon' => 'fas fa-search'],
        'changement_statut' => ['label' => 'Changement de statut', 'class' => 'info', 'icon' => 'fas fa-exchange-alt'],
        'affectation' => ['label' => 'Affectation', 'class' => 'warning', 'icon' => 'fas fa-user-tag'],
        'reaffectation' => ['label' => 'Reaffectation', 'class' => 'warning', 'icon' => 'fas fa-people-arrows'],
        'annulation_mission' => ['label' => 'Annulation de mission', 'class' => 'secondary', 'icon' => 'fas fa-times-circle'],
        'reouverture' => ['label' => 'Reouverture', 'class' => 'warning', 'icon' => 'fas fa-folder-open'],
        'finalisation' => ['label' => 'Finalisation', 'class' => 'success', 'icon' => 'fas fa-check-double'],
        'archivage' => ['label' => 'Archivage', 'class' => 'dark', 'icon' => 'fas fa-archive'],
        'restauration' => ['label' => 'Restauration', 'class' => 'warning', 'icon' => 'fas fa-undo-alt'],
        'sauvegarde' => ['label' => 'Sauvegarde', 'class' => 'dark', 'icon' => 'fas fa-database'],
        'export' => ['label' => 'Export', 'class' => 'primary', 'icon' => 'fas fa-file-export'],
        'telechargement' => ['label' => 'Telechargement', 'class' => 'primary', 'icon' => 'fas fa-download'],
        'email' => ['label' => 'E-mail', 'class' => 'info', 'icon' => 'fas fa-envelope'],
        'notification' => ['label' => 'Notification', 'class' => 'info', 'icon' => 'fas fa-bell'],
        'import_analyse' => ['label' => 'Analyse d’import', 'class' => 'info', 'icon' => 'fas fa-file-import'],
        'import' => ['label' => 'Import', 'class' => 'success', 'icon' => 'fas fa-file-import'],
        'rappel' => ['label' => 'Rappel automatique', 'class' => 'warning', 'icon' => 'fas fa-clock'],
    ];

    /** @return array{label:string,class:string,icon:string} */
    public static function meta(string $type): array
    {
        if (isset(self::META[$type])) {
            return self::META[$type];
        }

        return [
            'label' => ucfirst(str_replace('_', ' ', $type)),
            'class' => 'secondary',
            'icon' => 'fas fa-circle',
        ];
    }
}
