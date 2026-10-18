<?php

declare(strict_types=1);

const REDACTED = '[REDACTED]';

function isSensitiveName(string $name): bool
{
    return preg_match('/(?:password|secret|token|signature)/i', $name) === 1;
}

/** @return list<string> */
function configuredSecrets(): array
{
    $secrets = [];

    foreach (['ADMIN_PASSWORD', 'QA_CASHIER_PASSWORD', 'PAYMONGO_WEBHOOK_SECRET'] as $name) {
        $value = getenv($name);

        if (is_string($value) && $value !== '') {
            $secrets[] = $value;
        }
    }

    usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    return array_values(array_unique($secrets));
}

/** @param list<string> $secrets */
function scrubText(string $text, array $secrets): string
{
    if ($secrets !== []) {
        $text = str_replace($secrets, REDACTED, $text);
    }

    return preg_replace(
        [
            '#\bBearer\s+[^\s"\'<>]+#i',
            '#(?<![A-Za-z0-9])\d+\|[A-Za-z0-9._~+/=-]{8,}(?![A-Za-z0-9])#',
            '#\bt=[^,\s;&"<>]+,\s*te=[^,\s;&"<>]+#i',
        ],
        [
            'Bearer '.REDACTED,
            REDACTED,
            REDACTED,
        ],
        $text,
    ) ?? throw new RuntimeException('Could not scrub report text.');
}

/** @param list<string> $secrets */
function scrubValue(mixed $value, array $secrets): mixed
{
    if (is_string($value)) {
        return scrubText($value, $secrets);
    }

    if (! is_array($value)) {
        return $value;
    }

    $sensitiveEntry = isset($value['key'])
        && is_string($value['key'])
        && isSensitiveName($value['key']);

    foreach ($value as $key => $child) {
        if ((is_string($key) && isSensitiveName($key)) || ($key === 'value' && $sensitiveEntry)) {
            $value[$key] = REDACTED;
        } else {
            $value[$key] = scrubValue($child, $secrets);
        }
    }

    return $value;
}

function assertStructurallyRedacted(mixed $value): void
{
    if (! is_array($value)) {
        return;
    }

    $sensitiveEntry = isset($value['key'])
        && is_string($value['key'])
        && isSensitiveName($value['key']);

    foreach ($value as $key => $child) {
        if ((is_string($key) && isSensitiveName($key)) || ($key === 'value' && $sensitiveEntry)) {
            if ($child !== REDACTED) {
                throw new RuntimeException('A sensitive JSON field was not structurally redacted.');
            }

            continue;
        }

        assertStructurallyRedacted($child);
    }
}

/** @param list<string> $secrets */
function assertNoLeaks(string $text, array $secrets): void
{
    foreach ($secrets as $secret) {
        if (str_contains($text, $secret)) {
            throw new RuntimeException('A configured credential remains in a report.');
        }
    }

    foreach ([
        '#\bBearer\s+(?!\[REDACTED\])[A-Za-z0-9._~+/=-]{8,}#i',
        '#(?<![A-Za-z0-9])\d+\|[A-Za-z0-9._~+/=-]{8,}(?![A-Za-z0-9])#',
        '#\bt=[^,\s;&"<>]+,\s*te=[^,\s;&"<>]+#i',
    ] as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            throw new RuntimeException('A token-shaped value or webhook signature remains in a report.');
        }
    }
}

function writeFile(string $path, string $contents): void
{
    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Could not write a sanitized report file.');
    }
}

function markdownCell(string $value): string
{
    $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $value = strtr($value, [
        '\\' => '\\\\',
        '|' => '\\|',
        '`' => '\\`',
        '*' => '\\*',
        '_' => '\\_',
        '{' => '\\{',
        '}' => '\\}',
        '[' => '\\[',
        ']' => '\\]',
        '(' => '\\(',
        ')' => '\\)',
        '#' => '\\#',
        '+' => '\\+',
        '-' => '\\-',
        '.' => '\\.',
        '!' => '\\!',
    ]);

    return str_replace(["\r\n", "\r", "\n"], '<br>', $value);
}

function reportCount(mixed $value): int
{
    return is_numeric($value) ? max(0, (int) $value) : 0;
}

function errorMessage(mixed $error): string
{
    if (is_array($error) && isset($error['message']) && is_string($error['message'])) {
        return $error['message'];
    }

    if (is_string($error) && $error !== '') {
        return $error;
    }

    return 'Request or assertion failed.';
}

/** @return list<array{request: string, check: string, message: string}> */
function failureRows(array $report): array
{
    $run = is_array($report['run'] ?? null) ? $report['run'] : [];
    $rows = [];

    foreach (($run['executions'] ?? []) as $execution) {
        if (! is_array($execution)) {
            continue;
        }

        $item = is_array($execution['item'] ?? null) ? $execution['item'] : [];
        $request = is_string($item['name'] ?? null) ? $item['name'] : 'Request';

        foreach (($execution['assertions'] ?? []) as $assertion) {
            if (! is_array($assertion) || empty($assertion['error'])) {
                continue;
            }

            $check = is_string($assertion['assertion'] ?? null) ? $assertion['assertion'] : 'Assertion';
            $rows[] = [
                'request' => $request,
                'check' => $check,
                'message' => errorMessage($assertion['error']),
            ];
        }

        if (! empty($execution['requestError'])) {
            $rows[] = [
                'request' => $request,
                'check' => 'HTTP request',
                'message' => errorMessage($execution['requestError']),
            ];
        }
    }

    if ($rows === []) {
        foreach (($run['failures'] ?? []) as $failure) {
            if (! is_array($failure)) {
                continue;
            }

            $source = is_array($failure['source'] ?? null) ? $failure['source'] : [];
            $rows[] = [
                'request' => is_string($source['name'] ?? null) ? $source['name'] : 'Newman',
                'check' => 'Run failure',
                'message' => errorMessage($failure['error'] ?? null),
            ];
        }
    }

    return $rows;
}

