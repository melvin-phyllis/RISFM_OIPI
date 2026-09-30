<?php
declare(strict_types=1);

namespace App\Services\Mission;

use App\Core\AppMailer;
use App\Core\Logger;
use App\Core\ReminderRunState;
use App\Repositories\Mission\MissionRechercheRepository;
use App\Repositories\Mission\RelanceMissionRepository;
use App\Repositories\Notification\NotificationRepository;
use App\Repositories\Utilisateur\UserRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

/** Genere les rappels d'echeance sans doublon et relance les e-mails en echec. */
final class MissionReminderService
{
    private ?PDO $db;
    private AppMailer $mailer;
    private NotificationRepository $notifications;
    private MissionRechercheRepository $missions;
    private RelanceMissionRepository $reminders;
    private UserRepository $users;

    public function __construct(?PDO $db = null, ?AppMailer $mailer = null)
    {
        // null = connexion de l'application ; les tests passent celle d'une base jetable.
        $this->db = $db;
        $this->mailer = $mailer ?? new AppMailer();
        $this->notifications = new NotificationRepository($this->db);
        $this->missions = new MissionRechercheRepository($this->db);
        $this->reminders = new RelanceMissionRepository($this->db);
        $this->users = new UserRepository($this->db);
    }

    /**
     * Apercu strictement en lecture seule des actions qu'une execution
     * produirait a la date demandee.
     *
     * @return array{
     *   missions:int,nouvelles_relances:int,emails_en_reprise:int,
     *   emails_potentiels:int,par_type:array<string,int>
     * }
     */
    public function srv_preview(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $result = [
            'missions' => 0,
            'nouvelles_relances' => 0,
            'emails_en_reprise' => $this->pendingEmailCount($today),
            'emails_potentiels' => 0,
            'par_type' => [
                'avant_echeance' => 0,
                'echeance' => 0,
                'retard' => 0,
                'escalade' => 0,
            ],
        ];
        $admins = null;
        foreach ($this->activeMissions() as $mission) {
            $result['missions']++;
            $deadline = new DateTimeImmutable((string) $mission['date_echeance']);
            $daysUntil = (int) $today->diff($deadline)->format('%r%a');
            $recipientTypes = [];
            if ($daysUntil === 2) {
                $recipientTypes[] = [(int) $mission['responsable_id'], 'avant_echeance'];
            } elseif ($daysUntil === 0) {
                $recipientTypes[] = [(int) $mission['responsable_id'], 'echeance'];
            } elseif ($daysUntil < 0) {
                $overdueDays = abs($daysUntil);
                if ($overdueDays === 1 || $overdueDays % 3 === 0) {
                    $recipientTypes[] = [(int) $mission['responsable_id'], 'retard'];
                }
                if ($overdueDays >= 7 && ($overdueDays === 7 || $overdueDays % 7 === 0)) {
                    $admins ??= $this->activeAdministrators();
                    foreach ($admins as $admin) {
                        $recipientTypes[] = [(int) $admin['responsable_id'], 'escalade'];
                    }
                }
            }
            foreach ($recipientTypes as [$recipientId, $type]) {
                if ($this->reminderExists(
                    (int) $mission['mission_id'],
                    $recipientId,
                    $type,
                    $today->format('Y-m-d')
                )) {
                    continue;
                }
                $result['nouvelles_relances']++;
                $result['par_type'][$type]++;
            }
        }
        $result['emails_potentiels'] = $result['nouvelles_relances'] + $result['emails_en_reprise'];
        return $result;
    }

    /** @return array{missions:int,relances:int,notifications:int,emails:int,erreurs:int,verrouille:bool} */
    public function srv_run(?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $runState = new ReminderRunState($this->db);
        $stats = [
            'missions' => 0,
            'relances' => 0,
            'notifications' => 0,
            'emails' => 0,
            'erreurs' => 0,
            'verrouille' => false,
        ];

        if (!$this->reminders->repo_acquerirVerrou()) {
            $stats['verrouille'] = true;
            return $stats;
        }

        try {
            $runState->start();
            // Retente d'abord les e-mails des executions precedentes. Une
            // erreur creee aujourd'hui ne sera donc pas repetee immediatement.
            $this->retryPendingEmails($today, $stats);
            $missions = $this->activeMissions();
            $admins = null;
            foreach ($missions as $mission) {
                $stats['missions']++;
                $deadline = new DateTimeImmutable((string) $mission['date_echeance']);
                $daysUntil = (int) $today->diff($deadline)->format('%r%a');

                if ($daysUntil === 2) {
                    $this->processReminder($mission, $mission, 'avant_echeance', $today, $daysUntil, $stats);
                } elseif ($daysUntil === 0) {
                    $this->processReminder($mission, $mission, 'echeance', $today, $daysUntil, $stats);
                } elseif ($daysUntil < 0) {
                    $overdueDays = abs($daysUntil);
                    if ($overdueDays === 1 || $overdueDays % 3 === 0) {
                        $this->processReminder($mission, $mission, 'retard', $today, $daysUntil, $stats);
                    }
                    if ($overdueDays >= 7 && ($overdueDays === 7 || $overdueDays % 7 === 0)) {
                        $admins ??= $this->activeAdministrators();
                        foreach ($admins as $admin) {
                            $this->processReminder($mission, $admin, 'escalade', $today, $daysUntil, $stats);
                        }
                    }
                }
            }

            Logger::log(
                null,
                'rappel',
                sprintf(
                    'Relances automatiques: %d mission(s), %d relance(s), %d e-mail(s), %d erreur(s)',
                    $stats['missions'],
                    $stats['relances'],
                    $stats['emails'],
                    $stats['erreurs']
                ),
                null,
                'relances_missions'
            );
            $runState->finish($stats);
        } catch (Throwable $exception) {
            try {
                $runState->fail($exception);
            } catch (Throwable $stateException) {
                error_log('[Relances] Etat de supervision non enregistre : ' . $stateException->getMessage());
            }
            throw $exception;
        } finally {
            try {
                $this->reminders->repo_libererVerrou();
            } catch (Throwable $exception) {
                error_log('[Relances] Liberation du verrou impossible : ' . $exception->getMessage());
            }
        }

        return $stats;
    }

