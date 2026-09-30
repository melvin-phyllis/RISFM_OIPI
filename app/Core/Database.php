<?php
declare(strict_types=1);

namespace App\Core;

use LogicException;
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

    /** Niveaux d'isolation acceptes (liste fermee : la valeur est inseree dans le SQL). */
    private const ISOLATIONS = ['READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE'];

    private static int $pointsDeReprise = 0;

    /**
     * Execute $operation dans une transaction : validation si elle se termine
     * normalement, annulation puis relance de l'exception sinon. L'appelant
     * garde ainsi la main sur le message presente a l'utilisateur.
     *
     * Appelee alors qu'une transaction est deja ouverte, elle pose un point de
     * reprise (SAVEPOINT) : un echec n'annule que son propre travail et la
     * transaction englobante decide du reste.
     *
     * @template T
     * @param callable(PDO): T $operation
     * @param string|null $isolation niveau d'isolation d'une nouvelle transaction
     *                               (ignore dans une transaction deja ouverte)
     * @return T
     */
    public static function transaction(callable $operation, ?string $isolation = null): mixed
    {
        $db = self::getConnection();
        if ($db->inTransaction()) {
            $point = 'risfm_point_' . ++self::$pointsDeReprise;
            $db->exec("SAVEPOINT {$point}");
            try {
                $result = $operation($db);
                $db->exec("RELEASE SAVEPOINT {$point}");
                return $result;
            } catch (Throwable $exception) {
                if ($db->inTransaction()) {
                    $db->exec("ROLLBACK TO SAVEPOINT {$point}");
                    $db->exec("RELEASE SAVEPOINT {$point}");
                }
                throw $exception;
            }
        }

        self::demarrer($db, $isolation);
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

    /**
     * Ouvre une transaction qui reste ouverte au-dela d'un seul appel (lecture
     * coherente pendant un export produit par lots, par exemple). Dans une
     * transaction deja ouverte, la rejoint sans rien valider ni annuler.
     */
    public static function ouvrirTransaction(?string $isolation = null): Transaction
    {
        $db = self::getConnection();
        if ($db->inTransaction()) {
            return new Transaction($db, false);
        }
        self::demarrer($db, $isolation);
        return new Transaction($db, true);
    }

    private static function demarrer(PDO $db, ?string $isolation): void
    {
        if ($isolation !== null) {
            if (!in_array($isolation, self::ISOLATIONS, true)) {
                throw new LogicException("Niveau d'isolation non pris en charge : {$isolation}");
            }
            $db->exec("SET TRANSACTION ISOLATION LEVEL {$isolation}");
        }
        $db->beginTransaction();
    }

    private function __clone()
    {
    }
}
