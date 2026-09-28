<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Service SMTP central de RISFM.
 *
 * Toutes les fonctionnalités applicatives (mot de passe oublié, alertes,
 * rapports périodiques) passent par ce service pour une configuration et
 * une charte graphique e-mail unifiées.
 */
class AppMailer
{
    public function isConfigured(): bool
    {
        return env('MAIL_HOST', '') !== ''
            && env('MAIL_USER', '') !== ''
            && env('MAIL_PASS', '') !== ''
            && Security::isValidEmail((string) env('MAIL_FROM', ''));
    }

    /** @throws RuntimeException si la configuration ou l'envoi échoue. */
    public function send(string $recipient, string $subject, string $html, string $text = ''): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Le service SMTP RISFM n\'est pas complètement configuré.');
        }
        if (!Security::isValidEmail($recipient)) {
            throw new InvalidArgumentException('Adresse e-mail destinataire invalide.');
        }

        if (APP_ENV === 'testing' && (bool) env('MAIL_DRY_RUN', false)) {
            return;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = (string) env('MAIL_HOST');
            $mail->Port = (int) env('MAIL_PORT', 587);
            $mail->SMTPAuth = true;
            $mail->Timeout = 10;
            $mail->Username = (string) env('MAIL_USER');
            $mail->Password = str_replace(' ', '', (string) env('MAIL_PASS'));

            $encryption = strtolower((string) env('MAIL_ENCRYPTION', 'tls'));
            $mail->SMTPSecure = $encryption === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;

            $mail->CharSet = 'UTF-8';
            $mail->setFrom(
                (string) env('MAIL_FROM'),
                (string) env('MAIL_FROM_NAME', APP_NAME)
            );
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $html;
            $mail->AltBody = $text !== '' ? $text : strip_tags($html);
            $mail->send();
        } catch (MailException $e) {
            throw new RuntimeException('Échec de l\'envoi SMTP : ' . $mail->ErrorInfo, 0, $e);
        }
    }

    /**
     * Entoure le contenu HTML d'une mise en page e-mail moderne et épurée (Soft UI).
     */
    private function wrapHtml(string $title, string $bodyHtml, string $footerNote = ''): string
    {
        $applicationName = function_exists('appName') ? appName() : (string) APP_NAME;
        $safeAppName = Security::e($applicationName);
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';
        $brandOrange = function_exists('appColor') ? appColor('couleur_primaire', '#F68B1F') : '#F68B1F';
        $logoUrl = function_exists('appLogoUrl') ? appLogoUrl(true) : '';
        $year = date('Y');
        $safeTitle = Security::e($title);

        $logoHtml = $logoUrl !== ''
            ? '<img src="' . Security::e($logoUrl) . '" alt="' . $safeAppName . '" style="max-height:36px;width:auto;vertical-align:middle;margin-right:10px;display:inline-block;border:0">'
            : '';

        $footerNoteHtml = $footerNote !== ''
            ? '<p style="margin:20px 0 0;padding-top:16px;border-top:1px solid #edf2f7;color:#718096;font-size:12px;line-height:1.5">' . $footerNote . '</p>'
            : '';

        return <<<HTML
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$safeTitle}</title>
</head>
<body style="margin:0;padding:0;background-color:#f8fafc;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;color:#1e293b;line-height:1.6">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc;padding:40px 16px">
<tr>
    <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.03);border:1px solid #f1f5f9">
            <!-- En-tête -->
            <tr>
                <td style="padding:20px 32px;background:linear-gradient(135deg, {$brandGreen} 0%, #008741 100%);color:#ffffff">
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                        <tr>
                            <td style="vertical-align:middle">
                                {$logoHtml}
                                <span style="font-size:20px;font-weight:700;letter-spacing:-0.3px;color:#ffffff;display:inline-block;vertical-align:middle">{$safeAppName}</span>
                            </td>
                            <td align="right" style="font-size:12px;color:rgba(255,255,255,0.85);font-weight:500;vertical-align:middle">
                                Notification officielle
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            <!-- Bande décorative subtile -->
            <tr>
                <td style="height:3px;background:{$brandOrange}"></td>
            </tr>
            <!-- Contenu principal -->
            <tr>
                <td style="padding:36px 32px 28px">
                    {$bodyHtml}
                    {$footerNoteHtml}
                </td>
            </tr>
        </table>
        <!-- Pied de page -->
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;margin-top:20px">
            <tr>
                <td style="text-align:center;color:#94a3b8;font-size:12px;line-height:1.5;padding:0 10px">
                    &copy; {$year} {$safeAppName}. Tous droits réservés.<br>
                    Ceci est un message automatique, veuillez ne pas y répondre directement.
                </td>
            </tr>
        </table>
    </td>
