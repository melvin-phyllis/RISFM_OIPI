<?php
declare(strict_types=1);

/** Decoupe un script MySQL en respectant chaines, commentaires et DELIMITER. */
final class SqlStatementParser
{
    /** @return list<string> */
    public static function parse(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $delimiter = ';';

        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)
                && !self::containsExecutableSql($buffer)
            ) {
                $buffer = '';
                $delimiter = $match[1];
                continue;
            }

            $buffer .= $line . "\n";
            [$complete, $buffer] = self::extractComplete($buffer, $delimiter);
            foreach ($complete as $statement) {
                if (self::containsExecutableSql($statement)) {
                    $statements[] = trim($statement);
                }
            }
        }

        if (self::containsExecutableSql($buffer)) {
            $statements[] = trim($buffer);
        }
        return $statements;
    }

    /** @return array{0:list<string>,1:string} */
    private static function extractComplete(string $buffer, string $delimiter): array
    {
        $statements = [];
        $start = 0;
        $length = strlen($buffer);
        $delimiterLength = strlen($delimiter);
        $state = 'normal';

        for ($index = 0; $index < $length; $index++) {
            $char = $buffer[$index];
            $next = $index + 1 < $length ? $buffer[$index + 1] : '';

            if ($state === 'line_comment') {
                if ($char === "\n") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block_comment') {
                if ($char === '*' && $next === '/') {
                    $state = 'normal';
                    $index++;
                }
                continue;
            }
            if (in_array($state, ['single', 'double', 'backtick'], true)) {
                $quote = $state === 'single' ? "'" : ($state === 'double' ? '"' : '`');
                if ($char === '\\') {
                    $index++;
                    continue;
                }
                if ($char === $quote) {
                    if ($next === $quote) {
                        $index++;
                    } else {
                        $state = 'normal';
                    }
                }
                continue;
            }

            if ($char === '-' && $next === '-' && ($index + 2 >= $length || ctype_space($buffer[$index + 2]))) {
                $state = 'line_comment';
                $index++;
                continue;
            }
            if ($char === '#') {
                $state = 'line_comment';
                continue;
            }
            if ($char === '/' && $next === '*') {
                $state = 'block_comment';
                $index++;
                continue;
            }
            if ($char === "'") {
                $state = 'single';
                continue;
            }
            if ($char === '"') {
                $state = 'double';
                continue;
            }
            if ($char === '`') {
                $state = 'backtick';
                continue;
            }

            if ($delimiterLength > 0 && substr($buffer, $index, $delimiterLength) === $delimiter) {
                $statements[] = substr($buffer, $start, $index - $start);
                $index += $delimiterLength - 1;
                $start = $index + 1;
            }
        }

        return [$statements, substr($buffer, $start)];
    }

    private static function containsExecutableSql(string $sql): bool
    {
        $withoutComments = preg_replace('/\/\*(?!\!).*?\*\//s', ' ', $sql) ?? $sql;
        $withoutComments = preg_replace('/(?:^|\R)\s*--[^\r\n]*/m', "\n", $withoutComments) ?? $withoutComments;
        $withoutComments = preg_replace('/(?:^|\R)\s*#[^\r\n]*/m', "\n", $withoutComments) ?? $withoutComments;
        return trim($withoutComments) !== '';
    }
}
