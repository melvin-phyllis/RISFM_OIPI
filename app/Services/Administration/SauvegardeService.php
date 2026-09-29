<?php
declare(strict_types=1);

namespace App\Services\Administration;

use App\Core\DatabaseBackup;
use App\Core\Logger;
use App\Core\Security;
use App\Dto\Administration\RestaurerSauvegardeDTO;
use App\Repositories\Administration\SauvegardeRepository;
use App\Repositories\Utilisateur\UserRepository;
use DomainException;
use Throwable;

/**
 * Sauvegardes SQL de la base : creation controlee, restauration et suppression.
 *
 * Une restauration n'est executee qu'apres un test complet dans une base
 * temporaire et une sauvegarde de securite de l'etat courant. Chaque echec est
 * signale par une DomainException dont le message peut etre affiche.
 */
final class SauvegardeService
{
    /** Cree une sauvegarde complete et verifie son integrite. */
    public function srv_creer(int $acteurId): void
    {
        $fichier = 'risfm_backup_' . date('Ymd_His') . '.sql';
        $chemin = STORAGE_PATH . '/backups/' . $fichier;

        Security::ensureDirectory(STORAGE_PATH . '/backups');
        $backup = new DatabaseBackup();
        $result = $backup->createDump($chemin);
        $success = (bool) ($result['success'] ?? false);
        if ($success && !empty($result['warning'])) {
            error_log('[Sauvegarde] ' . $result['warning']);
        }
        if (!$success) {
            if (is_file($chemin)) {
                unlink($chemin);
            }
            $result = $backup->createPhpDump($chemin);
            $success = (bool) ($result['success'] ?? false);
        }
        if (!$success) {
            throw new DomainException("Echec de la sauvegarde. Verifiez que 'mysqldump' est disponible sur le serveur ou consultez le journal PHP.");
        }

        chmod($chemin, 0640);
        $validation = $backup->validateSqlFile($chemin);
        if (!$validation['valid']) {
            error_log('[Sauvegarde] Export produit mais invalide : ' . $validation['error']);
            unlink($chemin);
            throw new DomainException('La sauvegarde produite a echoue au controle d\'integrite et a ete supprimee.');
        }

        (new SauvegardeRepository())->repo_insert([
            'nom_fichier' => $fichier,
            'taille_octets' => filesize($chemin),
            'cree_par' => $acteurId,
        ]);
        Logger::log($acteurId, 'sauvegarde', "Creation de la sauvegarde {$fichier} [sha256: " . substr((string) $validation['sha256'], 0, 16) . '...]');
    }

    /**
     * Restaure la base principale a partir d'un fichier SQL.
     *
     * @param array $dataValidated donnees de RestaurerSauvegardeFormRequest
     * @return string nom de la sauvegarde de securite conservee
     */
    public function srv_restaurer(array $dataValidated, int $acteurId): string
    {
        $dto = RestaurerSauvegardeDTO::fromArray($dataValidated);
        $user = (new UserRepository())->repo_find($acteurId);
        if (!$user || !password_verify($dto->mot_de_passe_actuel, (string) $user['mot_de_passe'])) {
            Logger::log($acteurId, 'securite', 'Tentative de restauration refusee : confirmation critique invalide');
            throw new DomainException('Confirmation refusee. Cochez l\'avertissement, saisissez exactement RESTAURER OIPI et confirmez votre mot de passe actuel.');
        }
        if (!is_uploaded_file($dto->fichier['tmp_name'])) {
            throw new DomainException('Le fichier doit etre un export .sql valide.');
        }
        if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
            throw new DomainException('Le dossier temporaire est indisponible.');
        }
        $tmpPath = STORAGE_PATH . '/tmp/' . Security::safeFilename($dto->fichier['name']);
        if (!move_uploaded_file($dto->fichier['tmp_name'], $tmpPath)) {
            throw new DomainException('Impossible de placer le fichier dans la zone temporaire securisee.');
        }
        chmod($tmpPath, 0600);

