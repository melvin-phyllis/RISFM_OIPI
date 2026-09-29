<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\Administration\ActiviteRepository;
use Throwable;

/** Journal d'audit applicatif (table `activites`). */
class Logger
{
    public static function log(
        ?int $userId,
        string $type,
        string $description,
        ?string $ip = null,
        ?string $entiteType = null,
        ?int $entiteId = null,
        ?array $avant = null,
        ?array $apres = null
    ): bool {
        try {
            $repository = new ActiviteRepository();
            return $repository->repo_journaliser(
                $userId,
                $repository->repo_acteur($userId),
                $type,
                $description,
                $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                $entiteType,
                $entiteId,
                $avant === null ? null : self::encodeContext($avant),
                $apres === null ? null : self::encodeContext($apres)
            );
        } catch (Throwable $e) {
            error_log('[Logger] Echec journalisation: ' . $e->getMessage());
            return false;
        }
    }

    private static function encodeContext(array $context): string
    {
        return json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
    }
}
