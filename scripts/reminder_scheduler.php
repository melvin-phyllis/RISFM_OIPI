<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/config/config.php';

/**
 * Installe sans ecraser les autres lignes de la crontab utilisateur.
 *
 * Commandes :
 *   php scripts/reminder_scheduler.php status
 *   php scripts/reminder_scheduler.php install
 *   php scripts/reminder_scheduler.php remove --confirm
 */

$action = $argv[1] ?? 'status';
$schedule = trim((string) env('REMINDER_CRON_SCHEDULE', '0 8 * * *'));
if (!preg_match('/^[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+\s+[0-9*\/,\-]+$/', $schedule)) {
    fwrite(STDERR, "REMINDER_CRON_SCHEDULE invalide : cinq champs cron sont attendus.\n");
    exit(1);
}

$identifier = substr(hash('sha256', BASE_PATH), 0, 12);
$begin = "# BEGIN OIPI-RISFM REMINDERS {$identifier}";
$end = "# END OIPI-RISFM REMINDERS {$identifier}";
$logDirectory = STORAGE_PATH . '/logs';
$logFile = $logDirectory . '/relances-cron.log';
$phpBinary = PHP_BINARY;
$command = sprintf(
    '%s cd %s && %s %s >> %s 2>&1',
    $schedule,
    escapeshellarg(BASE_PATH),
    escapeshellarg($phpBinary),
    escapeshellarg(BASE_PATH . '/scripts/relances.php'),
    escapeshellarg($logFile)
);
$block = $begin . "\n" . $command . "\n" . $end;

[$listCode, $current, $listError] = runSchedulerCommand(['crontab', '-l']);
if ($listCode !== 0) {
    $noCrontab = stripos($listError . $current, 'no crontab') !== false;
    if (!$noCrontab) {
        fwrite(STDERR, 'Lecture de la crontab impossible : ' . trim($listError . ' ' . $current) . "\n");
        exit(1);
    }
    $current = '';
}
$hasBegin = str_contains($current, $begin);
$hasEnd = str_contains($current, $end);
if ($hasBegin !== $hasEnd) {
    fwrite(STDERR, "Bloc RISFM incomplet dans la crontab. Corrigez ses marqueurs avant toute modification automatique.\n");
    exit(1);
}
$installed = $hasBegin && $hasEnd;

if ($action === 'status') {
    echo $installed
        ? "PLANIFICATEUR RELANCES: INSTALLE\n"
        : "PLANIFICATEUR RELANCES: ABSENT\n";
    echo "Horaire : {$schedule}\n";
    echo "Commande : {$command}\n";
    exit($installed ? 0 : 3);
}

if ($action === 'install') {
    if (!is_dir($logDirectory) && !mkdir($logDirectory, 0750, true) && !is_dir($logDirectory)) {
        fwrite(STDERR, "Creation de storage/logs impossible.\n");
        exit(1);
    }
    if (!is_file($logFile) && file_put_contents($logFile, '') === false) {
        fwrite(STDERR, "Creation du journal des relances impossible.\n");
        exit(1);
    }
    @chmod($logFile, 0640);
    $updated = replaceManagedBlock($current, $begin, $end, $block);
    [$writeCode, $writeOutput, $writeError] = runSchedulerCommand(['crontab', '-'], $updated);
    if ($writeCode !== 0) {
        fwrite(STDERR, 'Installation impossible : ' . trim($writeError . ' ' . $writeOutput) . "\n");
        exit(1);
    }
    echo ($installed ? "PLANIFICATEUR RELANCES: MIS A JOUR\n" : "PLANIFICATEUR RELANCES: INSTALLE\n");
    echo "Horaire : {$schedule}\nJournal : {$logFile}\n";
    exit(0);
}

if ($action === 'remove') {
    if (!in_array('--confirm', $argv, true)) {
        fwrite(STDERR, "Suppression refusee sans --confirm.\n");
        exit(1);
    }
    if (!$installed) {
        echo "PLANIFICATEUR RELANCES: DEJA ABSENT\n";
        exit(0);
    }
    $updated = replaceManagedBlock($current, $begin, $end, '');
    [$writeCode, $writeOutput, $writeError] = runSchedulerCommand(['crontab', '-'], $updated);
    if ($writeCode !== 0) {
        fwrite(STDERR, 'Suppression impossible : ' . trim($writeError . ' ' . $writeOutput) . "\n");
        exit(1);
    }
    echo "PLANIFICATEUR RELANCES: SUPPRIME\n";
    exit(0);
}

fwrite(STDERR, "Action inconnue. Utilisez status, install ou remove --confirm.\n");
exit(1);

/** @return array{int,string,string} */
function runSchedulerCommand(array $command, ?string $input = null): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        BASE_PATH
    );
    if (!is_resource($process)) {
        return [1, '', 'Demarrage de crontab impossible'];
    }
    if ($input !== null) {
        fwrite($pipes[0], $input);
    }
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]);
    $error = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $output, $error];
}

function replaceManagedBlock(string $crontab, string $begin, string $end, string $replacement): string
{
    $lines = preg_split('/\R/', rtrim($crontab));
    $lines = is_array($lines) ? $lines : [];
    $result = [];
    $inside = false;
    foreach ($lines as $line) {
        if ($line === $begin) {
            $inside = true;
            continue;
        }
        if ($inside && $line === $end) {
            $inside = false;
            continue;
        }
        if (!$inside && $line !== '') {
            $result[] = $line;
        }
    }
    if ($replacement !== '') {
        if ($result !== []) {
            $result[] = '';
        }
        array_push($result, ...explode("\n", $replacement));
    }
    return implode("\n", $result) . "\n";
}