    /** @return array<int,array<string,mixed>> */
    private function activeMissions(): array
    {
        return $this->missions->repo_activesPourRelance();
    }

    /** @return array<int,array<string,mixed>> */
    private function activeAdministrators(): array
    {
        return $this->users->repo_administrateursActifsPourRelance();
    }

    private function reminderExists(int $missionId, int $recipientId, string $type, string $date): bool
    {
        return $this->reminders->repo_existe($missionId, $recipientId, $type, $date);
    }

    private function pendingEmailCount(DateTimeImmutable $today): int
    {
        return $this->reminders->repo_compterEmailsEnAttente(
            $today->modify('-7 days')->format('Y-m-d')
        );
    }

    /** @param array{missions:int,relances:int,notifications:int,emails:int,erreurs:int,verrouille:bool} $stats */
    private function processReminder(
        array $mission,
        array $recipient,
        string $type,
        DateTimeImmutable $today,
        int $daysUntil,
        array &$stats
    ): void {
        $recipientId = (int) ($recipient['responsable_id'] ?? $recipient['id'] ?? 0);
        if ($recipientId < 1) {
            return;
        }

        $eventId = $this->reminders->repo_creerEvenement(
            (int) $mission['mission_id'],
            $recipientId,
            $type,
            $today->format('Y-m-d'),
            $daysUntil
        );
        if ($eventId === null) {
            return;
        }
        $content = $this->content($mission, $type, $daysUntil);
        $notificationId = $this->notifications->repo_creer(
            $recipientId,
            $content['title'],
            $content['message'],
            $type === 'avant_echeance' || $type === 'echeance' ? 'rappel' : 'alerte',
            url('formulaires/voir/' . $mission['formulaire_id'] . '#mission-' . $mission['mission_id'])
        );
        $this->reminders->repo_associerNotification($eventId, $notificationId);
        $stats['relances']++;
        $stats['notifications']++;

        $this->sendEventEmail($eventId, $mission, $recipient, $type, $daysUntil, $stats);
    }

    /** @param array{missions:int,relances:int,notifications:int,emails:int,erreurs:int,verrouille:bool} $stats */
    private function retryPendingEmails(DateTimeImmutable $today, array &$stats): void
    {
        $since = $today->modify('-7 days')->format('Y-m-d');
        foreach ($this->reminders->repo_emailsEnAttente($since) as $pending) {
            $this->sendEventEmail(
                (int) $pending['relance_id'],
                $pending,
                $pending,
                (string) $pending['type_relance'],
                (int) $pending['jours_ecart'],
                $stats
            );
        }
    }

    /** @param array{missions:int,relances:int,notifications:int,emails:int,erreurs:int,verrouille:bool} $stats */
    private function sendEventEmail(
        int $eventId,
        array $mission,
        array $recipient,
        string $type,
        int $daysUntil,
        array &$stats
    ): void {
        if ($this->reminders->repo_emailEnvoye($eventId)) {
            return;
        }

        try {
            $this->mailer->sendMissionReminder(
                $recipient,
                $mission,
                url('formulaires/voir/' . $mission['formulaire_id'] . '#mission-' . $mission['mission_id']),
                $type,
                $daysUntil
            );
            $this->reminders->repo_marquerEmailEnvoye($eventId);
            $stats['emails']++;
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 500);
            $this->reminders->repo_marquerErreurEmail($eventId, $error);
            error_log('[Relances] E-mail #' . $eventId . ' : ' . $exception->getMessage());
            $stats['erreurs']++;
        }
    }

    /** @return array{title:string,message:string} */
    private function content(array $mission, string $type, int $daysUntil): array
    {
        $reference = (string) $mission['numero_auto'];
        $deadline = (new DateTimeImmutable((string) $mission['date_echeance']))->format('d/m/Y');
        $overdueDays = abs($daysUntil);

        return match ($type) {
            'avant_echeance' => [
                'title' => 'Échéance dans 2 jours',
                'message' => "La mission du formulaire {$reference} doit être terminée avant le {$deadline}.",
            ],
            'echeance' => [
                'title' => 'Échéance aujourd’hui',
                'message' => "La mission du formulaire {$reference} arrive à échéance aujourd’hui.",
            ],
            'retard' => [
                'title' => "Mission en retard de {$overdueDays} jour" . ($overdueDays > 1 ? 's' : ''),
                'message' => "La mission du formulaire {$reference}, attendue le {$deadline}, n’est toujours pas terminée.",
            ],
            'escalade' => [
                'title' => "Escalade : mission en retard de {$overdueDays} jours",
                'message' => "La mission du formulaire {$reference}, affectée à {$mission['responsable_nom']}, est en retard depuis le {$deadline}.",
            ],
            default => throw new InvalidArgumentException('Type de relance invalide.'),
        };
    }
}
