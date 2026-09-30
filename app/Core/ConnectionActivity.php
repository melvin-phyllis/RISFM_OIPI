<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\Utilisateur\ConnexionRepository;
use PDO;
use PDOException;
use Throwable;

/** Ecriture non critique de l'activite d'une connexion. */
final class ConnectionActivity
{
    public static function shouldWrite(int $lastWriteAt, int $now, int $intervalSeconds): bool
    {
        return $lastWriteAt <= 0 || ($now - $lastWriteAt) >= max(1, $intervalSeconds);
    }

    /**
     * @param PDO|null $db connexion a utiliser (null = celle de l'application ;
     *                     les tests en passent une autre pour simuler un verrou)
     */
    public static function touch(
        ?PDO $db,
        int $connectionId,
        int $userId,
        int $lockWaitSeconds = 1
    ): bool {
        if ($connectionId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            return (new ConnexionRepository($db))->repo_touchActivity(
                $connectionId,
                $userId,
                $lockWaitSeconds
            );
        } catch (Throwable $exception) {
            $category = self::isLockConflict($exception) ? 'verrou MySQL' : 'erreur MySQL';
            error_log(sprintf(
                '[SessionActivity] Mise a jour ignoree (%s) pour connexion #%d : %s',
                $category,
                $connectionId,
                $exception->getMessage()
            ));
            return false;
        }
    }

    private static function isLockConflict(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        $driverCode = isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0;
        return in_array($driverCode, [1205, 1213], true)
            || in_array((string) $exception->getCode(), ['1205', '1213', '40001'], true);
    }
}
