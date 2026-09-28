<?php
declare(strict_types=1);

/**
 * Controleur de base : rendu de vues (layout AdminLTE), reponses JSON, redirections.
 */
abstract class Controller
{
    protected function render(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        if ($layout === 'layouts/app' && !array_key_exists('__missionLoginReminder', $data)) {
            $data['__missionLoginReminder'] = MissionLoginReminder::consume();
        }
        extract($data, EXTR_SKIP);
        $viewFile = BASE_PATH . '/views/' . $view . '.php';
        if (!is_file($viewFile)) {
            throw new RuntimeException("Vue introuvable: {$view}");
        }

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if ($layout === null) {
            echo $content;
            return;
        }

        $layoutFile = BASE_PATH . '/views/' . $layout . '.php';
        require $layoutFile;
    }

    protected function renderPlain(string $view, array $data = []): void
    {
        $this->render($view, $data, null);
    }

    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function redirect(string $path): never
    {
        redirect($path);
    }

    protected function input(string $key, $default = null)
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    protected function requirePermission(string $permission): void
    {
        Auth::requireLogin();
        Permission::requireOrFail($permission);
    }
}
