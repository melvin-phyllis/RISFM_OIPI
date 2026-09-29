<?php
declare(strict_types=1);

namespace App\Services\Utilisateur;

use App\Core\AppMailer;
use App\Core\Logger;
use App\Core\Security;
use App\Dto\Utilisateur\CreateUserDTO;
use App\Dto\Utilisateur\DefinirMotDePasseDTO;
use App\Dto\Utilisateur\UpdateUserDTO;
use App\Repositories\Utilisateur\TokenResetRepository;
use App\Repositories\Utilisateur\UserRepository;
use DomainException;
use Throwable;

/**
 * Cycle de vie des comptes : creation, modification, suppression, activation
 * et renouvellement des acces.
 *
 * Aucun mot de passe n'est choisi ni envoye par l'administrateur a la
 * creation : l'utilisateur recoit un lien a usage unique. Un refus metier est
 * signale par une DomainException dont le message peut etre affiche.
 */
final class UserService
{
    private const VALIDITE_LIEN_MINUTES = 60;

    /**
     * Cree un compte et envoie le lien permettant de choisir son mot de passe.
     *
     * @param array $dataValidated donnees de CreateUserFormRequest
     * @return array{user: array|null, lien_envoye: bool}
     */
    public function srv_creer(array $dataValidated, int $acteurId): array
    {
        $dto = CreateUserDTO::fromArray($dataValidated);
        $repository = new UserRepository();
        if ($repository->repo_findByEmail($dto->email)) {
            throw new DomainException('Cette adresse e-mail est deja utilisee.');
        }

        $created = $repository->repo_insertWithGeneratedIdentifiant($dto->toArray() + [
            'mot_de_passe' => password_hash($this->genererMotDePasseTemporaire(), PASSWORD_DEFAULT),
            'doit_changer_mdp' => 1,
            'actif' => 1,
            'cree_par' => $acteurId,
            'role_id' => $repository->repo_roleIdParCode($dto->role),
        ]);
        Logger::log($acteurId, 'ajout', "Creation de l'utilisateur [{$created['identifiant']}]");

        $user = $repository->repo_find((int) $created['id']);
        return ['user' => $user, 'lien_envoye' => $user !== null && $this->envoyerLienAcces($user, true, $acteurId)];
    }

    /** @param array $dataValidated donnees de UpdateUserFormRequest */
    public function srv_modifier(int $userId, array $dataValidated, int $acteurId): void
    {
        $dto = UpdateUserDTO::fromArray($dataValidated);
        $repository = new UserRepository();
        $existant = $this->utilisateur($repository, $userId);

        $proprietaireEmail = $repository->repo_findByEmail($dto->email);
        if ($proprietaireEmail && (int) $proprietaireEmail['id'] !== $userId) {
            throw new DomainException('Cette adresse e-mail est deja utilisee par un autre compte.');
        }
        if (
            $existant['role'] === 'administrateur'
            && $dto->role !== 'administrateur'
            && (int) $existant['actif'] === 1
            && $repository->repo_activeAdminCount() <= 1
        ) {
            throw new DomainException('Impossible de retirer le role du dernier administrateur actif.');
        }

        $repository->repo_updateWithLifecycleGuard(
            $userId,
            $dto->toArray() + ['role_id' => $repository->repo_roleIdParCode($dto->role)]
        );
        Logger::log($acteurId, 'modification', "Modification de l'utilisateur #{$userId}");
    }

    public function srv_supprimer(int $userId, int $acteurId): void
    {
        if ($userId === $acteurId) {
            throw new DomainException('Vous ne pouvez pas supprimer votre propre compte.');
        }
        $repository = new UserRepository();
        $user = $this->utilisateur($repository, $userId);
        if ($user['role'] === 'administrateur' && $repository->repo_adminCount() <= 1) {
            throw new DomainException('Impossible de supprimer le dernier administrateur.');
        }
        $repository->repo_deleteWithRevocation($userId);
        Logger::log($acteurId, 'suppression', "Suppression de l'utilisateur #{$userId}");
    }

