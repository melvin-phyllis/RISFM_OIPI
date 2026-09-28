<?php
declare(strict_types=1);

class UserController extends Controller
{
    private function ensureAdmin(): void
    {
        Auth::requireLogin();
        Permission::requireOrFail('utilisateurs.manage');
    }

    public function index(): void
    {
        $this->ensureAdmin();
        $model = new UserModel();
        $users = $model->allWithMissionSummary();
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
                'connected' => $model->connectedCount(),
                'admins' => $adminUsers,
                'active_missions' => $activeMissions,
                'pending_password' => $pendingPasswordSetup,
            ],
        ]);
    }

    public function create(): void
    {
        $this->ensureAdmin();
        // Compatibilite avec les anciens favoris : la creation se fait
        // desormais dans la modale de la liste.
        $this->redirect('utilisateurs#ajouter-utilisateur');
    }

    public function store(): void
    {
        $this->ensureAdmin();
        $model = new UserModel();

        $data = $this->collect();
        $validator = new Validator();
        $ok = $validator->validate($data, [
            'nom'         => 'required|max:100',
            'prenoms'     => 'required|max:100',
            'email'       => 'required|email',
            'role'        => 'required|in:administrateur,responsable,agent,consultation',
        ]);

        if (!$ok) {
            $this->rememberCreateInput($data);
            setFlash('error', $validator->firstError());
            $this->redirect('utilisateurs#ajouter-utilisateur');
            return;
        }

        if ($model->findByEmail($data['email'])) {
            $this->rememberCreateInput($data);
            setFlash('error', 'Cette adresse e-mail est deja utilisee.');
            $this->redirect('utilisateurs#ajouter-utilisateur');
            return;
        }

        $motDePasseTemp = $this->genererMotDePasseTemporaire();
        $data['mot_de_passe'] = password_hash($motDePasseTemp, PASSWORD_DEFAULT);
        $data['doit_changer_mdp'] = 1;
        $data['actif'] = 1;
        $data['cree_par'] = Auth::id();

        $data['role_id'] = $this->roleIdFromCode($data['role']);

        try {
            $created = $model->insertWithGeneratedIdentifiant($data);
        } catch (Throwable $e) {
            error_log('[Utilisateur] Echec de creation avec identifiant automatique : ' . $e->getMessage());
            $this->rememberCreateInput($data);
            setFlash('error', 'Impossible de creer le compte pour le moment. Merci de reessayer.');
            $this->redirect('utilisateurs#ajouter-utilisateur');
            return;
        }

        $identifiant = $created['identifiant'];
        Logger::log(Auth::id(), 'ajout', "Creation de l'utilisateur [{$identifiant}]");

        $user = $model->find((int) $created['id']);
        if ($user && $this->sendSecureAccessLink($user, true)) {
            setFlash('success', "Utilisateur créé avec succès. Un lien sécurisé permettant de choisir son mot de passe a été envoyé à {$user['email']}.");
        } else {
            setFlash('warning', 'Le compte a été créé, mais le lien d’activation n’a pas pu être envoyé. Vérifiez la configuration e-mail puis utilisez l’action « Renvoyer le lien d’accès ».');
        }
        $this->redirect('utilisateurs');
    }

    public function edit(string $id): void
    {
        $this->ensureAdmin();
        $model = new UserModel();
        $user = $model->findWithMissionSummary((int) $id);
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

    public function update(string $id): void
    {
        $this->ensureAdmin();
        $model = new UserModel();
        $userId = (int) $id;
        $existing = $model->find($userId);
        if (!$existing) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }

        $data = $this->collect();
        $validator = new Validator();
        $ok = $validator->validate($data, [
            'nom'     => 'required|max:100',
            'prenoms' => 'required|max:100',
            'email'   => 'required|email',
            'role'    => 'required|in:administrateur,responsable,agent,consultation',
        ]);
        if (!$ok) {
            setFlash('error', $validator->firstError());
            $this->redirect('utilisateurs/modifier/' . $userId);
            return;
        }

        $emailOwner = $model->findByEmail($data['email']);
        if ($emailOwner && (int) $emailOwner['id'] !== $userId) {
            setFlash('error', 'Cette adresse e-mail est deja utilisee par un autre compte.');
            $this->redirect('utilisateurs/modifier/' . $userId);
            return;
        }

        if (
            $existing['role'] === 'administrateur'
            && $data['role'] !== 'administrateur'
            && (int) $existing['actif'] === 1
            && $model->activeAdminCount() <= 1
        ) {
            setFlash('error', 'Impossible de retirer le role du dernier administrateur actif.');
            $this->redirect('utilisateurs/modifier/' . $userId);
            return;
        }

        $data['role_id'] = $this->roleIdFromCode($data['role']);

        try {
            $model->updateWithLifecycleGuard($userId, $data);
        } catch (DomainException $e) {
            setFlash('error', $e->getMessage());
            $this->redirect('utilisateurs/modifier/' . $userId);
            return;
        } catch (Throwable $e) {
            error_log('[Utilisateur] Echec de mise a jour #' . $userId . ' : ' . $e->getMessage());
            setFlash('error', 'Le compte n’a pas pu etre mis a jour.');
            $this->redirect('utilisateurs/modifier/' . $userId);
            return;
        }
        Logger::log(Auth::id(), 'modification', "Modification de l'utilisateur #{$userId}");
        setFlash('success', 'Utilisateur mis a jour.');
        $this->redirect('utilisateurs');
    }

    public function destroy(string $id): void
    {
        $this->ensureAdmin();
        $userId = (int) $id;
        if ($userId === Auth::id()) {
            setFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');
            $this->redirect('utilisateurs');
            return;
        }
        $model = new UserModel();
        $user = $model->find($userId);
        if (!$user) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }
        if ($user['role'] === 'administrateur' && $model->adminCount() <= 1) {
            setFlash('error', 'Impossible de supprimer le dernier administrateur.');
            $this->redirect('utilisateurs');
            return;
        }
        try {
            $model->deleteWithRevocation($userId);
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
        Logger::log(Auth::id(), 'suppression', "Suppression de l'utilisateur #{$userId}");
        setFlash('success', 'Utilisateur supprime.');
        $this->redirect('utilisateurs');
    }

    public function toggleStatus(string $id): void
    {
        $this->ensureAdmin();
        $userId = (int) $id;
        if ($userId === Auth::id()) {
            setFlash('error', 'Vous ne pouvez pas desactiver votre propre compte.');
            $this->redirect('utilisateurs');
            return;
        }
        $model = new UserModel();
        $user = $model->find($userId);
        if (!$user) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }
        if (
            $user['role'] === 'administrateur'
            && (int) $user['actif'] === 1
            && $model->activeAdminCount() <= 1
        ) {
            setFlash('error', 'Impossible de desactiver le dernier administrateur actif.');
            $this->redirect('utilisateurs');
            return;
        }
        try {
            $model->toggleActive($userId);
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
        Logger::log(Auth::id(), 'activation', "Activation/desactivation de l'utilisateur #{$userId}");
        setFlash('success', 'Statut du compte mis a jour.');
        $this->redirect('utilisateurs');
    }

    public function resetPassword(string $id): void
    {
        $this->ensureAdmin();
        $userId = (int) $id;
        $model = new UserModel();
        $user = $model->find($userId);
        if (!$user) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }
        // Rend immédiatement l'ancien mot de passe inutilisable. Le secret
        // aleatoire n'est ni affiche, ni journalise, ni envoye par e-mail.
        $model->setPassword($userId, $this->genererMotDePasseTemporaire(), true);
        $model->clearFailedLoginAttempts($userId);
        Logger::log(Auth::id(), 'securite', "Revocation des acces et demande d'un nouveau mot de passe pour l'utilisateur #{$userId}");

        if ($this->sendSecureAccessLink($user, false)) {
            setFlash('success', 'Les anciens accès ont été révoqués. Un lien sécurisé permettant de choisir un nouveau mot de passe a été envoyé à l’utilisateur.');
        } else {
            setFlash('warning', 'Les anciens accès ont été révoqués, mais l’e-mail n’a pas pu être envoyé. Corrigez la configuration e-mail puis renvoyez le lien d’accès.');
        }
        $this->redirect('utilisateurs');
    }

    public function manualPasswordChange(string $id): void
    {
        $this->ensureAdmin();
        $userId = (int) $id;
        $model = new UserModel();
        $user = $model->find($userId);
        if (!$user) {
            setFlash('error', 'Utilisateur introuvable.');
            $this->redirect('utilisateurs');
            return;
        }

        $password = (string) $this->input('nouveau_mot_de_passe', '');
        $forceChange = $this->input('force_change', '0') === '1';

        $policyError = Security::passwordPolicyError($password);
        if ($policyError !== null) {
            setFlash('error', $policyError);
            $this->redirect('utilisateurs');
            return;
        }

        try {
            $model->setPassword($userId, $password, $forceChange);
            $model->clearFailedLoginAttempts($userId);
            Logger::log(
                Auth::id(),
                'securite',
                "Définition manuelle du mot de passe pour l'utilisateur #{$userId} (" . e($user['identifiant']) . ')',
                null,
                'utilisateur',
                $userId,
                null,
                ['force_change' => $forceChange]
            );
            setFlash('success', 'Le mot de passe de l’utilisateur ' . e($user['identifiant']) . ' a été mis à jour avec succès.');
        } catch (Throwable $e) {
            error_log('[Utilisateur] Échec de modification manuelle du mot de passe #' . $userId . ' : ' . $e->getMessage());
            setFlash('error', 'Impossible de modifier le mot de passe pour le moment.');
        }
        $this->redirect('utilisateurs');
    }

    private function sendSecureAccessLink(array $user, bool $newAccount): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId < 1 || !Security::isValidEmail((string) ($user['email'] ?? ''))) {
            return false;
        }

        $validityMinutes = 60;
        try {
            $tokenModel = new TokenResetModel();
            $tokenModel->purgerExpires();
            $token = $tokenModel->creer($userId, $validityMinutes);
            (new AppMailer())->sendAccountAccess(
                $user,
                url('reinitialiser/' . $token),
                $validityMinutes,
                $newAccount
            );
            Logger::log(
                Auth::id(),
                'email',
                ($newAccount ? 'Invitation de compte' : 'Lien de renouvellement d acces')
                . " envoye a l'utilisateur #{$userId}"
            );
            return true;
        } catch (Throwable $exception) {
            // Ni le mot de passe aleatoire ni le jeton ne sont inscrits dans
            // les journaux. Un nouvel envoi invalidera automatiquement ce lien.
            error_log('[Utilisateur] Echec d’envoi du lien d’accès pour utilisateur #' . $userId . ' : ' . $exception->getMessage());
            Logger::log(Auth::id(), 'securite', "Echec d'envoi du lien d'acces a l'utilisateur #{$userId}");
            return false;
        }
    }

    private function collect(): array
    {
        return [
            'nom'         => Security::cleanString($this->input('nom', '')),
            'prenoms'     => Security::cleanString($this->input('prenoms', '')),
            'email'       => Security::cleanString($this->input('email', '')),
            'telephone'   => Security::cleanString($this->input('telephone', '')),
            'service'     => Security::cleanString($this->input('service', '')),
            'role'        => Security::cleanString($this->input('role', '')),
        ];
    }

    private function rememberCreateInput(array $data): void
    {
        unset($data['mot_de_passe'], $data['role_id'], $data['cree_par'], $data['actif']);
        $_SESSION['_user_create_old'] = $data;
    }

    private function pullCreateInput(): array
    {
        $data = $_SESSION['_user_create_old'] ?? [];
        unset($_SESSION['_user_create_old']);
        return is_array($data) ? $data : [];
    }

    private function roleIdFromCode(string $code): ?int
    {
        $db = Database::getConnection();
        $stmt = $db->prepare('SELECT id FROM roles WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    private function genererMotDePasseTemporaire(): string
    {
        $groups = [
            'ABCDEFGHJKLMNPQRSTUVWXYZ',
            'abcdefghijkmnpqrstuvwxyz',
            '23456789',
            '!@#$%',
        ];
        $chars = [];
        foreach ($groups as $group) {
            $chars[] = $group[random_int(0, strlen($group) - 1)];
        }

        $pool = implode('', $groups);
        while (count($chars) < 12) {
            $chars[] = $pool[random_int(0, strlen($pool) - 1)];
        }

        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
