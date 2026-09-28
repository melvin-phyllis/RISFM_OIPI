<?php
declare(strict_types=1);

/** Genere les rappels d'echeance sans doublon et relance les e-mails en echec. */
final class MissionReminderService
{
    private PDO $db;
    private AppMailer $mailer;
    private NotificationModel $notifications;

    public function __construct(?PDO $db = null, ?AppMailer $mailer = null)
    {
        $this->db = $db ?? Database::getConnection();
        $this->mailer = $mailer ?? new AppMailer();
        $this->notifications = new NotificationModel();
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
    public function preview(?DateTimeImmutable $today = null): array
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
    public function run(?DateTimeImmutable $today = null): array
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

        $lock = $this->db->prepare("SELECT GET_LOCK('risfm_relances_missions', 0)");
        $lock->execute();
        if ((int) $lock->fetchColumn() !== 1) {
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
                $this->db->query("SELECT RELEASE_LOCK('risfm_relances_missions')")->fetchColumn();
            } catch (Throwable $exception) {
                error_log('[Relances] Liberation du verrou impossible : ' . $exception->getMessage());
            }
        }

        return $stats;
    }

    /** @return array<int,array<string,mixed>> */
    private function activeMissions(): array
    {
        return $this->db->query(
            "SELECT m.id AS mission_id, m.formulaire_id, m.responsable_id, m.date_echeance,
                    m.priorite, f.numero_auto, f.numero_formulaire,
                    t.libelle AS type_libelle, l.libelle AS localisation_libelle,
                    u.identifiant, u.nom, u.prenoms, u.email, u.actif,
                    CONCAT_WS(' ', u.nom, u.prenoms) AS responsable_nom
             FROM missions_recherche m
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             JOIN types_titres t ON t.id = f.type_titre_id
             JOIN localisations l ON l.id = m.localisation_id
             JOIN utilisateurs u ON u.id = m.responsable_id AND u.actif = 1
             WHERE m.etat IN ('affectee','en_cours')
               AND m.date_echeance IS NOT NULL
             ORDER BY m.date_echeance, m.id"
        )->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    private function activeAdministrators(): array
    {
        return $this->db->query(
            "SELECT id AS responsable_id, identifiant, nom, prenoms, email, actif
             FROM utilisateurs
             WHERE actif = 1 AND role = 'administrateur'
             ORDER BY id"
        )->fetchAll();
    }

    private function reminderExists(int $missionId, int $recipientId, string $type, string $date): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM relances_missions
             WHERE mission_id = :mission_id
               AND destinataire_id = :destinataire_id
               AND type_relance = :type_relance
               AND date_relance = :date_relance'
        );
        $statement->execute([
            'mission_id' => $missionId,
            'destinataire_id' => $recipientId,
            'type_relance' => $type,
            'date_relance' => $date,
        ]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function pendingEmailCount(DateTimeImmutable $today): int
    {
        $statement = $this->db->prepare(
            "SELECT COUNT(*)
             FROM relances_missions r
             JOIN missions_recherche m
               ON m.id = r.mission_id AND m.etat IN ('affectee','en_cours')
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             JOIN utilisateurs u ON u.id = r.destinataire_id AND u.actif = 1
             WHERE r.email_envoye = 0 AND r.date_relance >= :depuis"
        );
        $statement->execute(['depuis' => $today->modify('-7 days')->format('Y-m-d')]);
        return (int) $statement->fetchColumn();
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

        $insert = $this->db->prepare(
            'INSERT IGNORE INTO relances_missions
                (mission_id, destinataire_id, type_relance, date_relance, jours_ecart)
             VALUES
                (:mission_id, :destinataire_id, :type_relance, :date_relance, :jours_ecart)'
        );
        $insert->execute([
            'mission_id' => (int) $mission['mission_id'],
            'destinataire_id' => $recipientId,
            'type_relance' => $type,
            'date_relance' => $today->format('Y-m-d'),
            'jours_ecart' => $daysUntil,
        ]);
        if ($insert->rowCount() !== 1) {
            return;
        }

        $eventId = (int) $this->db->lastInsertId();
        $content = $this->content($mission, $type, $daysUntil);
        $notificationId = $this->notifications->creer(
            $recipientId,
            $content['title'],
            $content['message'],
            $type === 'avant_echeance' || $type === 'echeance' ? 'rappel' : 'alerte',
            url('formulaires/voir/' . $mission['formulaire_id'] . '#mission-' . $mission['mission_id'])
        );
        $this->db->prepare(
            'UPDATE relances_missions SET notification_id = :notification_id WHERE id = :id'
        )->execute(['notification_id' => $notificationId, 'id' => $eventId]);
        $stats['relances']++;
        $stats['notifications']++;

        $this->sendEventEmail($eventId, $mission, $recipient, $type, $daysUntil, $stats);
    }

    /** @param array{missions:int,relances:int,notifications:int,emails:int,erreurs:int,verrouille:bool} $stats */
    private function retryPendingEmails(DateTimeImmutable $today, array &$stats): void
    {
        $since = $today->modify('-7 days')->format('Y-m-d');
        $stmt = $this->db->prepare(
            "SELECT r.id AS relance_id, r.type_relance, r.jours_ecart,
                    m.id AS mission_id, m.formulaire_id, m.responsable_id, m.date_echeance, m.priorite,
                    f.numero_auto, f.numero_formulaire,
                    t.libelle AS type_libelle, l.libelle AS localisation_libelle,
                    u.id AS destinataire_id, u.identifiant, u.nom, u.prenoms, u.email, u.actif
             FROM relances_missions r
             JOIN missions_recherche m ON m.id = r.mission_id AND m.etat IN ('affectee','en_cours')
             JOIN formulaires_manquants f
               ON f.id = m.formulaire_id
              AND f.cycle_suivi = m.cycle_suivi
              AND f.est_archive = 0
             JOIN types_titres t ON t.id = f.type_titre_id
             JOIN localisations l ON l.id = m.localisation_id
             JOIN utilisateurs u ON u.id = r.destinataire_id AND u.actif = 1
             WHERE r.email_envoye = 0 AND r.date_relance >= :depuis
             ORDER BY r.id"
        );
        $stmt->execute(['depuis' => $since]);
        foreach ($stmt->fetchAll() as $pending) {
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
        $sent = $this->db->prepare('SELECT email_envoye FROM relances_missions WHERE id = :id');
        $sent->execute(['id' => $eventId]);
        if ((int) $sent->fetchColumn() === 1) {
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
            $this->db->prepare(
                'UPDATE relances_missions
                 SET email_envoye = 1, email_tente_le = NOW(), email_erreur = NULL
                 WHERE id = :id'
            )->execute(['id' => $eventId]);
            $stats['emails']++;
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 500);
            $this->db->prepare(
                'UPDATE relances_missions
                 SET email_tente_le = NOW(), email_erreur = :erreur
                 WHERE id = :id'
            )->execute(['erreur' => $error, 'id' => $eventId]);
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