    public function srv_basculerStatut(int $userId, int $acteurId): void
    {
        if ($userId === $acteurId) {
            throw new DomainException('Vous ne pouvez pas desactiver votre propre compte.');
        }
        $repository = new UserRepository();
        $user = $this->utilisateur($repository, $userId);
        if (
            $user['role'] === 'administrateur'
            && (int) $user['actif'] === 1
            && $repository->repo_activeAdminCount() <= 1
        ) {
            throw new DomainException('Impossible de desactiver le dernier administrateur actif.');
        }
        $repository->repo_toggleActive($userId);
        Logger::log($acteurId, 'activation', "Activation/desactivation de l'utilisateur #{$userId}");
    }

    /**
     * Revoque les acces actuels (le mot de passe devient inutilisable) et envoie
     * un nouveau lien pour en choisir un.
     *
     * @return bool le lien a ete envoye
     */
    public function srv_renouvelerAcces(int $userId, int $acteurId): bool
    {
        $repository = new UserRepository();
        $user = $this->utilisateur($repository, $userId);
        // Le secret aleatoire n'est ni affiche, ni journalise, ni envoye par e-mail.
        $repository->repo_setPassword($userId, $this->genererMotDePasseTemporaire(), true);
        $repository->repo_clearFailedLoginAttempts($userId);
        Logger::log($acteurId, 'securite', "Revocation des acces et demande d'un nouveau mot de passe pour l'utilisateur #{$userId}");

        return $this->envoyerLienAcces($user, false, $acteurId);
    }

    /**
     * Definit directement le mot de passe d'un compte.
     *
     * @param array $dataValidated donnees de DefinirMotDePasseFormRequest
     * @return array le compte modifie
     */
    public function srv_definirMotDePasse(int $userId, array $dataValidated, int $acteurId): array
    {
        $dto = DefinirMotDePasseDTO::fromArray($dataValidated);
        $repository = new UserRepository();
        $user = $this->utilisateur($repository, $userId);
        if (($erreur = Security::passwordPolicyError($dto->mot_de_passe)) !== null) {
            throw new DomainException($erreur);
        }

        $repository->repo_setPassword($userId, $dto->mot_de_passe, $dto->forcer_changement);
        $repository->repo_clearFailedLoginAttempts($userId);
        Logger::log(
            $acteurId,
            'securite',
            "Définition manuelle du mot de passe pour l'utilisateur #{$userId} ({$user['identifiant']})",
            null,
            'utilisateur',
            $userId,
            null,
            ['force_change' => $dto->forcer_changement]
        );
        return $user;
    }

    private function utilisateur(UserRepository $repository, int $userId): array
    {
        $user = $repository->repo_find($userId);
        if (!$user) {
            throw new DomainException('Utilisateur introuvable.');
        }
        return $user;
    }

    private function envoyerLienAcces(array $user, bool $nouveauCompte, int $acteurId): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || !Security::isValidEmail((string) ($user['email'] ?? ''))) {
            return false;
        }

        try {
            $tokenRepository = new TokenResetRepository();
            $tokenRepository->repo_purgerExpires();
            $token = $tokenRepository->repo_creer($userId, self::VALIDITE_LIEN_MINUTES);
            (new AppMailer())->sendAccountAccess(
                $user,
                url('reinitialiser/' . $token),
                self::VALIDITE_LIEN_MINUTES,
                $nouveauCompte
            );
            Logger::log(
                $acteurId,
                'email',
                ($nouveauCompte ? 'Invitation de compte' : 'Lien de renouvellement d acces')
                . " envoye a l'utilisateur #{$userId}"
            );
            return true;
        } catch (Throwable $exception) {
            // Ni le mot de passe aleatoire ni le jeton ne sont inscrits dans
            // les journaux. Un nouvel envoi invalidera automatiquement ce lien.
            error_log('[Utilisateur] Echec d’envoi du lien d’accès pour utilisateur #' . $userId . ' : ' . $exception->getMessage());
            Logger::log($acteurId, 'securite', "Echec d'envoi du lien d'acces a l'utilisateur #{$userId}");
            return false;
        }
    }

    private function genererMotDePasseTemporaire(): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnpqrstuvwxyz',
            '23456789',
            '!@#$%',
        ];
        $chars = [];
        foreach ($groups as $group) {
            $chars[] = $group[random_int(0, strlen($group) - 1)];
        }

        $pool = implode('', $groups);
        while (count($chars) < 12) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
