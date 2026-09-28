<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

$composerAutoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($composerAutoload)) {
    fwrite(STDERR, "ECHEC RELANCES: dependances Composer absentes. Executez composer install.\n");
    exit(1);
}
require_once $composerAutoload;
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    fwrite(STDERR, "ECHEC RELANCES: PHPMailer est indisponible apres le chargement de Composer.\n");
    exit(1);
}

spl_autoload_register(static function (string $class): void {
    foreach (['core', 'models', 'controllers'] as $directory) {
        $file = BASE_PATH . '/' . $directory . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
require_once BASE_PATH . '/core/helpers.php';

$actualExecution = false;
try {
    $today = null;
    $showStatus = in_array('--status', $argv, true);
    if ($showStatus) {
        $status = (new ReminderRunState(Database::getConnection()))->status();
        echo 'RELANCES: ' . strtoupper($status['state']) . ' , ' . $status['label'] . "\n";
        echo 'Dernier debut : ' . ($status['last_started'] ?? 'jamais') . "\n";
        echo 'Derniere fin : ' . ($status['last_finished'] ?? 'jamais') . "\n";
        if ($status['error'] !== null) {
            echo 'Derniere erreur : ' . $status['error'] . "\n";
        }
        exit(in_array($status['state'], ['failure', 'stale', 'never', 'warning'], true) ? 2 : 0);
    }

    if (in_array('--preview', $argv, true)) {
        $preview = (new MissionReminderService())->preview();
        echo sprintf(
            "APERCU RELANCES: %d mission(s), %d nouvelle(s) notification(s), %d e-mail(s) en reprise, %d e-mail(s) potentiel(s).\n",
            $preview['missions'],
            $preview['nouvelles_relances'],
            $preview['emails_en_reprise'],
            $preview['emails_potentiels']
        );
        foreach ($preview['par_type'] as $type => $count) {
            echo "- {$type}: {$count}\n";
        }
        exit(0);
    }

    if (!(bool) env('ENABLE_MISSION_REMINDERS', true)) {
        echo "RELANCES DESACTIVEES: ENABLE_MISSION_REMINDERS=false.\n";
        exit(0);
    }

    foreach ($argv as $argument) {
        if (!str_starts_with($argument, '--date=')) {
            continue;
        }
        if (APP_ENV !== 'testing') {
            throw new RuntimeException('L option --date est reservee aux tests automatises.');
        }
        $value = substr($argument, strlen('--date='));
        $today = DateTimeImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        if ($today === null || $today->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Date de simulation invalide.');
        }
    }

    $actualExecution = true;
    $stats = (new MissionReminderService())->run($today);
    if ($stats['verrouille']) {
        echo "RELANCES IGNOREES: une autre execution est deja en cours.\n";
        exit(0);
    }

    echo sprintf(
        "RELANCES OK: %d mission(s), %d nouvelle(s) relance(s), %d notification(s), %d e-mail(s), %d erreur(s).\n",
        $stats['missions'],
        $stats['relances'],
        $stats['notifications'],
        $stats['emails'],
        $stats['erreurs']
    );
    exit($stats['erreurs'] > 0 ? 2 : 0);
} catch (Throwable $exception) {
    if ($actualExecution) {
        try {
            (new ReminderRunState(Database::getConnection()))->fail($exception);
        } catch (Throwable) {
            // L'erreur originale reste prioritaire, notamment si MySQL est indisponible.
        }
    }
    error_log('[Relances] Echec fatal : ' . $exception->getMessage());
    fwrite(STDERR, "ECHEC RELANCES: {$exception->getMessage()}\n");
    exit(1);
}
