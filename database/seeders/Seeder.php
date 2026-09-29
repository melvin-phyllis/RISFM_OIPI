<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\Utilisateur\UserRepository;
use PDO;
use RuntimeException;

/**
 * Base des seeders : remplissent les donnees d'une base dont les tables ont
 * deja ete creees par schema.sql et les migrations.
 *
 * Un seeder peut etre relance : les donnees de reference existantes sont
 * conservees. AdminSeeder constitue l'exception explicite et reinitialise le
 * mot de passe du premier administrateur.
 */
abstract class Seeder
{
    public function __construct(protected readonly PDO $db)
    {
    }

    /** Remplit les donnees et retourne un resume d'une ligne. */
    abstract public function run(): string;

    /**
     * Insere les lignes dont la cle unique n'existe pas encore.
     *
     * @param list<array<string, mixed>> $rows lignes a colonnes identiques
     * @return int nombre de lignes reellement ajoutees
     */
    protected function insererSiAbsent(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        $columns = array_keys($rows[0]);
        $stmt = $this->db->prepare(sprintf(
            // "id = id" ne change rien : seule la cle en double est ignoree,
            // toute autre erreur (cle etrangere, type) reste signalee.
            'INSERT INTO `%s` (`%s`) VALUES (%s) ON DUPLICATE KEY UPDATE `%s` = `%s`',
            $table,
            implode('`, `', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
            $columns[0],
            $columns[0]
        ));

        $ajoutees = 0;
        foreach ($rows as $row) {
            $stmt->execute($row);
            $ajoutees += $stmt->rowCount() === 1 ? 1 : 0;
        }
        return $ajoutees;
    }

    /** Identifiant d'une ligne de reference designee par une colonne unique. */
    protected function idPar(string $table, string $column, string $value): int
    {
        $stmt = $this->db->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = :v LIMIT 1");
        $stmt->execute(['v' => $value]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new RuntimeException("{$table}.{$column} = {$value} introuvable : lancez d'abord les seeders de reference.");
        }
        return (int) $id;
    }

    protected function compter(string $table): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    /**
     * Cree un compte avec l'identifiant officiel OIPI-RISFM-XXXXXX, derive de
     * son ID comme dans l'application.
     */
    protected function creerUtilisateur(array $data): int
    {
        $data['identifiant'] = 'TMP-SEED-' . bin2hex(random_bytes(8));
        $columns = array_keys($data);
        $this->db->prepare(sprintf(
            'INSERT INTO utilisateurs (`%s`) VALUES (%s)',
            implode('`, `', $columns),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        ))->execute($data);
        $id = (int) $this->db->lastInsertId();
        $this->db->prepare('UPDATE utilisateurs SET identifiant = :identifiant WHERE id = :id')
            ->execute(['identifiant' => UserRepository::repo_generatedIdentifiant($id), 'id' => $id]);
        return $id;
    }
}
