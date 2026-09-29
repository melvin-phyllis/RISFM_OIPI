<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\Utilisateur\UserRepository;

/**
 * Premier compte administrateur.
 *
 * Le compte est cree s'il n'existe pas. S'il existe deja, seul son mot de
 * passe est reinitialise et un changement est impose a la prochaine connexion.
 */
final class AdminSeeder extends Seeder
{
    public const EMAIL = 'admin@oipi.ci';
    public const MOT_DE_PASSE = 'Admin_Oipi2026#';

    private const NOM = 'Administrateur';
    private const PRENOMS = 'Systeme';
    private const SERVICE = 'Direction Generale';

    public function run(): string
    {
        $existant = $this->db->query(
            "SELECT id, identifiant FROM utilisateurs WHERE role = 'administrateur' ORDER BY id LIMIT 1"
        )->fetch();
        if ($existant !== false) {
            $this->db->prepare(
                'UPDATE utilisateurs
                 SET mot_de_passe = :mot_de_passe, doit_changer_mdp = 1
                 WHERE id = :id'
            )->execute([
                'mot_de_passe' => password_hash(self::MOT_DE_PASSE, PASSWORD_DEFAULT),
                'id' => (int) $existant['id'],
            ]);

            return sprintf(
                'administrateur deja present (%s), mot de passe reinitialise',
                $existant['identifiant']
            );
        }

        $id = $this->creerUtilisateur([
            'nom' => self::NOM,
            'prenoms' => self::PRENOMS,
            'email' => self::EMAIL,
            'mot_de_passe' => password_hash(self::MOT_DE_PASSE, PASSWORD_DEFAULT),
            'role' => 'administrateur',
            'role_id' => $this->idPar('roles', 'code', 'administrateur'),
            'service' => self::SERVICE,
            'actif' => 1,
            'doit_changer_mdp' => 1,
        ]);

        return sprintf(
            'administrateur %s cree : %s / %s (mot de passe a changer a la premiere connexion)',
            UserRepository::repo_generatedIdentifiant($id),
            self::EMAIL,
            self::MOT_DE_PASSE
        );
    }
}
