<?php
declare(strict_types=1);

/**
 * Routeur minimaliste base sur des expressions de chemin avec parametres {id}.
 * Exemple : $router->get('/formulaires/edit/{id}', FormulaireController::class, 'edit');
 *
 * Utilise basePath() (core/helpers.php) pour retirer automatiquement du chemin
 * demande le sous-dossier de montage de l'application (ex: "/RISFM/public"),
 * afin que les routes definies sans prefixe ("/login") continuent de
 * correspondre meme si le DocumentRoot Apache ne pointe pas directement sur
 * public/ (cas frequent sur un poste XAMPP/WAMP local).
 */
class Router
{
    private array $routes = [];

    public function get(string $path, string $controller, string $action): void
    {
        $this->add('GET', $path, $controller, $action);
    }

    public function post(string $path, string $controller, string $action): void
    {
        $this->add('POST', $path, $controller, $action);
    }

    public function any(string $path, string $controller, string $action): void
    {
        $this->add('GET', $path, $controller, $action);
        $this->add('POST', $path, $controller, $action);
    }

    private function add(string $method, string $path, string $controller, string $action): void
    {
        $this->routes[] = compact('method', 'path', 'controller', 'action');
    }

    public function dispatch(string $method, string $uri): void
    {
        $uri = parse_url($uri, PHP_URL_PATH) ?? '/';

        $base = function_exists('basePath') ? basePath() : '';
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base));
        }

        $uri = '/' . trim($uri, '/');

        // Tolerance : si l'URL contient encore un segment "public" en tete
        // (acces direct type .../public/login au lieu de .../login), on l'ignore
        // pour que les deux formes d'URL mènent aux mêmes routes.
        if ($uri === '/public' || str_starts_with($uri, '/public/')) {
            $uri = '/' . trim(substr($uri, strlen('/public')), '/');
        }

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            $pattern = preg_replace('#\{[a-zA-Z_]+\}#', '([^/]+)', $route['path']);
            $pattern = '#^' . $pattern . '$#';

            if (preg_match($pattern, $uri, $matches)) {
                array_shift($matches);
                $controllerClass = $route['controller'];
                $action = $route['action'];

                if (!class_exists($controllerClass)) {
                    throw new RuntimeException("Controleur introuvable: {$controllerClass}");
                }
                $instance = new $controllerClass();
                if (!method_exists($instance, $action)) {
                    throw new RuntimeException("Action introuvable: {$controllerClass}::{$action}");
                }
                call_user_func_array([$instance, $action], $matches);
                return;
            }
        }

        $this->abort(404, 'Page introuvable');
    }

    private function abort(int $code, string $message): void
    {
        http_response_code($code);
        $view = BASE_PATH . '/views/errors/' . ($code === 404 ? '404' : '500') . '.php';
        if (is_file($view)) {
            require $view;
        } else {
            echo $message;
        }
        exit;
    }
}
