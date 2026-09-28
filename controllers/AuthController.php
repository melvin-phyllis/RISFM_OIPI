<?php
declare(strict_types=1);

class AuthController extends Controller
{
    public function redirectRoot(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        $this->redirect('login');
    }

    public function showLogin(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        if (LoginOtp::pending() !== null) {
            $this->redirect('verification-code');
        }

        $captcha = null;
        if ((bool) env('ENABLE_CAPTCHA', false)) {
            $a = random_int(1, 9);
            $b = random_int(1, 9);
            $_SESSION['captcha_sum'] = $a + $b;
            $captcha = ['a' => $a, 'b' => $b];
        }

        $this->render('auth/login', [
            '__title'   => 'Connexion',
            '__error'   => null,
            '__captcha' => $captcha,
        ], 'layouts/guest');
    }

    public function login(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }
        if (LoginOtp::pending() !== null) {
            $this->redirect('verification-code');
        }

        $identifiant = Security::cleanString($this->input('identifiant', ''));
        $motDePasse  = (string) $this->input('mot_de_passe', '');

        if ((bool) env('ENABLE_CAPTCHA', false)) {
            $submitted = (int) $this->input('captcha', -1);
            if (!isset($_SESSION['captcha_sum']) || $submitted !== (int) $_SESSION['captcha_sum']) {
                $this->render('auth/login', ['__title' => 'Connexion', '__error' => 'Verification anti-robot incorrecte.'], 'layouts/guest');
                return;
            }
        }

        if ($identifiant === '' || $motDePasse === '') {
            $this->render('auth/login', ['__title' => 'Connexion', '__error' => 'Veuillez renseigner votre identifiant et votre mot de passe.'], 'layouts/guest');
            return;
        }

        // Identifiant et e-mail partagent le meme compteur anti-brute-force :
        // alterner entre les deux ne permet donc pas de contourner le verrou.
        $lookupModel = new UserModel();
        $knownUser = $lookupModel->findByIdentifiant($identifiant) ?? $lookupModel->findByEmail($identifiant);
        $attemptIdentifier = mb_substr((string) ($knownUser['identifiant'] ?? $identifiant), 0, 50);

        $rateLimit = (new LoginRateLimiter())->inspect(
            $attemptIdentifier,
            $_SERVER['REMOTE_ADDR'] ?? null
        );
        if ($rateLimit['blocked']) {
            $minutes = max(1, (int) ceil($rateLimit['retry_after_seconds'] / 60));
            $this->render('auth/login', [
                '__title' => 'Connexion',
                '__error' => 'Trop de tentatives échouées. Réessayez dans environ '
                    . $minutes . ' minute(s).',
            ], 'layouts/guest');
            return;
        }

        $user = Auth::validateCredentials($identifiant, $motDePasse);
        if ($user === null) {
            $this->render('auth/login', ['__title' => 'Connexion', '__error' => 'Identifiant ou mot de passe incorrect.'], 'layouts/guest');
            return;
        }

        if (!(bool) env('ENABLE_LOGIN_OTP', true)) {
            Auth::completeLogin($user, $identifiant, false);
            $this->redirectAfterAuthentication($user);
        }

        if (!Security::isValidEmail((string) $user['email'])) {
            Logger::log((int) $user['id'], 'securite', 'Connexion 2FA impossible : adresse e-mail invalide');
            setFlash('error', 'Aucune adresse e-mail valide n’est associée à ce compte. Contactez l’administrateur.');
            $this->redirect('login');
        }

        $mailer = new AppMailer();
        if (!$mailer->isConfigured()) {
            Logger::log((int) $user['id'], 'securite', 'Connexion 2FA impossible : service SMTP non configure');
            setFlash('error', 'Le service d’envoi du code de sécurité est indisponible. Contactez l’administrateur.');
            $this->redirect('login');
        }

