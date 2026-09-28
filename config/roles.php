<?php
declare(strict_types=1);

/**
 * Definition centrale des roles et de leurs permissions.
 * Une permission est identifiee par une chaine "module.action".
 */
return [
    'administrateur' => [
        'label' => 'Administrateur',
        'permissions' => ['*'], // acces complet
    ],
    'responsable' => [
        'label' => 'Responsable',
        'permissions' => [
            'formulaires.view', 'formulaires.create', 'formulaires.update_metadata',
            'formulaires.assign', 'formulaires.record_result_own',
            'formulaires.finalize',
            'formulaires.attach_own', 'formulaires.delete_attachment_own',
            'formulaires.export',
            'dashboard.view', 'statistiques.view', 'recherche.view',
            'journal.view', 'notifications.view', 'profil.update',
        ],
    ],
    'agent' => [
        'label' => 'Agent',
        'permissions' => [
            'formulaires.view', 'formulaires.create',
            'formulaires.record_result_own', 'formulaires.attach_own',
            'formulaires.delete_attachment_own',
            'dashboard.view', 'recherche.view', 'notifications.view', 'profil.update',
        ],
    ],
    'consultation' => [
        'label' => 'Consultation',
        'permissions' => [
            'formulaires.view', 'dashboard.view', 'recherche.view', 'notifications.view', 'profil.update',
        ],
    ],
];
