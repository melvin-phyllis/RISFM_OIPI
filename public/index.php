<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\EnvironmentGuard;
use App\Core\ErrorHandler;
use App\Core\Router;
use App\Core\Security;

require_once dirname(__DIR__) . '/config/config.php';

require_once BASE_PATH . '/config/autoload.php';

// Autoloader Composer (PhpSpreadsheet, PHPWord, Dompdf) - genere par `composer install`
$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

require_once BASE_PATH . '/app/Core/helpers.php';

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
