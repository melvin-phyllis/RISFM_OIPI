<?php
declare(strict_types=1);

class SauvegardeController extends Controller
{
    private function ensureAdmin(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('sauvegardes.manage');
    }

    public function index(): void
    {
        $this->ensureAdmin();
        $backup = new DatabaseBackup();
        $rows = Database::getConnection()->query(
            "SELECT s.*,
                    NULLIF(TRIM(CONCAT_WS(' ', u.nom, u.prenoms)), '') AS createur_nom
               FROM sauvegardes s
          LEFT JOIN utilisateurs u ON u.id = s.cree_par
           ORDER BY s.cree_le DESC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $totalBytes = 0;
        $availableCount = 0;
        $safetyCount = 0;
        $latestAvailableAt = null;
        foreach ($rows as &$row) {
            $filename = basename((string) ($row['nom_fichier'] ?? ''));
            $path = STORAGE_PATH . '/backups/' . $filename;
            $row['fichier_disponible'] = $filename !== '' && is_file($path);
            $row['sauvegarde_securite'] = str_starts_with($filename, 'risfm_before_restore_');

            if ($row['fichier_disponible']) {
                $availableCount++;
                $actualSize = filesize($path);
                if ($actualSize !== false) {
                    $row['taille_octets'] = $actualSize;
                }
                $totalBytes += (int) ($row['taille_octets'] ?? 0);
                $latestAvailableAt ??= (string) ($row['cree_le'] ?? '');
            }
            if ($row['sauvegarde_securite']) {
                $safetyCount++;
            }
        }
        unset($row);

        $latestAgeHours = null;
        if ($latestAvailableAt) {
            try {
                $latestAgeHours = max(0, (int) floor((time() - (new DateTimeImmutable($latestAvailableAt))->getTimestamp()) / 3600));
            } catch (Throwable) {
                $latestAgeHours = null;
            }
        }

        $manifest = $backup->manifest();
        $integrity = $backup->verifyCurrentDatabase();
        $this->render('sauvegardes/index', [
            '__title' => 'Sauvegardes',
            '__active' => 'sauvegardes',
            '__hide_page_header' => true,
            'sauvegardes' => $rows,
            'execDisponible' => $backup->processAvailable(),
            'backupManifest' => $manifest,
            'databaseIntegrity' => $integrity,
            'backupSummary' => [
                'total' => count($rows),
                'available' => $availableCount,
                'missing' => count($rows) - $availableCount,
                'safety' => $safetyCount,
                'total_bytes' => $totalBytes,
                'latest_at' => $latestAvailableAt,
                'latest_age_hours' => $latestAgeHours,
                'manifest_objects' => count($manifest['tables'])
                    + count($manifest['views'])
                    + count($manifest['procedures'])
                    + count($manifest['triggers']),
            ],
        ]);
    }

    public function create(): void
    {
        $this->ensureAdmin();

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
            setFlash('error', "Echec de la sauvegarde. Verifiez que 'mysqldump' est disponible sur le serveur ou consultez le journal PHP.");
            $this->redirect('sauvegardes');
            return;
        }

        chmod($chemin, 0640);
        $validation = $backup->validateSqlFile($chemin);
        if (!$validation['valid']) {
            error_log('[Sauvegarde] Export produit mais invalide : ' . $validation['error']);
            unlink($chemin);
            setFlash('error', 'La sauvegarde produite a echoue au controle d\'integrite et a ete supprimee.');
            $this->redirect('sauvegardes');
            return;
        }

        $sauvModel = new class extends Model { protected string $table = 'sauvegardes'; };
        $sauvModel->insert([
            'nom_fichier'   => $fichier,
            'taille_octets' => filesize($chemin),
            'cree_par'      => Auth::id(),
        ]);

        Logger::log(Auth::id(), 'sauvegarde', "Creation de la sauvegarde {$fichier} [sha256: " . substr((string) $validation['sha256'], 0, 16) . '...]');
        setFlash('success', 'Sauvegarde creee avec succes.');
        $this->redirect('sauvegardes');
    }