function createSummary(array $report): string
{
    $run = is_array($report['run'] ?? null) ? $report['run'] : [];
    $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
    $requests = is_array($stats['requests'] ?? null) ? $stats['requests'] : [];
    $assertions = is_array($stats['assertions'] ?? null) ? $stats['assertions'] : [];
    $failures = is_array($run['failures'] ?? null) ? count($run['failures']) : 0;
    $requestFailures = reportCount($requests['failed'] ?? 0);
    $assertionFailures = reportCount($assertions['failed'] ?? 0);
    $failed = max($failures, $requestFailures, $assertionFailures);
    $outcome = $failed === 0 ? 'PASS' : 'FAIL';
    $collection = is_array($report['collection']['info'] ?? null) ? $report['collection']['info'] : [];
    $collectionName = is_string($collection['name'] ?? null) ? $collection['name'] : 'Newman collection';

    $summary = [
        '# Newman API contract report',
        '',
        '- Collection: **'.markdownCell($collectionName).'**',
        '- Outcome: **'.$outcome.'**',
        '- Requests: '.reportCount($requests['total'] ?? 0).' executed, '.$requestFailures.' failed',
        '- Assertions: '.reportCount($assertions['total'] ?? 0).' executed, '.$assertionFailures.' failed',
        '',
        '## Failures',
        '',
    ];

    $rows = failureRows($report);

    if ($rows === [] && $failed === 0) {
        $summary[] = 'No request or assertion failures.';
    } elseif ($rows !== []) {
        $summary[] = '| Request | Check | Details |';
        $summary[] = '| --- | --- | --- |';

        foreach ($rows as $row) {
            $summary[] = '| '.markdownCell($row['request']).' | '.markdownCell($row['check']).' | '.markdownCell($row['message']).' |';
        }
    } else {
        $summary[] = 'Newman reported a failure without a detailed execution row; inspect the sanitized JSON artifact.';
    }

    $summary[] = '';
    $summary[] = 'Credentials, bearer tokens, and webhook signatures were scrubbed and checked before publication.';

    return implode("\n", $summary)."\n";
}

/** @param list<string> $secrets */
function checkDirectory(string $directory, array $secrets): void
{
    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*');

    if ($files === false || $files === []) {
        throw new RuntimeException('No report files were produced.');
    }

    foreach ($files as $path) {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('A report output is not a regular file.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Could not inspect a report file.');
        }

        assertNoLeaks($contents, $secrets);

        if (basename($path) === 'newman.json') {
            try {
                $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new RuntimeException('The sanitized Newman JSON report is invalid.');
            }

            assertStructurallyRedacted($report);
        }
    }
}

/** @param list<string> $secrets */
function sanitizeReports(string $jsonPath, string $summaryPath, string $directory, array $secrets): void
{
    $rawJson = file_get_contents($jsonPath);

    if ($rawJson === false) {
        throw new RuntimeException('Newman did not produce a readable JSON report.');
    }

    try {
        $report = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Newman produced invalid JSON; refusing to publish reports.');
    }

    if (! is_array($report)) {
        throw new RuntimeException('Newman produced an unexpected JSON report shape.');
    }

    $report = scrubValue($report, $secrets);
    $json = json_encode(
        $report,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
    );
    $json = scrubText($json, $secrets);
    writeFile($jsonPath, $json."\n");

    writeFile($summaryPath, scrubText(createSummary($report), $secrets));

    $files = glob(rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*');

    if ($files === false) {
        throw new RuntimeException('Could not enumerate report files.');
    }

    foreach ($files as $path) {
        if (is_link($path) || ! is_file($path)) {
            throw new RuntimeException('A report output is not a regular file.');
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read a report file for sanitization.');
        }

        writeFile($path, scrubText($contents, $secrets));
    }

    checkDirectory($directory, $secrets);
}

try {
    $secrets = configuredSecrets();

    if (($argv[1] ?? null) === '--check') {
        if ($argc !== 3 || ! is_dir($argv[2])) {
            throw new RuntimeException('Usage: newman-report-summary.php --check <report-directory>');
        }

        checkDirectory($argv[2], $secrets);
        fwrite(STDOUT, "artifact leak check passed\n");
        exit(0);
    }

    if ($argc !== 4 || ! is_dir($argv[3])) {
        throw new RuntimeException('Usage: newman-report-summary.php <newman.json> <summary.md> <report-directory>');
    }

    sanitizeReports($argv[1], $argv[2], $argv[3], $secrets);
    fwrite(STDOUT, "artifact leak check passed\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Newman report sanitization failed: '.$error->getMessage()."\n");
    exit(1);
}
