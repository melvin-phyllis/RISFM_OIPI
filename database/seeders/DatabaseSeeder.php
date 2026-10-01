<?php
declare(strict_types=1);

namespace Database\Seeders;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Enchaine les seeders dans l'ordre de leurs dependances, en une seule
 * transaction : soit toutes les donnees sont ecrites, soit aucune.
 */
final class DatabaseSeeder
{
    /** Donnees indispensables a toute installation, production comprise. */
    public const REFERENCE = [
        RolePermissionSeeder::class,
        StatutSeeder::class,
        TypeTitreSeeder::class,
        LocalisationSeeder::class,
        DirectionSeeder::class,
        ServiceSeeder::class,
        ParametreSeeder::class,
    ];

    /** Donnees fictives pour les postes de developpement et la recette. */
    public const DEMO = [
        UtilisateurDemoSeeder::class,
        FormulaireDemoSeeder::class,
    ];

    /** Noms utilisables avec scripts/seed.php --only=nom. */
    public const NAMED = [
        'roles' => RolePermissionSeeder::class,
        'statuts' => StatutSeeder::class,
        'types-titres' => TypeTitreSeeder::class,
        'localisations' => LocalisationSeeder::class,
        'directions' => DirectionSeeder::class,
        'services' => ServiceSeeder::class,
        'parametres' => ParametreSeeder::class,
        'admin' => AdminSeeder::class,
        'utilisateurs-demo' => UtilisateurDemoSeeder::class,
        'formulaires-demo' => FormulaireDemoSeeder::class,
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param bool $admin cree le premier administrateur (AdminSeeder)
     * @param bool $demo  ajoute les comptes et formulaires de demonstration
     * @return array<string, string> resume par seeder
     */
    public function run(bool $admin = true, bool $demo = false): array
    {
        $seeders = self::REFERENCE;
        if ($admin || $demo) {
            $seeders[] = AdminSeeder::class;
        }
        if ($demo) {
            array_push($seeders, ...self::DEMO);
        }

        return $this->runSeeders($seeders);
    }

    /** @return array<string, string> */
    public function runOnly(string $name): array
    {
        $class = self::NAMED[$name] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException(
                'Seeder inconnu : ' . $name . '. Valeurs acceptees : ' . implode(', ', array_keys(self::NAMED))
            );
        }

        return $this->runSeeders([$class]);
    }

    /**
     * @param list<class-string<Seeder>> $seeders
     * @return array<string, string>
     */
    private function runSeeders(array $seeders): array
    {
        $this->db->beginTransaction();
        try {
            $resume = [];
            foreach ($seeders as $class) {
                $resume[$class] = (new $class($this->db))->run();
            }
            $this->db->commit();
            return $resume;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }
}
