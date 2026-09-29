<?php
declare(strict_types=1);

namespace App\Http\Controllers\Utilisateur;

use App\Core\Auth;
use App\Core\Controller;
use App\Http\Requests\Utilisateur\CreateUserFormRequest;
use App\Http\Requests\Utilisateur\DefinirMotDePasseFormRequest;
use App\Http\Requests\Utilisateur\UpdateUserFormRequest;
use App\Repositories\Utilisateur\UserRepository;
use App\Services\Utilisateur\UserService;
use DomainException;
use Throwable;

class UserController extends Controller
{
    /** Saisie de la modale de creation, conservee apres une erreur. */
    private const OLD_INPUT_KEY = '_user_create_old';

    public function ctrl_index(): void
    {
        $this->requirePermission('utilisateurs.manage');
        $model = new UserRepository();
        $users = $model->repo_allWithMissionSummary();
        $createOld = $this->pullCreateInput();

        $activeUsers = count(array_filter(
            $users,
            static fn (array $user): bool => (int) ($user['actif'] ?? 0) === 1
        ));
        $adminUsers = count(array_filter(
            $users,
            static fn (array $user): bool => ($user['role'] ?? '') === 'administrateur'
        ));
        $activeMissions = array_sum(array_map(
            static fn (array $user): int => (int) ($user['missions_actives'] ?? 0),
            $users
        ));
        $pendingPasswordSetup = count(array_filter(
            $users,
            static fn (array $user): bool => (int) ($user['doit_changer_mdp'] ?? 0) === 1
        ));

        $this->render('users/list', [
            '__title' => 'Gestion des utilisateurs',
            '__active' => 'utilisateurs',
            '__hide_page_header' => true,
            'users' => $users,
            'roles' => require BASE_PATH . '/config/roles.php',
            'createOld' => $createOld,
            'userKpi' => [
                'total' => count($users),
                'active' => $activeUsers,
                'inactive' => count($users) - $activeUsers,
                'connected' => $model->repo_connectedCount(),
                'admins' => $adminUsers,
                'active_missions' => $activeMissions,
                'pending_password' => $pendingPasswordSetup,
            ],
        ]);
    }

    public function ctrl_create(): void
    {
        $this->requirePermission('utilisateurs.manage');
        // Compatibilite avec les anciens favoris : la creation se fait
        // desormais dans la modale de la liste.
        $this->redirect('utilisateurs#ajouter-utilisateur');
    }

    public function ctrl_store(): void
    {
        $this->requirePermission('utilisateurs.manage');
        $retour = 'utilisateurs#ajouter-utilisateur';
        $data = $this->validateRequest(CreateUserFormRequest::class, $retour, [], self::OLD_INPUT_KEY);

        try {
            $resultat = (new UserService())->srv_creer($data, (int) Auth::id());
        } catch (DomainException $e) {
            $_SESSION[self::OLD_INPUT_KEY] = $data;
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Utilisateur] Echec de creation avec identifiant automatique : ' . $e->getMessage());
            $_SESSION[self::OLD_INPUT_KEY] = $data;
            setFlash('error', 'Impossible de creer le compte pour le moment. Merci de reessayer.');
            $this->redirect($retour);
            return;
        }

