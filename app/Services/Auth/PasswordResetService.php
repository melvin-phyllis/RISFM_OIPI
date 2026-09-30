<?php
declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\AppMailer;
use App\Core\Database;
use App\Core\Logger;
use App\Core\PasswordResetRateLimiter;
use App\Core\Security;
use App\Dto\Auth\MotDePasseOublieDTO;
use App\Dto\Auth\ReinitialiserMotDePasseDTO;
use App\Repositories\Utilisateur\TokenResetRepository;
use App\Repositories\Utilisateur\UserRepository;
use DomainException;
use InvalidArgumentException;
use Throwable;

/**
 * Mot de passe oublie : envoi d'un lien a usage unique, puis consommation
 * atomique de ce lien pour choisir un nouveau mot de passe.
 */
final class PasswordResetService
{
    private const VALIDITE_LIEN_MINUTES = 60;

    /**
     * Envoie un lien si un compte actif correspond a l'adresse. Le resultat
     * n'est jamais revele a l'appelant (protection contre l'enumeration).
     *
     * Au-dela du quota par adresse ou par IP, la demande est ignoree sans que
     * l'appelant le sache : chaque nouveau lien invalidant le precedent, un
     * envoi illimite empecherait le titulaire de reinitialiser son compte.
     *
     * @param array $dataValidated donnees de MotDePasseOublieFormRequest
     */
    public function srv_demander(array $dataValidated, ?string $ip = null): void
    {
        $email = MotDePasseOublieDTO::fromArray($dataValidated)->email;
        if (!(new PasswordResetRateLimiter())->attempt($email, $ip)) {
            error_log('[RISFM Securite] Demande de reinitialisation ignoree : quota atteint (IP ' . ($ip ?? 'inconnue') . ').');
            return;
        }
        $tokenRepository = new TokenResetRepository();
        $tokenRepository->repo_purgerExpires();
        $user = (new UserRepository())->repo_findByEmail($email);
        if (!$user || (int) $user['actif'] !== 1) {
            return;
        }

        $token = $tokenRepository->repo_creer((int) $user['id'], self::VALIDITE_LIEN_MINUTES);
        Logger::log((int) $user['id'], 'securite', 'Demande de reinitialisation de mot de passe');
        try {
            (new AppMailer())->sendPasswordReset($user, url('reinitialiser/' . $token), self::VALIDITE_LIEN_MINUTES);
            Logger::log((int) $user['id'], 'securite', 'E-mail de reinitialisation envoye');
        } catch (Throwable $e) {
            // Le jeton secret n'est jamais ecrit dans le journal.
            error_log('[RISFM Mail] Echec de l\'envoi de reinitialisation pour utilisateur #' . $user['id'] . ' : ' . $e->getMessage());
            Logger::log((int) $user['id'], 'securite', 'Echec de l\'envoi de l\'e-mail de reinitialisation');
        }
    }

    /**
     * Verifie la saisie puis consomme le lien.
     *
     * @param array $dataValidated donnees de ReinitialiserMotDePasseFormRequest
     * @return int|null compte modifie, ou null si le lien n'est plus valable
     * @throws DomainException saisie refusee (mots de passe differents ou trop faibles)
     */
    public function srv_reinitialiser(string $token, array $dataValidated): ?int
    {
        $dto = ReinitialiserMotDePasseDTO::fromArray($dataValidated);
        if ($dto->mot_de_passe !== $dto->confirmation) {
            throw new DomainException('Les deux nouveaux mots de passe ne correspondent pas.');
        }
        if (($erreur = Security::passwordPolicyError($dto->mot_de_passe)) !== null) {
            throw new DomainException($erreur);
        }
        $userId = $this->srv_reset($token, $dto->mot_de_passe);
        if ($userId !== null) {
            Logger::log($userId, 'securite', 'Mot de passe reinitialise via lien');
        }
        return $userId;
    }

    /**
     * Retourne l'identifiant du compte modifie, ou null si le jeton a expire,
     * a deja ete consomme ou appartient a un compte desactive.
     */
    public function srv_reset(string $token, string $newPassword): ?int
    {
        $passwordError = Security::passwordPolicyError($newPassword);
        if ($passwordError !== null) {
            throw new InvalidArgumentException($passwordError);
        }

        return Database::transaction(static function () use ($token, $newPassword): ?int {
            $tokens = new TokenResetRepository();
            $tokenRow = $tokens->repo_valide($token, true);
            if ($tokenRow === null) {
                return null;
            }

            $userId = (int) $tokenRow['utilisateur_id'];
            $users = new UserRepository();
            $user = $users->repo_find($userId);
            if ($user === null || (int) $user['actif'] !== 1) {
                // Le lien d'un compte desactive est invalide et cette
                // invalidation est conservee (la transaction est validee).
                $tokens->repo_invaliderTous($userId);
                return null;
            }

            // setPassword incremente session_version, ferme toutes les
            // connexions actives et invalide tous les jetons du compte.
            $users->repo_setPassword($userId, $newPassword, false);
            $users->repo_clearFailedLoginAttempts($userId);
            return $userId;
        });
    }
}
