<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Connexion PDO unique (singleton) a la base MySQL.
 * Toutes les requetes de l'application doivent transiter par cette classe
 * et utiliser exclusivement des requetes preparees (protection injections SQL).
 */
class Database
{
    private static ?PDO $instance = null;

    private function __construct()
    {

    }

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $cfg = require BASE_PATH . '/config/database.php';
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $cfg['host'],
                $cfg['port'],
                $cfg['dbname'],
                $cfg['charset']
            );

            try {
                self::$instance = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                ]);
            } catch (PDOException $e) {
                throw new RuntimeException('Connexion a la base de donnees impossible.', 0, $e);
            }
        }

        return self::$instance;
    }

    /**
     * Execute $operation dans une transaction : validation si elle se termine
     * normalement, annulation puis relance de l'exception sinon. L'appelant
     * garde ainsi la main sur le message presente a l'utilisateur.
     *
     * @template T
     * @param callable(PDO): T $operation
     * @return T
     */
    public static function transaction(callable $operation): mixed
    {
        $db = self::getConnection();
        $db->beginTransaction();
        try {
            $result = $operation($db);
            $db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }



    private function __clone()
    {
    }
}