        $code = LoginOtp::generateCode();
        try {
            $mailer->sendLoginCode($user, $code, (int) (LoginOtp::VALIDITY_SECONDS / 60));
        } catch (Throwable $e) {
            error_log('[RISFM 2FA] Echec d’envoi du code pour utilisateur #' . $user['id'] . ' : ' . $e->getMessage());
            Logger::log((int) $user['id'], 'securite', 'Echec d’envoi du code de connexion 2FA');
            setFlash('error', 'Impossible d’envoyer le code de sécurité. Réessayez dans quelques instants.');
            $this->redirect('login');
        }

        session_regenerate_id(true);
        LoginOtp::start(
            (int) $user['id'],
            (string) $user['identifiant'],
            (int) $user['session_version'],
            $code
        );
        Logger::log((int) $user['id'], 'securite', 'Code de connexion 2FA envoye');
        $this->redirect('verification-code');
    }

    public function showLoginCode(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }

        $challenge = LoginOtp::pending();
        if ($challenge === null) {
            setFlash('warning', 'Commencez par saisir votre identifiant et votre mot de passe.');
            $this->redirect('login');
        }
        if (time() > (int) $challenge['expires_at']) {
            LoginOtp::clear();
            setFlash('error', 'Le code de sécurité a expiré. Recommencez la connexion.');
            $this->redirect('login');
        }

        $user = (new UserModel())->find((int) $challenge['user_id']);
        if (!$user
            || (int) $user['actif'] !== 1
            || (int) $user['session_version'] !== (int) ($challenge['session_version'] ?? 0)
            || !Security::isValidEmail((string) $user['email'])
        ) {
            LoginOtp::clear();
            setFlash('error', 'Cette demande de connexion n’est plus valide.');
            $this->redirect('login');
        }

        $this->render('auth/verify_code', [
            '__title' => 'Code de sécurité',
            'maskedEmail' => $this->maskEmail((string) $user['email']),
            'resendIn' => LoginOtp::secondsUntilResend(),
            'remainingSends' => max(0, LoginOtp::MAX_SENDS - (int) ($challenge['sends'] ?? 1)),
        ], 'layouts/guest');
    }

    public function verifyLoginCode(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }

        $code = trim((string) $this->input('code', ''));
        $result = LoginOtp::verify($code);
        $status = $result['status'];

        if ($status === 'missing') {
            setFlash('error', 'Aucune vérification de connexion n’est en cours.');
            $this->redirect('login');
        }
        if ($status === 'expired') {
            if (!empty($result['challenge']['user_id'])) {
                Logger::log((int) $result['challenge']['user_id'], 'securite', 'Code de connexion 2FA expire');
            }
            setFlash('error', 'Le code de sécurité a expiré. Recommencez la connexion.');
            $this->redirect('login');
        }
        if ($status === 'blocked') {
            if (!empty($result['challenge']['user_id'])) {
                Logger::log((int) $result['challenge']['user_id'], 'securite', 'Verification 2FA bloquee apres cinq codes incorrects');
            }
            setFlash('error', 'Trop de codes incorrects. Recommencez entièrement la connexion.');
            $this->redirect('login');
        }
        if ($status === 'invalid') {
            if (!empty($result['challenge']['user_id'])) {
                Logger::log((int) $result['challenge']['user_id'], 'securite', 'Code de connexion 2FA incorrect');
            }
            setFlash('error', 'Code incorrect. Il vous reste ' . (int) $result['remaining'] . ' tentative(s).');
            $this->redirect('verification-code');
        }

        $challenge = $result['challenge'];
        $user = (new UserModel())->find((int) $challenge['user_id']);
        if (!$user
            || (int) $user['actif'] !== 1
            || (int) $user['session_version'] !== (int) ($challenge['session_version'] ?? 0)
        ) {
            setFlash('error', 'Ce compte n’est plus autorisé à se connecter.');
            $this->redirect('login');
        }

        Auth::completeLogin($user, (string) $challenge['identifiant'], true);
        Logger::log((int) $user['id'], 'securite', 'Code de connexion 2FA valide');
        $this->redirectAfterAuthentication($user);
    }

    public function resendLoginCode(): void
    {
        if (Auth::check()) {
            $this->redirect('dashboard');
        }

        $status = LoginOtp::resendStatus();
        if (!$status['allowed']) {
            $message = match ($status['reason'] ?? '') {
                'cooldown' => 'Patientez encore ' . (int) ($status['wait'] ?? 1) . ' seconde(s) avant un nouvel envoi.',
                'limit' => 'La limite de renvoi est atteinte. Utilisez le dernier code reçu ou annulez la connexion.',
                'expired' => 'Le code a expiré. Recommencez entièrement la connexion.',
                default => 'Aucune vérification de connexion n’est en cours.',
            };
            setFlash('error', $message);
            $stayOnVerification = in_array(($status['reason'] ?? ''), ['cooldown', 'limit'], true);
            $this->redirect($stayOnVerification ? 'verification-code' : 'login');
        }

        $challenge = LoginOtp::pending();
        $user = $challenge ? (new UserModel())->find((int) $challenge['user_id']) : null;
        if (!$user
            || (int) $user['actif'] !== 1
            || (int) $user['session_version'] !== (int) ($challenge['session_version'] ?? 0)
            || !Security::isValidEmail((string) $user['email'])
        ) {
            LoginOtp::clear();
            setFlash('error', 'Cette demande de connexion n’est plus valide.');
            $this->redirect('login');
        }

        $code = LoginOtp::generateCode();
        try {
            (new AppMailer())->sendLoginCode($user, $code, (int) (LoginOtp::VALIDITY_SECONDS / 60));
            LoginOtp::renew($code);
            Logger::log((int) $user['id'], 'securite', 'Nouveau code de connexion 2FA envoye');
            setFlash('success', 'Un nouveau code de sécurité vient de vous être envoyé.');
        } catch (Throwable $e) {
            error_log('[RISFM 2FA] Echec du renvoi pour utilisateur #' . $user['id'] . ' : ' . $e->getMessage());
            setFlash('error', 'Le nouveau code n’a pas pu être envoyé. Votre code précédent reste valable.');
        }
        $this->redirect('verification-code');
    }

    public function cancelLoginCode(): void
    {
        LoginOtp::clear();
        session_regenerate_id(true);
        setFlash('success', 'Vérification annulée. Vous pouvez recommencer la connexion.');
        $this->redirect('login');
    }

    private function redirectAfterAuthentication(array $user): never
    {
        if ((int) $user['doit_changer_mdp'] === 1) {
            $this->redirect('profil');
        }

        $redirectTo = $_SESSION['redirect_after_login'] ?? null;
        unset($_SESSION['redirect_after_login']);
        if ($redirectTo
            && str_starts_with($redirectTo, '/')
            && !str_contains($redirectTo, 'login')
            && !str_contains($redirectTo, 'verification-code')
        ) {
            header('Location: ' . $redirectTo);
            exit;
        }

        $this->redirect('dashboard');
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $first = mb_substr($local, 0, 1);
        return $first . str_repeat('•', max(3, mb_strlen($local) - 1)) . '@' . $domain;
    }

    public function logout(): void
    {
        Auth::logout();
        Auth::bootSession();
        setFlash('success', 'Vous avez ete deconnecte avec succes.');
        $this->redirect('login');
    }

    public function showForgot(): void
    {
        $this->render('auth/forgot', ['__title' => 'Mot de passe oublie'], 'layouts/guest');
    }

    public function forgot(): void
    {
        $email = Security::cleanString($this->input('email', ''));
        if (!Security::isValidEmail($email)) {
            setFlash('error', 'Adresse e-mail invalide.');
            $this->redirect('mot-de-passe-oublie');
            return;
        }

        $tokenModel = new TokenResetModel();
        $tokenModel->purgerExpires();
        $userModel = new UserModel();
        $user = $userModel->findByEmail($email);

        // Message volontairement identique que le compte existe ou non (anti enumeration)
        if ($user && (int) $user['actif'] === 1) {
            $validityMinutes = 60;
            $token = $tokenModel->creer((int) $user['id'], $validityMinutes);
            Logger::log((int) $user['id'], 'securite', 'Demande de reinitialisation de mot de passe');

            $lien = url('reinitialiser/' . $token);
            try {
                (new AppMailer())->sendPasswordReset($user, $lien, $validityMinutes);
                Logger::log((int) $user['id'], 'securite', 'E-mail de reinitialisation envoye');
            } catch (Throwable $e) {
                // Le message public reste identique pour ne pas reveler si le
                // compte existe. Le jeton secret n'est jamais ecrit dans le log.
                error_log('[RISFM Mail] Echec de l\'envoi de reinitialisation pour utilisateur #' . $user['id'] . ' : ' . $e->getMessage());
                Logger::log((int) $user['id'], 'securite', 'Echec de l\'envoi de l\'e-mail de reinitialisation');
            }
        }

        setFlash('success', 'Si un compte existe avec cette adresse, un lien de reinitialisation vient de lui etre envoye. Verifiez egalement le dossier des courriers indesirables.');
        $this->redirect('mot-de-passe-oublie');
    }

    public function showReset(string $token): void
    {
        $tokenModel = new TokenResetModel();
        if (!$tokenModel->valide($token)) {
            setFlash('error', 'Ce lien de reinitialisation est invalide ou a expire.');
            $this->redirect('mot-de-passe-oublie');
            return;
        }

        // Le secret ne reste dans l'URL que pendant cette premiere requete.
        // La page et le POST suivants utilisent la session serveur.
        $_SESSION['_password_reset_token'] = $token;
        $this->redirect('reinitialiser');
    }

    public function showResetForm(): void
    {
        $token = (string) ($_SESSION['_password_reset_token'] ?? '');
        $tokenModel = new TokenResetModel();
        if (!$tokenModel->valide($token)) {
            unset($_SESSION['_password_reset_token']);
            setFlash('error', 'Ce lien de reinitialisation est invalide ou a expire.');
            $this->redirect('mot-de-passe-oublie');
            return;
        }

        $this->render('auth/reset', ['__title' => 'Nouveau mot de passe'], 'layouts/guest');
    }

    public function reset(?string $legacyToken = null): void
    {
        // Compatibilite avec un formulaire ouvert avant la mise a jour P9.
        $token = $legacyToken ?? (string) ($_SESSION['_password_reset_token'] ?? '');
        if ($legacyToken !== null) {
            $_SESSION['_password_reset_token'] = $legacyToken;
        }

        $tokenModel = new TokenResetModel();
        if (!$tokenModel->valide($token)) {
            unset($_SESSION['_password_reset_token']);
            setFlash('error', 'Ce lien de reinitialisation est invalide ou a expire.');
            $this->redirect('mot-de-passe-oublie');
            return;
        }

        $motDePasse = (string) $this->input('mot_de_passe', '');
        $confirmation = (string) $this->input('mot_de_passe_confirmation', '');

        if ($motDePasse !== $confirmation) {
            setFlash('error', 'Les deux nouveaux mots de passe ne correspondent pas.');
            $this->redirect('reinitialiser');
            return;
        }

        $passwordError = Security::passwordPolicyError($motDePasse);
        if ($passwordError !== null) {
            setFlash('error', $passwordError);
            $this->redirect('reinitialiser');
            return;
        }

        $userId = (new PasswordResetService())->reset($token, $motDePasse);
        unset($_SESSION['_password_reset_token']);
        if ($userId === null) {
            setFlash('error', 'Ce lien de reinitialisation est invalide ou a expire.');
            $this->redirect('mot-de-passe-oublie');
            return;
        }

        Logger::log($userId, 'securite', 'Mot de passe reinitialise via lien');

        setFlash('success', 'Votre mot de passe a ete reinitialise. Vous pouvez maintenant vous connecter.');
        $this->redirect('login');
    }
}