</tr>
</table>
</body>
</html>
HTML;
    }

    /**
     * Génère un bouton d'action principal (CTA) stylisé.
     */
    private function renderCta(string $url, string $label, string $color = ''): string
    {
        $bg = $color !== '' ? $color : (function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651');
        $safeUrl = Security::e($url);
        $safeLabel = Security::e($label);

        return <<<HTML
<div style="margin:32px 0 24px;text-align:center">
    <a href="{$safeUrl}" target="_blank" style="display:inline-block;background-color:{$bg};color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:10px;font-size:15px;font-weight:600;letter-spacing:-0.2px;box-shadow:0 4px 12px rgba(0,0,0,0.15);transition:all 0.2s ease">{$safeLabel}</a>
</div>
HTML;
    }

    public function sendPasswordReset(array $user, string $resetUrl, int $validityMinutes = 60): void
    {
        $name = trim((string) ($user['prenoms'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $safeName = Security::e($name !== '' ? $name : 'utilisateur');
        $applicationName = function_exists('appName') ? appName() : (string) APP_NAME;
        $safeAppName = Security::e($applicationName);
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';

        $body = <<<HTML
<h2 style="margin:0 0 16px;color:#0f172a;font-size:22px;font-weight:700;letter-spacing:-0.5px">Réinitialisation de mot de passe</h2>
<p style="margin:0 0 16px;color:#334155;font-size:15px">Bonjour <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 20px;color:#475569;font-size:15px">Une demande de réinitialisation de mot de passe a été émise pour votre compte <strong>{$safeAppName}</strong>.</p>
{$this->renderCta($resetUrl, 'Définir un nouveau mot de passe', $brandGreen)}
<p style="margin:20px 0 0;color:#64748b;font-size:13px;background:#f8fafc;padding:12px 16px;border-radius:8px;border:1px solid #e2e8f0">
    <strong style="color:#334155">Attention :</strong> Ce lien expire dans <strong>{$validityMinutes} minutes</strong> et ne peut être utilisé qu’une seule fois.
</p>
HTML;

        $footer = "Si vous n'êtes pas à l'origine de cette demande, vous pouvez ignorer cet e-mail en toute sécurité. Votre mot de passe restera inchangé.";
        $html = $this->wrapHtml('Réinitialisation de votre mot de passe', $body, $footer);

        $text = "Bonjour {$name},\n\n"
            . "Utilisez ce lien pour réinitialiser votre mot de passe : {$resetUrl}\n"
            . "Ce lien expire dans {$validityMinutes} minutes et ne peut être utilisé qu'une seule fois.\n\n"
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.";

        $this->send((string) $user['email'], 'Réinitialisation de votre mot de passe - ' . $applicationName, $html, $text);
    }

    public function sendAccountAccess(array $user, string $accessUrl, int $validityMinutes = 60, bool $newAccount = false): void
    {
        $message = $this->buildAccountAccessMessage($user, $accessUrl, $validityMinutes, $newAccount);
        $this->send((string) $user['email'], $message['subject'], $message['html'], $message['text']);
    }

    /**
     * @return array{subject:string, html:string, text:string}
     */
    public function buildAccountAccessMessage(
        array $user,
        string $accessUrl,
        int $validityMinutes = 60,
        bool $newAccount = false
    ): array {
        $validityMinutes = max(5, min(1440, $validityMinutes));
        $name = trim((string) ($user['prenoms'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $identifier = trim((string) ($user['identifiant'] ?? ''));
        if ($identifier === '') {
            throw new InvalidArgumentException('Identifiant utilisateur manquant pour l’invitation.');
        }

        $safeName = Security::e($name !== '' ? $name : 'utilisateur');
        $safeIdentifier = Security::e($identifier);
        $applicationName = function_exists('appName') ? appName() : (string) APP_NAME;
        $safeAppName = Security::e($applicationName);
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';

        $title = $newAccount ? 'Activation de votre compte' : 'Nouvel accès à votre compte';
        $intro = $newAccount
            ? "Un compte <strong>{$safeAppName}</strong> vient d’être créé pour vous."
            : "Un administrateur a demandé le renouvellement de l’accès à votre compte <strong>{$safeAppName}</strong>.";

        $body = <<<HTML
<h2 style="margin:0 0 16px;color:#0f172a;font-size:22px;font-weight:700;letter-spacing:-0.5px">{$title}</h2>
<p style="margin:0 0 16px;color:#334155;font-size:15px">Bonjour <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 20px;color:#475569;font-size:15px">{$intro}</p>

<div style="background:#f1f5f9;border-left:4px solid {$brandGreen};padding:16px 20px;border-radius:0 10px 10px 0;margin:24px 0">
    <span style="display:block;font-size:12px;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;font-weight:600;margin-bottom:4px">Votre identifiant de connexion</span>
    <span style="font-size:18px;font-weight:700;color:#0f172a;font-family:monospace">{$safeIdentifier}</span>
</div>

{$this->renderCta($accessUrl, 'Choisir mon mot de passe', $brandGreen)}

<p style="margin:20px 0 0;color:#64748b;font-size:13px;background:#f8fafc;padding:12px 16px;border-radius:8px;border:1px solid #e2e8f0">
    <strong style="color:#334155">Sécurité :</strong> Ce lien d'activation expire dans <strong>{$validityMinutes} minutes</strong> et ne peut être utilisé qu’une seule fois.
</p>
HTML;

        $footer = "Ne transférez pas ce message : ce lien permet de définir l’accès direct à votre compte. Si vous n’attendiez pas cet e-mail, contactez l’administrateur.";
        $html = $this->wrapHtml($title, $body, $footer);

        $text = "Bonjour {$name},\n\n"
            . strip_tags($intro) . "\nVotre identifiant de connexion est : {$identifier}\n\n"
            . "Choisissez votre mot de passe avec ce lien : {$accessUrl}\n"
            . "Ce lien expire dans {$validityMinutes} minutes et ne peut être utilisé qu'une seule fois.\n\n"
            . "Ne transférez pas ce message. Si vous ne l'attendiez pas, contactez l'administrateur.";

        return [
            'subject' => $title . ' - ' . $applicationName,
            'html' => $html,
            'text' => $text,
        ];
    }

    public function sendLoginCode(array $user, string $code, int $validityMinutes = 10): void
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new InvalidArgumentException('Code de connexion invalide.');
        }

        $name = trim((string) ($user['prenoms'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $safeName = Security::e($name !== '' ? $name : 'utilisateur');
        $applicationName = function_exists('appName') ? appName() : (string) APP_NAME;
        $safeAppName = Security::e($applicationName);
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';

        $body = <<<HTML
<h2 style="margin:0 0 16px;color:#0f172a;font-size:22px;font-weight:700;letter-spacing:-0.5px">Code de connexion de sécurité</h2>
<p style="margin:0 0 16px;color:#334155;font-size:15px">Bonjour <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 24px;color:#475569;font-size:15px">Voici votre code de vérification à usage unique pour vous connecter à <strong>{$safeAppName}</strong> :</p>

<div style="text-align:center;margin:28px 0">
    <div style="display:inline-block;background:#f8fafc;border:2px dashed {$brandGreen};padding:16px 32px;border-radius:12px">
        <span style="font-size:36px;font-weight:800;letter-spacing:10px;color:#0f172a;font-family:Consolas,Monaco,monospace">{$code}</span>
    </div>
</div>

<p style="margin:20px 0 0;color:#64748b;font-size:13px;text-align:center">
    Ce code est valide pendant <strong>{$validityMinutes} minutes</strong>.
</p>
HTML;

        $footer = "Si vous n’êtes pas à l’origine de cette tentative de connexion, veuillez changer immédiatement votre mot de passe et alerter votre administrateur.";
        $html = $this->wrapHtml("Code de sécurité {$safeAppName}", $body, $footer);

        $text = "Bonjour {$name},\n\nVotre code de sécurité {$applicationName} est : {$code}\n"
            . "Il expire dans {$validityMinutes} minutes et ne peut être utilisé qu'une seule fois.\n\n"
            . "Si vous n'êtes pas à l'origine de cette connexion, contactez l'administrateur.";

        $this->send((string) $user['email'], 'Votre code de sécurité - ' . $applicationName, $html, $text);
    }

    public function sendAssignment(array $user, array $formulaire, string $formUrl, bool $reassignment = false): void
    {
        $message = $this->buildAssignmentMessage($user, $formulaire, $formUrl, $reassignment);
        $this->send((string) $user['email'], $message['subject'], $message['html'], $message['text']);
    }

    /**
     * Notifie la personne qui a assigne la mission que le formulaire a ete retrouve.
     *
     * @param array $assignedBy Utilisateur ayant assigne la mission (affecte_par)
     * @param array $foundBy    Utilisateur ayant retrouve le formulaire (responsable)
     * @param array $formulaire Formulaire avec ses relations (numero_auto, type_libelle…)
     * @param array $mission    Mission avec ses relations (localisation_libelle, date_recherche…)
     * @param string $formUrl   URL de la fiche formulaire
     */
    public function sendResultFound(
        array $assignedBy,
        array $foundBy,
        array $formulaire,
        array $mission,
        string $formUrl
    ): void {
        $message = $this->buildResultFoundMessage($assignedBy, $foundBy, $formulaire, $mission, $formUrl);
        $this->send((string) $assignedBy['email'], $message['subject'], $message['html'], $message['text']);
    }

    /** @return array{subject:string, html:string, text:string} */
    public function buildResultFoundMessage(
        array $assignedBy,
        array $foundBy,
        array $formulaire,
        array $mission,
        string $formUrl
    ): array {
        $name = trim((string) ($assignedBy['prenoms'] ?? '') . ' ' . (string) ($assignedBy['nom'] ?? ''));
        $safeName = Security::e($name !== '' ? $name : 'utilisateur');
        $foundByName = trim((string) ($foundBy['prenoms'] ?? '') . ' ' . (string) ($foundBy['nom'] ?? ''));
        $safeFoundByName = Security::e($foundByName !== '' ? $foundByName : 'un agent');
        $reference = Security::e((string) ($formulaire['numero_auto'] ?? ('#' . ($formulaire['id'] ?? ''))));
        $numero = Security::e((string) ($formulaire['numero_formulaire'] ?? '-'));
        $type = Security::e((string) ($formulaire['type_libelle'] ?? '-'));
        $localisation = Security::e((string) ($mission['localisation_libelle'] ?? 'Non definie'));
        $dateRecherche = trim((string) ($mission['date_recherche'] ?? ''));
        $dateRechercheFmt = $dateRecherche !== ''
            ? Security::e((new DateTimeImmutable($dateRecherche))->format('d/m/Y'))
            : 'Non precisee';
        $resultat = Security::e(trim((string) ($mission['resultat'] ?? '')) !== ''
            ? (string) $mission['resultat']
            : 'Formulaire retrouve');

        $applicationName = function_exists('appName') ? appName() : (string) APP_NAME;
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';

        $body = <<<HTML
<div style="display:inline-block;padding:4px 12px;border-radius:20px;background:{$brandGreen};color:#ffffff;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:16px">Formulaire retrouve</div>
<h2 style="margin:0 0 16px;color:#0f172a;font-size:22px;font-weight:700;letter-spacing:-0.5px">Bonne nouvelle !</h2>
<p style="margin:0 0 16px;color:#334155;font-size:15px">Bonjour <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 20px;color:#475569;font-size:15px">Le dossier <strong>{$reference}</strong> que vous aviez affecte pour recherche a ete <strong style="color:{$brandGreen}">retrouve</strong> par <strong>{$safeFoundByName}</strong>.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;margin:24px 0">
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;width:35%;font-size:13px">Ref. Formulaire</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;font-size:14px">{$numero}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;font-size:13px">Type de dossier</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:14px">{$type}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;font-size:13px">Localisation</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:14px">{$localisation}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;font-size:13px">Retrouve par</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:14px">{$safeFoundByName}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;font-size:13px">Date de recherche</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:14px">{$dateRechercheFmt}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;font-weight:600;color:#64748b;font-size:13px">Resultat</td>
        <td style="padding:14px 18px;color:{$brandGreen};font-weight:700;font-size:14px">{$resultat}</td>
    </tr>
</table>

{$this->renderCta($formUrl, 'Consulter le dossier', $brandGreen)}
HTML;

        $text = "Bonjour {$name},\n\n"
            . "Le formulaire {$reference} ({$numero}) que vous aviez affecte pour recherche a ete retrouve par {$foundByName}.\n"
            . "Localisation : {$localisation}\nDate de recherche : {$dateRechercheFmt}\nResultat : {$resultat}\n\n"
            . "Consulter le dossier : {$formUrl}";

        return [
            'subject' => 'Formulaire retrouve - ' . strip_tags($reference) . ' - ' . $applicationName,
            'html' => $this->wrapHtml('Formulaire retrouve', $body),
            'text' => $text,
        ];
    }

    public function sendMissionReminder(array $user, array $mission, string $missionUrl, string $type, int $daysUntil): void
    {
        $message = $this->buildMissionReminderMessage($user, $mission, $missionUrl, $type, $daysUntil);
        $this->send((string) $user['email'], $message['subject'], $message['html'], $message['text']);
    }

    /** @return array{subject:string,html:string,text:string} */
    public function buildMissionReminderMessage(array $user, array $mission, string $missionUrl, string $type, int $daysUntil): array
    {
        if (!in_array($type, ['avant_echeance', 'echeance', 'retard', 'escalade'], true)) {
            throw new InvalidArgumentException('Type de relance invalide.');
        }

        $name = trim((string) ($user['prenoms'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $reference = (string) ($mission['numero_auto'] ?? ('#' . ($mission['formulaire_id'] ?? '')));
        $deadline = (new DateTimeImmutable((string) $mission['date_echeance']))->format('d/m/Y');
        $overdueDays = abs($daysUntil);

        [$title, $description, $badgeColor] = match ($type) {
            'avant_echeance' => ['Rappel : échéance dans 2 jours', "La mission <strong>{$reference}</strong> doit être terminée avant le <strong>{$deadline}</strong>.", '#f59e0b'],
            'echeance'       => ['Rappel : échéance aujourd’hui', "La mission <strong>{$reference}</strong> arrive à échéance aujourd’hui.", '#d97706'],
            'retard'         => ["Alerte : mission en retard de {$overdueDays} jour" . ($overdueDays > 1 ? 's' : ''), "La mission <strong>{$reference}</strong>, attendue le <strong>{$deadline}</strong>, n’est toujours pas terminée.", '#ef4444'],
            'escalade'       => ["Escalade : mission en retard de {$overdueDays} jours", "La mission <strong>{$reference}</strong>, attendue le <strong>{$deadline}</strong>, nécessite un suivi administratif urgent.", '#dc2626'],
        };

        $safeName = Security::e($name !== '' ? $name : 'utilisateur');
        $safeTitle = Security::e($title);
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';

        $body = <<<HTML
<div style="display:inline-block;padding:4px 12px;border-radius:20px;background:{$badgeColor};color:#ffffff;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:16px">Rappel de Suivi</div>
<h2 style="margin:0 0 16px;color:#0f172a;font-size:22px;font-weight:700;letter-spacing:-0.5px">{$safeTitle}</h2>
<p style="margin:0 0 16px;color:#334155;font-size:15px">Bonjour <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 20px;color:#475569;font-size:15px">{$description}</p>
{$this->renderCta($missionUrl, 'Consulter la mission', $brandGreen)}
HTML;

        $text = "Bonjour {$name},\n\n{$description}\n\nConsulter la mission : {$missionUrl}";
        return [
            'subject' => $title . ' - ' . (function_exists('appName') ? appName() : APP_NAME),
            'html' => $this->wrapHtml($title, $body),
            'text' => $text
        ];
    }

    /**
     * @return array{subject:string, html:string, text:string}
     */
    public function buildAssignmentMessage(array $user, array $formulaire, string $formUrl, bool $reassignment = false): array
    {
        $name = trim((string) ($user['prenoms'] ?? '') . ' ' . (string) ($user['nom'] ?? ''));
        $safeName = Security::e($name !== '' ? $name : 'utilisateur');
        $reference = Security::e((string) ($formulaire['numero_auto'] ?? ('#' . ($formulaire['id'] ?? ''))));
        $numero = Security::e((string) ($formulaire['numero_formulaire'] ?? '-'));
        $type = Security::e((string) ($formulaire['type_libelle'] ?? '-'));
        $localisation = Security::e((string) ($formulaire['localisation_libelle'] ?? 'Non définie'));
        $deadlineValue = trim((string) ($formulaire['date_echeance_recherche'] ?? ''));
        $deadline = $deadlineValue !== ''
            ? Security::e((new DateTimeImmutable($deadlineValue))->format('d/m/Y'))
            : 'Non définie';

        $action = $reassignment ? 'réaffecté' : 'affecté';
        $brandGreen = function_exists('appColor') ? appColor('couleur_secondaire', '#00A651') : '#00A651';

        $body = <<<HTML
<h2 style="margin:0 0 16px;color:#0f172a;font-size:22px;font-weight:700;letter-spacing:-0.5px">Nouvelle affectation de dossier</h2>
<p style="margin:0 0 16px;color:#334155;font-size:15px">Bonjour <strong>{$safeName}</strong>,</p>
<p style="margin:0 0 20px;color:#475569;font-size:15px">Le dossier <strong>{$reference}</strong> vous a été {$action} pour traitement/recherche.</p>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-radius:12px;border:1px solid #e2e8f0;margin:24px 0">
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;width:35%;font-size:13px">Réf. Formulaire</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:700;color:#0f172a;font-size:14px">{$numero}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;font-size:13px">Type de dossier</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:14px">{$type}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;font-weight:600;color:#64748b;font-size:13px">Localisation</td>
        <td style="padding:14px 18px;border-bottom:1px solid #e2e8f0;color:#334155;font-size:14px">{$localisation}</td>
    </tr>
    <tr>
        <td style="padding:14px 18px;font-weight:600;color:#64748b;font-size:13px">Date limite</td>
        <td style="padding:14px 18px;color:#d97706;font-weight:700;font-size:14px">{$deadline}</td>
    </tr>
</table>

{$this->renderCta($formUrl, 'Consulter le dossier', $brandGreen)}
HTML;

        $text = "Bonjour {$name},\n\nLe formulaire {$reference} ({$numero}) vous a été {$action} pour recherche.\n"
            . "Localisation à inspecter : {$localisation}\nDate limite : {$deadline}\n{$formUrl}";

        return [
            'subject' => 'Nouvelle affectation RISFM - ' . strip_tags($reference),
            'html' => $this->wrapHtml('Nouvelle affectation', $body),
            'text' => $text,
        ];
    }
}
