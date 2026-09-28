<?php
declare(strict_types=1);

class ProfilController extends Controller
{
    public function show(): void
    {
        Auth::requireLogin(true);
        $userModel = new UserModel();
        $user = $userModel->find((int) Auth::id());

        if ($user && (int) $user['doit_changer_mdp'] === 1) {
            $this->render('auth/force_change', ['__title' => 'Changement de mot de passe requis'], 'layouts/guest');
            return;
        }

        $connModel = new ConnexionModel();
        $activiteModel = new ActiviteModel();

        $this->render('profil/show', [
            '__title' => 'Mon profil',
            '__active' => 'profil',
            'user' => $user,
            'connexions' => $connModel->dernieresConnexions(5, (int) Auth::id()),
            'activites' => $activiteModel->recentes(10, (int) Auth::id()),
        ]);
    }

    public function update(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('profil.update');

        $userModel = new UserModel();
        $id = (int) Auth::id();
        $existing = $userModel->find($id);
        if (!$existing) {
            setFlash('error', 'Compte utilisateur introuvable.');
            $this->redirect('login');
            return;
        }

        $data = [
            'nom'       => Security::cleanString($this->input('nom', '')),
            'prenoms'   => Security::cleanString($this->input('prenoms', '')),
            'email'     => Security::cleanString($this->input('email', '')),
            'telephone' => Security::cleanString($this->input('telephone', '')),
        ];

        $validator = new Validator();
        if (!$validator->validate($data, ['nom' => 'required|max:100', 'prenoms' => 'required|max:100', 'email' => 'required|email'])) {
            setFlash('error', $validator->firstError());
            $this->redirect('profil');
            return;
        }

        $emailOwner = $userModel->findByEmail($data['email']);
        if ($emailOwner && (int) $emailOwner['id'] !== $id) {
            setFlash('error', 'Cette adresse e-mail est deja utilisee par un autre compte.');
            $this->redirect('profil');
            return;
        }

        $photoName = null;
        if (!empty($_FILES['photo']['name'])) {
            $photoName = $this->handlePhotoUpload();
            if ($photoName === null) {
                $this->redirect('profil');
                return;
            }
            $data['photo'] = $photoName;
        }

        try {
            $userModel->update($id, $data);
        } catch (PDOException $e) {
            if ($photoName !== null) {
                $this->deletePhotoFile($photoName);
            }
            if ($e->getCode() === '23000') {
                setFlash('error', 'Cette adresse e-mail est deja utilisee par un autre compte.');
                $this->redirect('profil');
                return;
            }
            throw $e;
        }

        if ($photoName !== null && !empty($existing['photo']) && $existing['photo'] !== $photoName) {
            $this->deletePhotoFile((string) $existing['photo']);
        }
        Logger::log($id, 'modification', 'Mise a jour du profil personnel');
        setFlash('success', 'Profil mis a jour avec succes.');
        $this->redirect('profil');
    }

    private function handlePhotoUpload(): ?string
    {
        $file = $_FILES['photo'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            setFlash('error', 'Le televersement de la photo a echoue. Verifiez sa taille puis reessayez.');
            return null;
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mime = Security::validatedUploadMime($file['tmp_name'], $ext, ['jpg', 'jpeg', 'png']);
        if ($mime === null) {
            Logger::log(Auth::id(), 'securite', 'Photo de profil refusee : type de fichier invalide');
            setFlash('error', 'Format de photo non autorise (jpg, jpeg, png uniquement).');
            return null;
        }
        if ($file['size'] > UPLOAD_MAX_MB * 1024 * 1024) {
            setFlash('error', 'La photo depasse la taille maximale autorisee.');
            return null;
        }
        $safeName = Security::safeFilename($file['name']);
        $directory = UPLOADS_PATH . '/photos';
        if (!Security::ensureDirectory($directory)) {
            setFlash('error', 'Le dossier des photos est indisponible.');
            return null;
        }
        $dest = $directory . '/' . $safeName;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            setFlash('error', 'La photo n\'a pas pu etre enregistree sur le serveur.');
            return null;
        }
        return $safeName;
    }

    private function deletePhotoFile(string $filename): void
    {
        $filename = basename($filename);
        if ($filename === '') {
            return;
        }

        $path = UPLOADS_PATH . '/photos/' . $filename;
        if (is_file($path) && !unlink($path)) {
            error_log('[RISFM Profil] Impossible de supprimer l\'ancienne photo : ' . $filename);
        }
    }

    public function updatePassword(): void
    {
        Auth::requireLogin(true);

        $userModel = new UserModel();
        $id = (int) Auth::id();
        $user = $userModel->find($id);
        // L'etat force vient exclusivement de la base. Un champ POST ne doit
        // jamais permettre de contourner la verification du mot de passe actuel.
        $forced = $user && (int) $user['doit_changer_mdp'] === 1;

        $nouveau = (string) $this->input('mot_de_passe', '');
        $confirmation = (string) $this->input('mot_de_passe_confirmation', '');

        if (!$forced) {
            $actuel = (string) $this->input('mot_de_passe_actuel', '');
            if (!$user || !password_verify($actuel, $user['mot_de_passe'])) {
                setFlash('error', 'Le mot de passe actuel est incorrect.');
                $this->redirect('profil');
                return;
            }
        }

        if ($nouveau !== $confirmation) {
            setFlash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');
            $this->redirect('profil');
            return;
        }

        $passwordError = Security::passwordPolicyError($nouveau);
        if ($passwordError !== null) {
            setFlash('error', $passwordError);
            $this->redirect('profil');
            return;
        }

        $userModel->setPassword($id, $nouveau, false, Auth::connectionId());
        $userModel->clearFailedLoginAttempts($id);
        // Toutes les autres sessions sont revoquees. La session qui vient de
        // prouver le mot de passe actuel reste ouverte avec la nouvelle version.
        Auth::synchronizeCurrentUser();
        Logger::log($id, 'securite', 'Changement de mot de passe personnel');
        setFlash('success', 'Mot de passe modifie avec succes.');
        $this->redirect('profil');
    }
}
