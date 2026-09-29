<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Repositories\Utilisateur\UserRepository;

/**
 * Premier compte administrateur.
 *
 * Le compte n'est cree que si aucun administrateur n'existe : un compte deja
 * present n'est jamais modifie. Le mot de passe ci-dessous est public (il est
 * dans le code) : il ne sert qu'a la premiere connexion, qui impose d'en
 * choisir un nouveau.
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
            "SELECT identifiant FROM utilisateurs WHERE role = 'administrateur' ORDER BY id LIMIT 1"
        )->fetchColumn();
        if ($existant !== false) {
            return "administrateur deja present ({$existant}), aucun compte cree";
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