        if ($resultat['lien_envoye']) {
            setFlash('success', "Utilisateur créé avec succès. Un lien sécurisé permettant de choisir son mot de passe a été envoyé à {$resultat['user']['email']}.");
        } else {
            setFlash('warning', 'Le compte a été créé, mais le lien d’activation n’a pas pu être envoyé. Vérifiez la configuration e-mail puis utilisez l’action « Renvoyer le lien d’accès ».');
        }
        $this->redirect('utilisateurs');
    }

    public function ctrl_edit(string $id): void
    {
        $this->requirePermission('utilisateurs.manage');
        $model = new UserRepository();
        $user = $model->repo_findWithMissionSummary((int) $id);
        if (!$user) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }
        $this->render('users/form', [
            '__title' => 'Modifier l\'utilisateur ' . $user['identifiant'],
            '__active' => 'utilisateurs',
            'user' => $user,
            'roles' => require BASE_PATH . '/config/roles.php',
        ]);
    }

    public function ctrl_update(string $id): void
    {
        $this->requirePermission('utilisateurs.manage');
        $userId = (int) $id;
        if (!(new UserRepository())->repo_find($userId)) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }
        $retour = 'utilisateurs/modifier/' . $userId;
        $data = $this->validateRequest(UpdateUserFormRequest::class, $retour);

        try {
            (new UserService())->srv_modifier($userId, $data, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect($retour);
            return;
        } catch (Throwable $e) {
            error_log('[Utilisateur] Echec de mise a jour #' . $userId . ' : ' . $e->getMessage());
            setFlash('error', 'Le compte n’a pas pu etre mis a jour.');
            $this->redirect($retour);
            return;
        }
        setFlash('success', 'Utilisateur mis a jour.');
        $this->redirect('utilisateurs');
    }

    public function ctrl_destroy(string $id): void
    {
        $this->requirePermission('utilisateurs.manage');
        $userId = (int) $id;
        try {
            (new UserService())->srv_supprimer($userId, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('utilisateurs');
            return;
        } catch (Throwable $e) {
            error_log('[Utilisateur] Echec de suppression #' . $userId . ' : ' . $e->getMessage());
            setFlash('error', 'Ce compte ne peut pas etre supprime. Desactivez-le afin de conserver l’historique.');
            $this->redirect('utilisateurs');
            return;
        }
        setFlash('success', 'Utilisateur supprime.');
        $this->redirect('utilisateurs');
    }

    public function ctrl_toggleStatus(string $id): void
    {
        $this->requirePermission('utilisateurs.manage');
        $userId = (int) $id;
        try {
            (new UserService())->srv_basculerStatut($userId, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('utilisateurs');
            return;
        } catch (Throwable $e) {
            error_log('[Utilisateur] Echec de changement de statut #' . $userId . ' : ' . $e->getMessage());
            setFlash('error', 'Le statut du compte n’a pas pu etre modifie.');
            $this->redirect('utilisateurs');
            return;
        }
        setFlash('success', 'Statut du compte mis a jour.');
        $this->redirect('utilisateurs');
    }

    public function ctrl_resetPassword(string $id): void
    {
        $this->requirePermission('utilisateurs.manage');
        try {
            $lienEnvoye = (new UserService())->srv_renouvelerAcces((int) $id, (int) Auth::id());
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('utilisateurs');
            return;
        }
        if ($lienEnvoye) {
            setFlash('success', 'Les anciens accès ont été révoqués. Un lien sécurisé permettant de choisir un nouveau mot de passe a été envoyé à l’utilisateur.');
        } else {
            setFlash('warning', 'Les anciens accès ont été révoqués, mais l’e-mail n’a pas pu être envoyé. Corrigez la configuration e-mail puis renvoyez le lien d’accès.');
        }
        $this->redirect('utilisateurs');
    }

    public function ctrl_manualPasswordChange(string $id): void
    {
        $this->requirePermission('utilisateurs.manage');
        $userId = (int) $id;
        $data = $this->validateRequest(DefinirMotDePasseFormRequest::class, 'utilisateurs');

        try {
            $user = (new UserService())->srv_definirMotDePasse($userId, $data, (int) Auth::id());
            setFlash('success', 'Le mot de passe de l’utilisateur ' . e($user['identifiant']) . ' a été mis à jour avec succès.');
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
        } catch (Throwable $e) {
            error_log('[Utilisateur] Échec de modification manuelle du mot de passe #' . $userId . ' : ' . $e->getMessage());
            setFlash('error', 'Impossible de modifier le mot de passe pour le moment.');
        }
        $this->redirect('utilisateurs');
    }

    private function pullCreateInput(): array
    {
        $data = $_SESSION[self::OLD_INPUT_KEY] ?? [];
        unset($_SESSION[self::OLD_INPUT_KEY]);
        return is_array($data) ? $data : [];
    }

}