    public function download(string $fichier): void
    {
        $this->ensureAdmin();
        $fichier = basename($fichier); // anti traversee de repertoire
        $chemin = STORAGE_PATH . '/backups/' . $fichier;
        if (!is_file($chemin)) {
            setFlash('error', 'Fichier de sauvegarde introuvable.');
            $this->redirect('sauvegardes');
            return;
        }
        Logger::log(Auth::id(), 'export', "Telechargement de la sauvegarde {$fichier}");
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $fichier . '"');
        header('Content-Length: ' . filesize($chemin));
        readfile($chemin);
        exit;
    }

    public function restore(): void
    {
        $this->ensureAdmin();

        $confirmation = Security::cleanString($this->input('confirmation_critique', ''));
        $acknowledged = (string) $this->input('confirmer_perte_donnees', '') === '1';
        $currentPassword = (string) $this->input('mot_de_passe_actuel', '');
        $user = (new UserModel())->find((int) Auth::id());
        if (
            $confirmation !== 'RESTAURER OIPI'
            || !$acknowledged
            || !$user
            || !password_verify($currentPassword, (string) $user['mot_de_passe'])
        ) {
            Logger::log(Auth::id(), 'securite', 'Tentative de restauration refusee : confirmation critique invalide');
            setFlash('error', 'Confirmation refusee. Cochez l\'avertissement, saisissez exactement RESTAURER OIPI et confirmez votre mot de passe actuel.');
            $this->redirect('sauvegardes');
            return;
        }

        if (empty($_FILES['fichier_sql']['name'])) {
            setFlash('error', 'Aucun fichier selectionne.');
            $this->redirect('sauvegardes');
            return;
        }

        $file = $_FILES['fichier_sql'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (
            $file['error'] !== UPLOAD_ERR_OK
            || $ext !== 'sql'
            || (int) $file['size'] <= 0
            || (int) $file['size'] > BACKUP_MAX_MB * 1024 * 1024
            || !is_uploaded_file($file['tmp_name'])
        ) {
            setFlash('error', 'Le fichier doit etre un export .sql valide.');
            $this->redirect('sauvegardes');
            return;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mime) || !in_array($mime, ['text/plain', 'application/sql', 'application/x-sql', 'application/octet-stream'], true)) {
            setFlash('error', 'Le contenu du fichier ne correspond pas a un fichier SQL texte.');
            $this->redirect('sauvegardes');
            return;
        }

        if (!Security::ensureDirectory(STORAGE_PATH . '/tmp')) {
            setFlash('error', 'Le dossier temporaire est indisponible.');
            $this->redirect('sauvegardes');
            return;
        }
        $tmpPath = STORAGE_PATH . '/tmp/' . Security::safeFilename($file['name']);
        if (!move_uploaded_file($file['tmp_name'], $tmpPath)) {
            setFlash('error', 'Impossible de placer le fichier dans la zone temporaire securisee.');
            $this->redirect('sauvegardes');
            return;
        }
        chmod($tmpPath, 0600);

        try {
            $backup = new DatabaseBackup();
            $validation = $backup->validateSqlFile($tmpPath);
            if (!$validation['valid']) {
                Logger::log(Auth::id(), 'securite', 'Restauration refusee : fichier SQL invalide');
                setFlash('error', 'Restauration refusee : ' . $validation['error']);
                unlink($tmpPath);
                $this->redirect('sauvegardes');
                return;
            }

            $test = $backup->testRestore($tmpPath);
            if (!$test['success']) {
                error_log('[Restauration] Test temporaire refuse : ' . $test['error']);
                Logger::log(Auth::id(), 'securite', 'Restauration annulee : echec du test sur base temporaire');
                setFlash('error', 'Restauration annulee : le test dans la base temporaire a echoue. La base principale n\'a pas ete modifiee. Consultez le journal serveur.');
                unlink($tmpPath);
                $this->redirect('sauvegardes');
                return;
            }

            // Dernier filet de securite : une sauvegarde complete de l'etat
            // courant est creee et testee juste avant de modifier la base.
            $safety = $this->createSafetyBackup($backup);
            if (!$safety['success']) {
                error_log('[Restauration] Sauvegarde de securite impossible : ' . ($safety['error'] ?? 'erreur inconnue'));
                setFlash('error', 'Restauration annulee : impossible de creer et tester la sauvegarde de securite prealable. La base principale n\'a pas ete modifiee.');
                unlink($tmpPath);
                $this->redirect('sauvegardes');
                return;
            }

            $safetyFile = (string) $safety['filename'];
            $actorId = (int) Auth::id();
            Logger::log($actorId, 'sauvegarde', "Sauvegarde automatique avant restauration : {$safetyFile}");

            $restore = $backup->restoreToMainDatabase($tmpPath);
            if (!$restore['success']) {
                error_log('[Restauration] Import principal echoue : ' . ($restore['error'] ?? 'erreur inconnue'));
                $this->registerBackupFile($safetyFile, null);
                setFlash('error', "La restauration principale a echoue. La sauvegarde de securite {$safetyFile} a ete conservee. Consultez immediatement le journal serveur.");
                unlink($tmpPath);
                $this->redirect('sauvegardes');
                return;
            }

            // La table sauvegardes vient elle-meme d'etre restauree : on y
            // reinscrit le filet de securite avec une FK neutre et portable.
            $this->registerBackupFile($safetyFile, null);
            Logger::log(
                null,
                'securite',
                "RESTAURATION demandee par l'utilisateur #{$actorId}, effectuee apres test temporaire et sauvegarde {$safetyFile} [sha256: "
                . substr((string) $validation['sha256'], 0, 16) . '...]'
            );
            setFlash('success', "Restauration effectuee avec succes. La sauvegarde de securite {$safetyFile} a ete conservee.");
        } finally {
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }
        }
        $this->redirect('sauvegardes');
    }

    /** @return array{success:bool,filename?:string,error:?string} */
    private function createSafetyBackup(DatabaseBackup $backup): array
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

    private function registerBackupFile(string $filename, ?int $creatorId): void
    {
        $path = STORAGE_PATH . '/backups/' . basename($filename);
        if (!is_file($path)) {
            return;
        }

        try {
            $db = Database::getConnection();
            $name = basename($filename);
            $existing = $db->prepare('SELECT id FROM sauvegardes WHERE nom_fichier = :nom LIMIT 1');
            $existing->execute(['nom' => $name]);
            $id = $existing->fetchColumn();
            if ($id !== false) {
                $update = $db->prepare('UPDATE sauvegardes SET taille_octets = :taille WHERE id = :id');
                $update->execute(['taille' => filesize($path), 'id' => $id]);
            } else {
                $insert = $db->prepare(
                    'INSERT INTO sauvegardes (nom_fichier, taille_octets, cree_par, cree_le)
                     VALUES (:nom, :taille, :cree_par, NOW())'
                );
                $insert->execute([
                    'nom' => $name,
                    'taille' => filesize($path),
                    'cree_par' => $creatorId,
                ]);
            }
        } catch (Throwable $exception) {
            error_log('[Sauvegarde] Fichier de securite conserve mais indexation impossible : ' . $exception->getMessage());
        }
    }

    public function destroy(string $fichier): void
    {
        $this->ensureAdmin();
        $fichier = basename($fichier);
        $chemin = STORAGE_PATH . '/backups/' . $fichier;
        if (is_file($chemin)) {
            @unlink($chemin);
        }
        $sauvModel = new class extends Model { protected string $table = 'sauvegardes'; };
        $stmt = Database::getConnection()->prepare('DELETE FROM sauvegardes WHERE nom_fichier = :f');
        $stmt->execute(['f' => $fichier]);
        Logger::log(Auth::id(), 'suppression', "Suppression de la sauvegarde {$fichier}");
        setFlash('success', 'Sauvegarde supprimee.');
        $this->redirect('sauvegardes');
    }
}
