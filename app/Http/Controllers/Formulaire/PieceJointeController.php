<?php
declare(strict_types=1);

namespace App\Http\Controllers\Formulaire;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Logger;
use App\Core\Permission;
use App\Http\Requests\Formulaire\AjouterPieceJointeFormRequest;
use App\Repositories\Formulaire\FormulaireRepository;
use App\Repositories\Formulaire\PieceJointeRepository;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Services\Formulaire\PieceJointeService;
use DomainException;

/** Stockage prive, telechargement controle et suppression des pieces jointes. */
class PieceJointeController extends Controller
{
    public function ctrl_upload(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if (!$this->canAttach($formulaire)) {
            Permission::requireOrFail('formulaires.attach_any');
        }
        $retour = 'formulaires/voir/' . $formulaireId . '#pieces-jointes';
        $data = $this->validateRequest(AjouterPieceJointeFormRequest::class, $retour, ['formulaire_id' => $formulaireId]);

        try {
            (new PieceJointeService())->srv_ajouter($formulaireId, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        }
        setFlash('success', 'Piece jointe televersee avec succes.');
        $this->redirect($retour);
    }

    public function ctrl_download(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.view');
        $piece = (new PieceJointeRepository())->repo_find((int) $id);
        if (!$piece) {
            Logger::log(Auth::id(), 'securite', "Telechargement refuse : piece #{$id} introuvable");
            http_response_code(404);
            require BASE_PATH . '/views/errors/404.php';
            return;
        }

        $formulaire = (new FormulaireRepository())->repo_find((int) $piece['formulaire_id']);
        if (!$formulaire || ((int) ($formulaire['est_archive'] ?? 0) === 1
            && !Permission::has((string) Auth::role(), 'formulaires.archive'))
        ) {
            Logger::log(Auth::id(), 'securite', "Telechargement refuse : acces interdit a la piece #{$id}");
            http_response_code(404);
            require BASE_PATH . '/views/errors/404.php';
            return;
        }

        $filename = basename((string) $piece['nom_fichier']);
        $path = PRIVATE_UPLOADS_PATH . '/formulaires/' . $filename;
        if (!is_file($path)) {
            $legacy = UPLOADS_PATH . '/formulaires/' . $filename;
            $path = is_file($legacy) ? $legacy : $path;
        }
        if (!is_file($path)) {
            Logger::log(Auth::id(), 'securite', "Fichier physique de la piece #{$id} absent");
            http_response_code(404);
            require BASE_PATH . '/views/errors/404.php';
            return;
        }

        Logger::log(Auth::id(), 'telechargement', "Telechargement de la piece #{$id}");
        $downloadName = preg_replace('/[\r\n"\\\\]/', '_', (string) $piece['nom_original']);
        header('Content-Type: ' . ((string) $piece['type_mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store, max-age=0');
        readfile($path);
        exit;
    }

    public function ctrl_delete(string $id): void
    {
        Auth::requireLogin();
        $pieceRepository = new PieceJointeRepository();
        $piece = $pieceRepository->repo_find((int) $id);
        if ($piece) {
            $formulaire = $this->editableFormOrRedirect((int) $piece['formulaire_id']);
            if (!$this->canDelete($piece, $formulaire)) {
                Permission::requireOrFail('formulaires.delete_attachment_any');
            }
            (new PieceJointeService())->srv_supprimer($piece, (int) Auth::id());
            setFlash('success', 'Piece jointe supprimee.');
        }
        $this->redirect($piece ? 'formulaires/voir/' . $piece['formulaire_id'] . '#pieces-jointes' : 'formulaires');
    }

    private function editableFormOrRedirect(int $formulaireId): array
    {
        $formulaire = (new FormulaireRepository())->repo_find($formulaireId);
        if (!$formulaire || (int) ($formulaire['est_archive'] ?? 0) === 1) {
            setFlash('error', 'Ce formulaire est introuvable ou archive et ne peut plus etre modifie.');
            $this->redirect('formulaires');
        }
        return $formulaire;
    }

    private function canAttach(array $formulaire): bool
    {
        $role = (string) Auth::role();
        return (int) ($formulaire['est_archive'] ?? 0) === 0
            && (Permission::has($role, 'formulaires.attach_any')
                || (Permission::has($role, 'formulaires.attach_own')
                    && ((int) ($formulaire['responsable_id'] ?? 0) === (int) Auth::id()
                        || (new MissionRechercheRepository())->repo_estResponsableDuFormulaire(
                            (int) $formulaire['id'],
                            (int) Auth::id()
                        ))));
    }

    private function canDelete(array $piece, array $formulaire): bool
    {
        if ((int) ($formulaire['est_archive'] ?? 0) === 1) {
            return false;
        }
        $role = (string) Auth::role();
        return Permission::has($role, 'formulaires.delete_attachment_any')
            || (Permission::has($role, 'formulaires.delete_attachment_own')
                && ((int) ($formulaire['responsable_id'] ?? 0) === (int) Auth::id()
                    || (new MissionRechercheRepository())->repo_estResponsableDuFormulaire(
                        (int) $formulaire['id'],
                        (int) Auth::id()
                    ))
                && (int) ($piece['televerse_par'] ?? 0) === (int) Auth::id());
    }
}
