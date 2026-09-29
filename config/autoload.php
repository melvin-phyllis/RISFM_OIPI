<?php
declare(strict_types=1);

/**
 * Chargement automatique PSR-4 des classes du projet, partage par le point
 * d'entree Web et tous les scripts CLI. Necessite BASE_PATH (config/config.php).
 * Les memes correspondances sont declarees dans composer.json.
 *
 *   App\Core\...                    app/Core/            infrastructure
 *   App\Http\Controllers\{Module}   app/Http/Controllers orchestration HTTP
 *   App\Services\{Module}           app/Services/        regles metier
 *   App\Repositories\{Module}       app/Repositories/    acces aux donnees
 *   Database\Seeders\...            database/seeders/    donnees initiales
 */
spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => '/app/', 'Database\\Seeders\\' => '/database/seeders/'] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = BASE_PATH . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
