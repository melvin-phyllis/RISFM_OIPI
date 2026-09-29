<?php
declare(strict_types=1);

namespace App\Http\Controllers\Administration;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\DatabaseBackup;
use App\Core\Logger;
use App\Http\Requests\Administration\RestaurerSauvegardeFormRequest;
use App\Repositories\Administration\SauvegardeRepository;
use App\Services\Administration\SauvegardeService;
use DateTimeImmutable;
use DomainException;
use Throwable;

class SauvegardeController extends Controller
{
    public function ctrl_index(): void
    {
        $this->requirePermission('sauvegardes.manage');
        $backup = new DatabaseBackup();
        $rows = (new SauvegardeRepository())->repo_toutesAvecCreateur();

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

    public function ctrl_create(): void
    {
        $this->requirePermission('sauvegardes.manage');
        try {
            (new SauvegardeService())->srv_creer((int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('sauvegardes');
            return;
        }
        setFlash('success', 'Sauvegarde creee avec succes.');
        $this->redirect('sauvegardes');
    }

    public function ctrl_download(string $fichier): void
    {
        $this->requirePermission('sauvegardes.manage');
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

    public function ctrl_restore(): void
    {
        $this->requirePermission('sauvegardes.manage');
        $data = $this->validateRequest(RestaurerSauvegardeFormRequest::class, 'sauvegardes');

        try {
            $sauvegardeSecurite = (new SauvegardeService())->srv_restaurer($data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('sauvegardes');
            return;
        }
        setFlash('success', "Restauration effectuee avec succes. La sauvegarde de securite {$sauvegardeSecurite} a ete conservee.");
        $this->redirect('sauvegardes');
    }

    public function ctrl_destroy(string $fichier): void
    {
        $this->requirePermission('sauvegardes.manage');
        (new SauvegardeService())->srv_supprimer($fichier, (int) Auth::id());
        setFlash('success', 'Sauvegarde supprimee.');
        $this->redirect('sauvegardes');
    }
}
