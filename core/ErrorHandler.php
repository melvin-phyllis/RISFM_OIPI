<?php
declare(strict_types=1);

/** Gestion centrale des exceptions et erreurs fatales non interceptees. */
final class ErrorHandler
{
    private static bool $handling = false;

    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleException(Throwable $exception): never
    {
        self::$handling = true;
        $incidentId = self::incidentId();
        self::log($exception, $incidentId);

        if (PHP_SAPI === 'cli') {
            $message = APP_DEBUG
                ? sprintf("Erreur non geree [%s] %s: %s\n", $incidentId, $exception::class, $exception->getMessage())
                : sprintf("Une erreur technique est survenue. Reference : %s\n", $incidentId);
            fwrite(STDERR, $message);
            exit(1);
        }

        if (!headers_sent()) {
            http_response_code(500);
            header('Cache-Control: no-store, no-cache, must-revalidate');
            header('X-Incident-ID: ' . $incidentId);
        }

        if (self::expectsJson()) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=UTF-8');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Une erreur technique est survenue.',
                'reference' => $incidentId,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit(1);
        }

        $debugException = APP_DEBUG ? $exception : null;
        $view = BASE_PATH . '/views/errors/500.php';
        if (is_file($view)) {
            require $view;
        } else {
            echo '<h1>Erreur 500</h1><p>Une erreur technique est survenue.</p>';
        }
        exit(1);
    }

    public static function handleShutdown(): void
    {
        if (self::$handling) {
            return;
        }

        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        self::handleException(new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        ));
    }

    private static function log(Throwable $exception, string $incidentId): void
    {
        $chain = [];
        $current = $exception;
        do {
            $chain[] = sprintf(
                '%s: %s dans %s:%d',
                $current::class,
                $current->getMessage(),
                $current->getFile(),
                $current->getLine()
            );
            $current = $current->getPrevious();
        } while ($current !== null);

        $method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
        $uri = self::sanitizePath(parse_url($_SERVER['REQUEST_URI'] ?? '-', PHP_URL_PATH) ?: '-');
        error_log(sprintf(
            "[Incident %s] %s %s | %s\nTrace:\n%s",
            $incidentId,
            $method,
            $uri,
            implode(' | Cause: ', $chain),
            $exception->getTraceAsString()
        ));
    }

    private static function expectsJson(): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        return str_contains($path, '/api/') || str_contains($accept, 'application/json');
    }

    private static function sanitizePath(string $path): string
    {
        return (string) preg_replace(
            '#(/reinitialiser/)[a-f0-9]{32,128}#i',
            '$1[JETON_MASQUE]',
            $path
        );
    }

    private static function incidentId(): string
    {
        try {
            return strtoupper(bin2hex(random_bytes(4)));
        } catch (Throwable) {
            return strtoupper(substr(hash('sha256', uniqid('', true)), 0, 8));
        }
    }
}
