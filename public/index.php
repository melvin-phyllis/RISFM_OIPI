<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';

// Autoloader classmap simple (sans dependre de composer pour les classes maison)
spl_autoload_register(function (string $class): void {
    $dirs = ['core', 'models', 'controllers'];
    foreach ($dirs as $dir) {
        $file = BASE_PATH . "/{$dir}/{$class}.php";
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Autoloader Composer (PhpSpreadsheet, PHPWord, Dompdf) - genere par `composer install`
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once BASE_PATH . '/core/helpers.php';

// Doit etre actif avant toute connexion MySQL ou initialisation de session.
ErrorHandler::register();
EnvironmentGuard::assertSafeForRuntime();

Auth::bootSession();
Security::sendSecurityHeaders();
Csrf::verifyRequestOrFail();

$router = new Router();

$routeGroups = require BASE_PATH . '/config/routes.php';
foreach ($routeGroups as $routes) {
    foreach ($routes as [$method, $path, $controller, $action]) {
        match ($method) {
            'GET' => $router->get($path, $controller, $action),
            'POST' => $router->post($path, $controller, $action),
            default => throw new RuntimeException("Methode HTTP non prise en charge dans les routes : {$method}"),
        };
    }
}

$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'] ?? '/');
