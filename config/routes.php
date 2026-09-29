<?php
declare(strict_types=1);

/**
 * Registre des fichiers de routes, un par module (equivalent du withRouting()
 * de bootstrap/app.php dans l'API Laravel de l'ERP).
 *
 * Chaque fichier de routes/ retourne une liste [methode, chemin, controleur,
 * action]. Un nouveau fichier de routes doit etre ajoute ici.
 */
return [
    'auth'           => require BASE_PATH . '/routes/auth.php',
    'formulaires'    => require BASE_PATH . '/routes/formulaires.php',
    'missions'       => require BASE_PATH . '/routes/missions.php',
    'utilisateurs'   => require BASE_PATH . '/routes/utilisateurs.php',
    'administration' => require BASE_PATH . '/routes/administration.php',
    'pilotage'       => require BASE_PATH . '/routes/pilotage.php',
    'notifications'  => require BASE_PATH . '/routes/notifications.php',
    'api'            => require BASE_PATH . '/routes/api.php',
];
