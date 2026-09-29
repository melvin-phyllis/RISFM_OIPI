<?php
declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Security;
use App\Repositories\Utilisateur\UserRepository;
use InvalidArgumentException;
use PDO;

/**
 * Cree le premier administrateur avec des donnees fournies a l'execution.
 *
 * Aucun identifiant ni mot de passe par defaut n'est conserve dans le code.
 * Ce seeder est appele par scripts/create_admin.php apres saisie interactive.
 */
final class AdminSeeder extends Seeder
{
    public function __construct(
        PDO $db,
        private readonly string $nom,
        private readonly string $prenoms,
        private readonly string $email,
        private readonly string $service,
        private readonly string $motDePasse
    ) {
        parent::__construct($db);
    }

    public function run(): string
    {
        $repository = new UserRepository($this->db);
        if ($repository->repo_adminCount() > 0) {
            throw new InvalidArgumentException(
                "Un administrateur existe deja. Utilisez la gestion des utilisateurs dans l'application."
            );
        }
        if ($this->nom === '' || $this->prenoms === '') {
            throw new InvalidArgumentException('Le nom et les prenoms sont obligatoires.');
        }
        if (!Security::isValidEmail($this->email)) {
            throw new InvalidArgumentException('Adresse e-mail invalide.');
        }
        if (($erreur = Security::passwordPolicyError($this->motDePasse)) !== null) {
            throw new InvalidArgumentException($erreur);
        }

        $id = $this->creerUtilisateur([
            'nom' => $this->nom,
            'prenoms' => $this->prenoms,
            'email' => mb_strtolower($this->email),
            'mot_de_passe' => password_hash($this->motDePasse, PASSWORD_DEFAULT),
            'role' => 'administrateur',
            'role_id' => $this->idPar('roles', 'code', 'administrateur'),
            'service' => $this->service,
            'actif' => 1,
            'doit_changer_mdp' => 0,
        ]);

        return sprintf(
            'administrateur %s cree pour %s',
            UserRepository::repo_generatedIdentifiant($id),
            mb_strtolower($this->email)
        );
    }
}
