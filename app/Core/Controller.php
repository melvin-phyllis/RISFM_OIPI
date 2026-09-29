<?php
declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ValidationException;
use App\Repositories\Notification\NotificationRepository;
use RuntimeException;

/**
 * Controleur de base : rendu de vues (layout AdminLTE), reponses JSON, redirections.
 */
abstract class Controller
{
    protected function render(string $view, array $data = [], string $layout = 'layouts/app'): void
    {
        if ($layout === 'layouts/app') {
            if (!array_key_exists('__missionLoginReminder', $data)) {
                $data['__missionLoginReminder'] = MissionLoginReminder::consume();
            }
            // Compteur de la cloche, affiche par views/partials/navbar.php.
            $data['__notifCount'] ??= Auth::check()
                ? (new NotificationRepository())->repo_nonLuesCount((int) Auth::id())
                : 0;
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

    /**
     * Valide la requete POST avec un FormRequest. En cas d'erreur, affiche la
     * premiere erreur et renvoie vers $redirectOnError : le controleur n'a donc
     * ni try/catch ni message de validation a gerer.
     *
     * @param class-string<FormRequest> $requestClass
     * @param array $route parametres de la route utiles a la validation
     * @param string|null $oldInputKey cle de session ou conserver la saisie
     *                                 (hors mots de passe) pour remplir de nouveau le formulaire
     * @return array<string, mixed> donnees validees, a transmettre au service
     */
    protected function validateRequest(
        string $requestClass,
        string $redirectOnError,
        array $route = [],
        ?string $oldInputKey = null
    ): array {
        $request = new $requestClass($_POST, $_FILES, $route);
        if (!$request->authorize()) {
            http_response_code(403);
            require BASE_PATH . '/views/errors/403.php';
            exit;
        }
        try {
            return $request->validated();
        } catch (ValidationException $exception) {
            $request->failed($exception);
            if ($oldInputKey !== null) {
                $_SESSION[$oldInputKey] = $request->old();
            }
            setFlash('error', $exception->getMessage());
            $this->redirect($redirectOnError);
        }
    }
}
