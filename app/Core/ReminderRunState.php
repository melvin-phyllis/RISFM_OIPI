<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\Administration\ParametreRepository;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Etat de sante persistant du moteur de relances.
 *
 * L'historique metier reste dans relances_missions et activites. Ces quelques
 * parametres servent uniquement de heartbeat afin de detecter un cron absent
 * ou silencieux depuis le back-office et les outils de supervision.
 */
final class ReminderRunState
{
    private const PREFIX = 'relances_';

    private ParametreRepository $parameters;

    public function __construct(PDO $db)
    {
        $this->parameters = new ParametreRepository($db);
    }

    public function start(): void
    {
        $now = date('Y-m-d H:i:s');
        $this->setMany([
            self::PREFIX . 'derniere_execution_debut' => $now,
            self::PREFIX . 'dernier_statut' => 'running',
            self::PREFIX . 'derniere_erreur' => '',
        ]);
    }

    /** @param array<string,int|bool> $stats */
    public function finish(array $stats): void
    {
        $status = !empty($stats['verrouille'])
            ? 'locked'
            : ((int) ($stats['erreurs'] ?? 0) > 0 ? 'warning' : 'ok');
        $this->setMany([
            self::PREFIX . 'derniere_execution_fin' => date('Y-m-d H:i:s'),
            self::PREFIX . 'dernier_statut' => $status,
            self::PREFIX . 'dernier_resume' => json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);
    }

    public function fail(Throwable $exception): void
    {
        $this->setMany([
            self::PREFIX . 'derniere_execution_fin' => date('Y-m-d H:i:s'),
            self::PREFIX . 'dernier_statut' => 'failure',
            self::PREFIX . 'derniere_erreur' => mb_substr($exception->getMessage(), 0, 500),
        ]);
    }

    /**
     * @return array{
     *   enabled:bool,state:string,label:string,class:string,last_started:?string,
     *   last_finished:?string,age_hours:?float,summary:array<string,mixed>,error:?string
     * }
     */
    public function status(?DateTimeImmutable $now = null): array
    {
        $enabled = (bool) env('ENABLE_MISSION_REMINDERS', true);
        $values = $this->values();
        $lastStarted = $this->nullable($values[self::PREFIX . 'derniere_execution_debut'] ?? null);
        $lastFinished = $this->nullable($values[self::PREFIX . 'derniere_execution_fin'] ?? null);
        $storedStatus = (string) ($values[self::PREFIX . 'dernier_statut'] ?? '');
        $error = $this->nullable($values[self::PREFIX . 'derniere_erreur'] ?? null);
        $summary = json_decode((string) ($values[self::PREFIX . 'dernier_resume'] ?? ''), true);
        $summary = is_array($summary) ? $summary : [];
        $now ??= new DateTimeImmutable();
        $ageHours = null;
        if ($lastFinished !== null) {
            $finished = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastFinished);
            if ($finished !== false) {
                $ageHours = max(0.0, ($now->getTimestamp() - $finished->getTimestamp()) / 3600);
            }
        }

        if (!$enabled) {
            [$state, $label, $class] = ['disabled', 'Désactivées par configuration', 'secondary'];
        } elseif ($storedStatus === 'failure') {
            [$state, $label, $class] = ['failure', 'Dernière exécution en échec', 'danger'];
        } elseif ($storedStatus === 'running' && $lastStarted !== null) {
            $started = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $lastStarted);
            $runningHours = $started === false ? 0.0 : max(0.0, ($now->getTimestamp() - $started->getTimestamp()) / 3600);
            [$state, $label, $class] = $runningHours > 2
                ? ['failure', 'Exécution bloquée depuis plus de 2 heures', 'danger']
                : ['running', 'Exécution en cours', 'info'];
        } elseif ($lastFinished === null) {
            [$state, $label, $class] = ['never', 'Aucune exécution enregistrée', 'warning'];
        } elseif ($ageHours !== null && $ageHours > 36) {
            [$state, $label, $class] = ['stale', 'Tâche silencieuse depuis plus de 36 heures', 'danger'];
        } elseif ($storedStatus === 'warning') {
            [$state, $label, $class] = ['warning', 'Exécutée avec des erreurs d’e-mail', 'warning'];
        } elseif ($storedStatus === 'locked') {
            [$state, $label, $class] = ['locked', 'Exécution simultanée ignorée', 'info'];
        } else {
            [$state, $label, $class] = ['ok', 'Opérationnelles', 'success'];
        }

        return [
            'enabled' => $enabled,
            'state' => $state,
            'label' => $label,
            'class' => $class,
            'last_started' => $lastStarted,
            'last_finished' => $lastFinished,
            'age_hours' => $ageHours,
            'summary' => $summary,
            'error' => $error,
        ];
    }

    /** @param array<string,string> $values */
    private function setMany(array $values): void
    {
        $this->parameters->repo_setMany($values);
    }

    /** @return array<string,string> */
    private function values(): array
    {
        return $this->parameters->repo_parPrefixe(self::PREFIX);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
