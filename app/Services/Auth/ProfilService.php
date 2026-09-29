<?php
declare(strict_types=1);

namespace App\Services\Auth;

use App\Core\Logger;
use App\Core\Security;
use App\Dto\Auth\ChangerMotDePasseDTO;
use App\Dto\Auth\UpdateProfilDTO;
use App\Repositories\Utilisateur\UserRepository;
use DomainException;
use PDOException;

/**
 * Profil personnel : informations, photo et mot de passe de l'utilisateur
 * connecte. Un refus metier est signale par une DomainException.
 */
final class ProfilService
{
    /** @param array $dataValidated donnees de UpdateProfilFormRequest */
    public function srv_modifier(int $userId, array $dataValidated): void
    {
        $dto = UpdateProfilDTO::fromArray($dataValidated);
        $repository = new UserRepository();
        $existant = $repository->repo_find($userId);
        if (!$existant) {
            throw new DomainException('Compte utilisateur introuvable.');
        }

        $proprietaireEmail = $repository->repo_findByEmail($dto->email);
        if ($proprietaireEmail && (int) $proprietaireEmail['id'] !== $userId) {
            throw new DomainException('Cette adresse e-mail est deja utilisee par un autre compte.');
        }

        $data = [
            'nom' => $dto->nom,
            'prenoms' => $dto->prenoms,
            'email' => $dto->email,
            'telephone' => $dto->telephone,
        ];
        $photo = $dto->photo !== null ? $this->enregistrerPhoto($dto->photo) : null;
        if ($photo !== null) {
            $data['photo'] = $photo;
        }

        try {
            $repository->repo_update($userId, $data);
        } catch (PDOException $e) {
            if ($photo !== null) {
                $this->supprimerPhoto($photo);
            }
            if ($e->getCode() === '23000') {
                throw new DomainException('Cette adresse e-mail est deja utilisee par un autre compte.');
            }
            throw $e;
        }

        if ($photo !== null && !empty($existant['photo']) && $existant['photo'] !== $photo) {
            $this->supprimerPhoto((string) $existant['photo']);
        }
        Logger::log($userId, 'modification', 'Mise a jour du profil personnel');
    }

    /**
     * Change le mot de passe. Hors changement impose, le mot de passe actuel
     * est exige : l'etat "impose" vient de la base, jamais du formulaire.
     *
     * @param array $dataValidated donnees de ChangerMotDePasseFormRequest
     * @param int|null $connexionId session a conserver ; les autres sont revoquees
     */
    public function srv_changerMotDePasse(int $userId, array $dataValidated, ?int $connexionId): void
    {
        $dto = ChangerMotDePasseDTO::fromArray($dataValidated);
        $repository = new UserRepository();
        $user = $repository->repo_find($userId);
        $impose = $user && (int) $user['doit_changer_mdp'] === 1;

        if (!$impose && (!$user || !password_verify($dto->actuel, $user['mot_de_passe']))) {
            throw new DomainException('Le mot de passe actuel est incorrect.');
        }
        if ($dto->nouveau !== $dto->confirmation) {
            throw new DomainException('Les deux nouveaux mots de passe ne correspondent pas.');
        }
        if (($erreur = Security::passwordPolicyError($dto->nouveau)) !== null) {
            throw new DomainException($erreur);
        }

        $repository->repo_setPassword($userId, $dto->nouveau, false, $connexionId);
        $repository->repo_clearFailedLoginAttempts($userId);
        Logger::log($userId, 'securite', 'Changement de mot de passe personnel');
    }

    /** @param array{name:string, tmp_name:string} $fichier fichier deja valide par le FormRequest */
    private function enregistrerPhoto(array $fichier): string
    {
        $nom = Security::safeFilename($fichier['name']);
        $dossier = UPLOADS_PATH . '/photos';
        if (!Security::ensureDirectory($dossier)) {
            throw new DomainException('Le dossier des photos est indisponible.');
        }
        if (!move_uploaded_file($fichier['tmp_name'], $dossier . '/' . $nom)) {
            throw new DomainException('La photo n\'a pas pu etre enregistree sur le serveur.');
        }
        return $nom;
    }

    private function supprimerPhoto(string $nom): void
    {
        $nom = basename($nom);
        if ($nom === '') {
            return;
        }
        $chemin = UPLOADS_PATH . '/photos/' . $nom;
        if (is_file($chemin) && !unlink($chemin)) {
            error_log('[RISFM Profil] Impossible de supprimer l\'ancienne photo : ' . $nom);
        }
    }
}
