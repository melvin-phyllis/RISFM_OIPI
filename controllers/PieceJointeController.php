<?php
declare(strict_types=1);

/** Stockage prive, telechargement controle et suppression des pieces jointes. */
class PieceJointeController extends Controller
{
    public function upload(string $id): void
    {
        Auth::requireLogin();
        $formulaireId = (int) $id;
        $formulaire = $this->editableFormOrRedirect($formulaireId);
        if (!$this->canAttach($formulaire)) {
            Permission::requireOrFail('formulaires.attach_any');
        }

        if (empty($_FILES['piece']['name'])) {
            setFlash('error', 'Aucun fichier selectionne.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#pieces-jointes');
            return;
        }

        $file = $_FILES['piece'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = $file['error'] === UPLOAD_ERR_OK
            ? Security::validatedUploadMime($file['tmp_name'], $ext, ['pdf', 'jpg', 'jpeg', 'png'])
            : null;
        if ($mime === null) {
            Logger::log(Auth::id(), 'securite', "Televersement refuse pour le formulaire #{$formulaireId} : type invalide");
            setFlash('error', 'Fichier invalide (formats acceptes : PDF, JPG, PNG).');
            $this->redirect('formulaires/voir/' . $formulaireId . '#pieces-jointes');
            return;
        }
        if ((int) $file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
            setFlash('error', 'Le fichier depasse la taille maximale autorisee (' . UPLOAD_MAX_MB . ' Mo).');
            $this->redirect('formulaires/voir/' . $formulaireId . '#pieces-jointes');
            return;
        }

        $safeName = Security::safeFilename($file['name']);
        $directory = PRIVATE_UPLOADS_PATH . '/formulaires';
        if (!Security::ensureDirectory($directory)) {
            setFlash('error', 'Le dossier securise des pieces jointes est indisponible.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#pieces-jointes');
            return;
        }
        $destination = $directory . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            setFlash('error', 'Echec du televersement.');
            $this->redirect('formulaires/voir/' . $formulaireId . '#pieces-jointes');
            return;
        }

        (new PieceJointeModel())->insert([
            'formulaire_id' => $formulaireId,
            'nom_original' => Security::cleanString($file['name']),
            'nom_fichier' => $safeName,
            'type_mime' => $mime,
            'taille_octets' => (int) $file['size'],
            'televerse_par' => Auth::id(),
        ]);
        Logger::log(
            Auth::id(),
            'ajout',
            "Piece jointe televersee pour le formulaire #{$formulaireId}",
            null,
            'formulaire',
            $formulaireId,
            null,
            ['nom_original' => Security::cleanString($file['name'])]
        );
        setFlash('success', 'Piece jointe televersee avec succes.');
        $this->redirect('formulaires/voir/' . $formulaireId . '#pieces-jointes');
    }

    public function download(string $id): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('formulaires.view');
        $piece = (new PieceJointeModel())->find((int) $id);
        if (!$piece) {
            Logger::log(Auth::id(), 'securite', "Telechargement refuse : piece #{$id} introuvable");
            http_response_code(404);
            require BASE_PATH . '/views/errors/404.php';
            return;
        }

        $formulaire = (new FormulaireModel())->find((int) $piece['formulaire_id']);
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

    public function delete(string $id): void
    {
        Auth::requireLogin();
        $pieceModel = new PieceJointeModel();
        $piece = $pieceModel->find((int) $id);
        if ($piece) {
            $formulaire = $this->editableFormOrRedirect((int) $piece['formulaire_id']);
            if (!$this->canDelete($piece, $formulaire)) {
                Permission::requireOrFail('formulaires.delete_attachment_any');
            }
            $this->deletePhysicalFile((string) $piece['nom_fichier']);
            $pieceModel->delete((int) $id);
            Logger::log(
                Auth::id(),
                'suppression',
                "Suppression de la piece jointe #{$id}",
                null,
                'formulaire',
                (int) $piece['formulaire_id'],
                ['nom_original' => (string) $piece['nom_original']],
                null
            );
            setFlash('success', 'Piece jointe supprimee.');
        }
        $this->redirect($piece ? 'formulaires/voir/' . $piece['formulaire_id'] . '#pieces-jointes' : 'formulaires');
    }

    private function editableFormOrRedirect(int $formulaireId): array
    {
        $formulaire = (new FormulaireModel())->find($formulaireId);
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
                        || (new MissionRechercheModel())->estResponsableDuFormulaire(
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
                    || (new MissionRechercheModel())->estResponsableDuFormulaire(
                        (int) $formulaire['id'],
                        (int) Auth::id()
                    ))
                && (int) ($piece['televerse_par'] ?? 0) === (int) Auth::id());
    }

    private function deletePhysicalFile(string $filename): void
    {
        foreach ([
            PRIVATE_UPLOADS_PATH . '/formulaires/' . basename($filename),
            UPLOADS_PATH . '/formulaires/' . basename($filename),
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}
