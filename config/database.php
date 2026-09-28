<?php
declare(strict_types=1);

return [
    'host'    => env('DB_HOST', '127.0.0.1'),
    'port'    => env('DB_PORT', '3306'),
    'dbname'  => env('DB_NAME', 'oipi_risfm'),
    'user'    => env('DB_USER', 'root'),
    'pass'    => env('DB_PASS', ''),
    // Compte optionnel dedie aux sauvegardes. Il doit avoir des droits limites
    // a la base principale et aux bases ephemeres de test de restauration.
    'maintenance_user' => env('DB_MAINTENANCE_USER', env('DB_USER', 'root')),
    'maintenance_pass' => env('DB_MAINTENANCE_PASS', env('DB_PASS', '')),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
];
