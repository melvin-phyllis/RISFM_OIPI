<?php
declare(strict_types=1);

/**
 * Ecriture non critique de l'activite d'une connexion.
 *
 * Cette information alimente les indicateurs et l'expiration des connexions,
 * mais son echec ne doit jamais empecher l'utilisateur d'afficher une page.
 */
final class ConnectionActivity
{
    public static function shouldWrite(int $lastWriteAt, int $now, int $intervalSeconds): bool
    {
        return $lastWriteAt <= 0 || ($now - $lastWriteAt) >= max(1, $intervalSeconds);
    }

    /**
     * Tente une seule mise a jour avec un delai de verrou tres court.
     * Retourne false et journalise l'incident si MySQL est indisponible ou si
     * la ligne est verrouillee ; aucune exception ne remonte vers la page.
     */
    public static function touch(
        PDO $db,
        int $connectionId,
        int $userId,
        int $lockWaitSeconds = 1
    ): bool {
        if ($connectionId <= 0 || $userId <= 0) {
            return false;
        }

        $previousLockWait = null;
        $shortLockWait = max(1, min(5, $lockWaitSeconds));

        try {
            $previousLockWait = (int) $db
                ->query('SELECT @@SESSION.innodb_lock_wait_timeout')
                ->fetchColumn();

            if ($previousLockWait !== $shortLockWait) {
                $db->exec('SET SESSION innodb_lock_wait_timeout = ' . $shortLockWait);
            }

            $stmt = $db->prepare(
                "UPDATE connexions
                 SET derniere_activite = NOW()
                 WHERE id = :id
                   AND utilisateur_id = :utilisateur_id
                   AND statut = 'actif'"
            );
            $stmt->execute([
                'id' => $connectionId,
                'utilisateur_id' => $userId,
            ]);

            return true;
        } catch (Throwable $exception) {
            $category = self::isLockConflict($exception) ? 'verrou MySQL' : 'erreur MySQL';
            error_log(sprintf(
                '[SessionActivity] Mise a jour ignoree (%s) pour connexion #%d : %s',
                $category,
                $connectionId,
                $exception->getMessage()
            ));
            return false;
        } finally {
            if ($previousLockWait !== null && $previousLockWait !== $shortLockWait) {
                try {
                    $db->exec('SET SESSION innodb_lock_wait_timeout = ' . max(1, $previousLockWait));
                } catch (Throwable $restoreException) {
                    error_log('[SessionActivity] Restauration du delai MySQL impossible : ' . $restoreException->getMessage());
                }
            }
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
