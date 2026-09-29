<?php
declare(strict_types=1);

namespace Database\Seeders;

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
        ParametreSeeder::class,
    ];

    /** Donnees fictives pour les postes de developpement et la recette. */
    public const DEMO = [
        UtilisateurDemoSeeder::class,
        FormulaireDemoSeeder::class,
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