        try {
            $backup = new DatabaseBackup();
            $validation = $backup->validateSqlFile($tmpPath);
            if (!$validation['valid']) {
                Logger::log($acteurId, 'securite', 'Restauration refusee : fichier SQL invalide');
                throw new DomainException('Restauration refusee : ' . $validation['error']);
            }

            $test = $backup->testRestore($tmpPath);
            if (!$test['success']) {
                error_log('[Restauration] Test temporaire refuse : ' . $test['error']);
                Logger::log($acteurId, 'securite', 'Restauration annulee : echec du test sur base temporaire');
                throw new DomainException('Restauration annulee : le test dans la base temporaire a echoue. La base principale n\'a pas ete modifiee. Consultez le journal serveur.');
            }

            // Dernier filet de securite : une sauvegarde complete de l'etat
            // courant est creee et testee juste avant de modifier la base.
            $safety = $this->creerSauvegardeSecurite($backup);
            if (!$safety['success']) {
                error_log('[Restauration] Sauvegarde de securite impossible : ' . ($safety['error'] ?? 'erreur inconnue'));
                throw new DomainException('Restauration annulee : impossible de creer et tester la sauvegarde de securite prealable. La base principale n\'a pas ete modifiee.');
            }
            $safetyFile = (string) $safety['filename'];
            Logger::log($acteurId, 'sauvegarde', "Sauvegarde automatique avant restauration : {$safetyFile}");

            $restore = $backup->restoreToMainDatabase($tmpPath);
            if (!$restore['success']) {
                error_log('[Restauration] Import principal echoue : ' . ($restore['error'] ?? 'erreur inconnue'));
                $this->indexerFichier($safetyFile, null);
                throw new DomainException("La restauration principale a echoue. La sauvegarde de securite {$safetyFile} a ete conservee. Consultez immediatement le journal serveur.");
            }

            // La table sauvegardes vient elle-meme d'etre restauree : on y
            // reinscrit le filet de securite avec une FK neutre et portable.
            $this->indexerFichier($safetyFile, null);
            Logger::log(
                null,
                'securite',
                "RESTAURATION demandee par l'utilisateur #{$acteurId}, effectuee apres test temporaire et sauvegarde {$safetyFile} [sha256: "
                . substr((string) $validation['sha256'], 0, 16) . '...]'
            );
            return $safetyFile;
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
    }

    public function srv_supprimer(string $fichier, int $acteurId): void
    {
        $fichier = basename($fichier);
        $chemin = STORAGE_PATH . '/backups/' . $fichier;
        if (is_file($chemin)) {
            @unlink($chemin);
        }
        (new SauvegardeRepository())->repo_supprimerFichier($fichier);
        Logger::log($acteurId, 'suppression', "Suppression de la sauvegarde {$fichier}");
    }

    /** @return array{success:bool,filename?:string,error:?string} */
    private function creerSauvegardeSecurite(DatabaseBackup $backup): array
    {
        if (!Security::ensureDirectory(STORAGE_PATH . '/backups')) {
            return ['success' => false, 'error' => 'Dossier de sauvegarde indisponible.'];
        }

        $filename = 'risfm_before_restore_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.sql';
        $path = STORAGE_PATH . '/backups/' . $filename;
        $result = $backup->createDump($path);
        if (empty($result['success'])) {
            if (is_file($path)) {
                unlink($path);
            }
            $result = $backup->createPhpDump($path);
        }
        if (empty($result['success'])) {
            if (is_file($path)) {
                unlink($path);
            }
            return ['success' => false, 'error' => (string) ($result['error'] ?? 'Export impossible.')];
        }

        chmod($path, 0640);
        $validation = $backup->validateSqlFile($path);
        if (!$validation['valid']) {
            unlink($path);
            return ['success' => false, 'error' => (string) $validation['error']];
        }

        $test = $backup->testRestore($path);
        if (!$test['success']) {
            unlink($path);
            return ['success' => false, 'error' => (string) $test['error']];
        }

        return ['success' => true, 'filename' => $filename, 'error' => null];
    }

    private function indexerFichier(string $filename, ?int $creatorId): void
    {
        $path = STORAGE_PATH . '/backups/' . basename($filename);
        if (!is_file($path)) {
            return;
        }
        try {
            (new SauvegardeRepository())->repo_enregistrerFichier(basename($filename), (int) filesize($path), $creatorId);
        } catch (Throwable $exception) {
            error_log('[Sauvegarde] Fichier de securite conserve mais indexation impossible : ' . $exception->getMessage());
        }
    }
}
