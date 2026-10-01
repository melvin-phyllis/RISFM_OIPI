<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Permission;
use App\Http\Requests\Auth\ChangerMotDePasseFormRequest;
use App\Http\Requests\Auth\UpdateProfilFormRequest;
use App\Repositories\Administration\ActiviteRepository;
use App\Repositories\Utilisateur\ConnexionRepository;
use App\Repositories\Utilisateur\UserRepository;
use App\Services\Auth\ProfilService;
use DomainException;

class ProfilController extends Controller
{
    public function ctrl_show(): void
    {
        Auth::requireLogin(true);
        $userRepository = new UserRepository();
        $user = $userRepository->repo_findWithService((int) Auth::id());

        if ($user && (int) $user['doit_changer_mdp'] === 1) {
            $this->render('auth/force_change', ['__title' => 'Changement de mot de passe requis'], 'layouts/guest');
            return;
        }

        $connRepository = new ConnexionRepository();
        $activiteRepository = new ActiviteRepository();

        $this->render('profil/show', [
            '__title' => 'Mon profil',
            '__active' => 'profil',
            'user' => $user,
            'connexions' => $connRepository->repo_dernieresConnexions(5, (int) Auth::id()),
            'activites' => $activiteRepository->repo_recentes(10, (int) Auth::id()),
        ]);
    }

    public function ctrl_update(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('profil.update');
        if (!(new UserRepository())->repo_find((int) Auth::id())) {
            setFlash('error', 'Compte utilisateur introuvable.');
            $this->redirect('login');
            return;
        }
        $data = $this->validateRequest(UpdateProfilFormRequest::class, 'profil');

        try {
            (new ProfilService())->srv_modifier((int) Auth::id(), $data);
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('profil');
            return;
        }
        setFlash('success', 'Profil mis a jour avec succes.');
        $this->redirect('profil');
    }

    public function ctrl_updatePassword(): void
    {
        Auth::requireLogin(true);
        $data = $this->validateRequest(ChangerMotDePasseFormRequest::class, 'profil');

        try {
            (new ProfilService())->srv_changerMotDePasse((int) Auth::id(), $data, Auth::connectionId());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('profil');
            return;
        }
        // Toutes les autres sessions sont revoquees. La session qui vient de
        // prouver le mot de passe actuel reste ouverte avec la nouvelle version.
        Auth::synchronizeCurrentUser();
        setFlash('success', 'Mot de passe modifie avec succes.');
        $this->redirect('profil');
    }
}
