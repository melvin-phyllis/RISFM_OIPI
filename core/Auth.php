<?php
declare(strict_types=1);

/**
 * Gestion de l'authentification par session securisee :
 * - hachage des mots de passe (password_hash / password_verify)
 * - regeneration de l'identifiant de session a la connexion (anti fixation)
 * - expiration automatique apres inactivite
 * - journalisation des tentatives de connexion (reussies et echouees)
 */
class Auth
{
    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        if (!self::revalidateAuthenticatedUser()) {
            return;
        }
        self::enforceIdleTimeout();
    }

    /**
     * Recharge l'autorisation depuis la base avant chaque requete. Le role et
     * le nom ne sont donc jamais consideres comme fiables uniquement parce
     * qu'ils figurent dans le cookie de session.
     */
    private static function revalidateAuthenticatedUser(): bool
    {
        if (!self::check()) {
            return true;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id, identifiant, nom, prenoms, role, actif,
                    doit_changer_mdp, session_version
             FROM utilisateurs WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => self::id()]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['actif'] !== 1) {
            self::invalidateCurrentSession('Votre compte a ete desactive ou supprime. Contactez un administrateur.');
            return false;
        }

        $sessionVersion = (int) ($_SESSION['session_version'] ?? 0);
        if ($sessionVersion !== (int) $user['session_version']) {
            self::invalidateCurrentSession('Votre session a ete revoquee pour des raisons de securite. Merci de vous reconnecter.');
            return false;
        }

        self::applyUserToSession($user);
        return true;
    }

    private static function applyUserToSession(array $user): void
    {
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_identifiant'] = (string) $user['identifiant'];
        $_SESSION['user_nom'] = trim((string) $user['nom'] . ' ' . (string) $user['prenoms']);
        $_SESSION['user_role'] = (string) $user['role'];
        $_SESSION['must_change_password'] = (int) $user['doit_changer_mdp'] === 1;
        $_SESSION['session_version'] = (int) $user['session_version'];
    }

    private static function invalidateCurrentSession(string $message): void
    {
        self::logout(false);
        self::bootSession();
        $_SESSION['flash_warning'] = $message;
    }

    private static function enforceIdleTimeout(): void
    {
        if (!self::check()) {
            return;
        }
        $sessionMinutes = sessionLifetimeMinutes();
        $limit = $sessionMinutes * 60;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $limit) {
            Logger::log(self::id(), 'deconnexion', 'Session expiree pour inactivite');
            self::logout(false);
            self::bootSession();
            $_SESSION['flash_warning'] = 'Votre session a expire apres ' . $sessionMinutes . ' minutes d\'inactivite. Merci de vous reconnecter.';
            return;
        }
        $_SESSION['last_activity'] = time();
        self::touchConnection();
    }

    private static function touchConnection(): void
    {
        $connectionId = (int) ($_SESSION['connexion_id'] ?? 0);
        $userId = (int) (self::id() ?? 0);
        if ($connectionId <= 0 || $userId <= 0) {
            return;
        }

        $now = time();
        $lastWriteAt = (int) ($_SESSION['_connection_activity_written_at'] ?? 0);
        if (!ConnectionActivity::shouldWrite($lastWriteAt, $now, SESSION_ACTIVITY_WRITE_INTERVAL_SECONDS)) {
            return;
        }

        // On espace aussi les nouvelles tentatives apres un echec, afin qu'un
        // verrou ne declenche pas une attente sur chaque requete de la page.
        $_SESSION['_connection_activity_written_at'] = $now;

        try {
            $db = Database::getConnection();
            ConnectionActivity::touch(
                $db,
                $connectionId,
                $userId,
                SESSION_ACTIVITY_LOCK_WAIT_SECONDS
            );
        } catch (Throwable $exception) {
            // Une panne de la base sera traitee sur les operations critiques.
            // Ce compteur d'activite reste volontairement non bloquant.
            error_log('[SessionActivity] Mise a jour ignoree : ' . $exception->getMessage());
        }
    }

    public static function validateCredentials(string $identifiant, string $motDePasse): ?array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM utilisateurs
             WHERE identifiant = :login_identifiant OR email = :login_email
             LIMIT 1'
        );
        $stmt->execute([
            'login_identifiant' => $identifiant,
            'login_email'       => $identifiant,
        ]);
        $user = $stmt->fetch();
        $attemptIdentifier = mb_substr((string) ($user['identifiant'] ?? $identifiant), 0, 50);

        $ip = LoginRateLimiter::normalizeIp($_SERVER['REMOTE_ADDR'] ?? null);
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

        if (!$user || !password_verify($motDePasse, $user['mot_de_passe'])) {
            self::recordAttempt($attemptIdentifier, false, $ip, $agent);
            Logger::log($user['id'] ?? null, 'connexion_echouee', "Tentative de connexion echouee pour [$identifiant]", $ip);
            return null;
        }

        if ((int) $user['actif'] !== 1) {
            self::recordAttempt($attemptIdentifier, false, $ip, $agent);
            Logger::log($user['id'], 'connexion_refusee', "Compte desactive [$identifiant]", $ip);
            return null;
        }

        return $user;
    }

    public static function completeLogin(array $user, string $submittedIdentifiant, bool $twoFactorVerified): void
    {
        if (empty($user['id']) || (int) ($user['actif'] ?? 0) !== 1) {
            throw new RuntimeException('Utilisateur invalide ou inactif.');
        }

        $db = Database::getConnection();
        $attemptIdentifier = (string) $user['identifiant'];
        $ip = LoginRateLimiter::normalizeIp($_SERVER['REMOTE_ADDR'] ?? null);
        $agent = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null;

        // Anti fixation de session
        session_regenerate_id(true);

        self::recordAttempt($attemptIdentifier, true, $ip, $agent);

        // Une authentification reussie remet le compteur anti-brute-force a
        // zero afin que d'anciens echecs ne rebloquent pas le compte aussitot.
        $clear = $db->prepare('DELETE FROM tentatives_connexion WHERE identifiant = :identifiant AND succes = 0');
        $clear->execute(['identifiant' => $attemptIdentifier]);

        $stmt = $db->prepare(
            'INSERT INTO connexions (utilisateur_id, adresse_ip, navigateur, statut, connecte_le, derniere_activite)
             VALUES (:uid, :ip, :agent, "actif", NOW(), NOW())'
        );
        $stmt->execute(['uid' => $user['id'], 'ip' => $ip, 'agent' => $agent]);
        $_SESSION['connexion_id'] = (int) $db->lastInsertId();

        $upd = $db->prepare('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = :id');
        $upd->execute(['id' => $user['id']]);

        // L'utilisateur n'apparait dans la session qu'apres la finalisation
        // reussie de toutes les ecritures de connexion en base.
        self::applyUserToSession($user);
        $_SESSION['last_activity'] = time();
        $_SESSION['_connection_activity_written_at'] = time();
        MissionLoginReminder::arm((int) $_SESSION['connexion_id']);

        $verification = $twoFactorVerified
            ? 'avec verification en deux etapes'
            : 'sans verification en deux etapes (desactivee par configuration)';
        Logger::log($user['id'], 'connexion', "Connexion reussie [$submittedIdentifiant] $verification", $ip);
    }

    private static function recordAttempt(string $identifiant, bool $succes, ?string $ip, ?string $agent): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'INSERT INTO tentatives_connexion (identifiant, succes, adresse_ip, navigateur, tentee_le)
             VALUES (:identifiant, :succes, :ip, :agent, NOW())'
        );
        $stmt->execute([
            'identifiant' => mb_substr($identifiant, 0, 50),
            'succes'      => $succes ? 1 : 0,
            'ip'          => LoginRateLimiter::normalizeIp($ip),
            'agent'       => mb_substr((string) $agent, 0, 255) ?: null,
        ]);
    }

    public static function logout(bool $log = true): void
    {
        if (self::check()) {
            if ($log) {
                Logger::log(self::id(), 'deconnexion', 'Deconnexion manuelle');
            }
            if (!empty($_SESSION['connexion_id'])) {
                $db = Database::getConnection();
                $stmt = $db->prepare(
                    'UPDATE connexions
                     SET deconnecte_le = NOW(), statut = "termine", derniere_activite = NOW(),
                         duree_secondes = TIMESTAMPDIFF(SECOND, connecte_le, NOW())
                     WHERE id = :id'
                );
                $stmt->execute(['id' => $_SESSION['connexion_id']]);
            }
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function check(): bool
    {
        return !empty($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function role(): ?string
    {
        return $_SESSION['user_role'] ?? null;
    }

    public static function nom(): string
    {
        return $_SESSION['user_nom'] ?? '';
    }

    public static function connectionId(): ?int
    {
        return isset($_SESSION['connexion_id']) ? (int) $_SESSION['connexion_id'] : null;
    }

    /** Recharge la version apres un changement de mot de passe personnel. */
    public static function synchronizeCurrentUser(): void
    {
        if (!self::check()) {
            return;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT id, identifiant, nom, prenoms, role, actif,
                    doit_changer_mdp, session_version
             FROM utilisateurs WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => self::id()]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['actif'] !== 1) {
            self::invalidateCurrentSession('Votre compte n’est plus autorise a acceder a l’application.');
            return;
        }
        self::applyUserToSession($user);
    }

    public static function requireLogin(bool $allowPasswordChange = false): void
    {
        if (!self::check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/';
            header('Location: ' . url('login'));
            exit;
        }

        // Cette valeur vient d'etre rechargee par bootSession() pour la requete
        // courante ; elle ne repose pas sur un ancien etat de session.
        $mustChange = !empty($_SESSION['must_change_password']);

        if ($mustChange && !$allowPasswordChange) {
            header('Location: ' . url('profil'));
            exit;
        }
    }
}
