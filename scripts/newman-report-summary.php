<?php

declare(strict_types=1);

const REDACTED = '[REDACTED]';
const SUMMARY_BYTE_LIMIT = 256 * 1024;
const SUMMARY_MAX_REQUEST_ROWS = 100;
const SUMMARY_MAX_FAILURE_ROWS = 20;
const SUMMARY_SLOWEST_REQUESTS = 5;

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
function scrubPlainText(string $text, array $secrets): string
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

function decodeBase64Candidate(string $candidate): ?string
{
    $remainder = strlen($candidate) % 4;

    if ($remainder === 1) {
        return null;
    }

    if ($remainder !== 0) {
        $candidate .= str_repeat('=', 4 - $remainder);
    }

    $decoded = base64_decode($candidate, true);

    if ($decoded === false || $decoded === '' || preg_match('//u', $decoded) !== 1) {
        return null;
    }

    return $decoded;
}

/** @param list<string> $secrets */
function scrubText(string $text, array $secrets): string
{
    $text = scrubPlainText($text, $secrets);

    return preg_replace_callback(
        '#(?<![A-Za-z0-9+/])[A-Za-z0-9+/]{16,}={0,2}(?![A-Za-z0-9+/=])#',
        static function (array $match) use ($secrets): string {
            $decoded = decodeBase64Candidate($match[0]);

            if ($decoded === null) {
                return $match[0];
            }

            $scrubbed = scrubPlainText($decoded, $secrets);

            return $scrubbed === $decoded ? $match[0] : base64_encode($scrubbed);
        },
        $text,
    ) ?? throw new RuntimeException('Could not scrub base64-encoded report text.');
}

/** @return list<int>|null */
function bufferBytes(mixed $value): ?array
{
    if (! is_array($value)
        || ($value['type'] ?? null) !== 'Buffer'
        || ! is_array($value['data'] ?? null)) {
        return null;
    }

    $bytes = [];

    foreach ($value['data'] as $byte) {
        if (! is_int($byte) || $byte < 0 || $byte > 255) {
            return null;
        }

        $bytes[] = $byte;
    }

    return $bytes;
}

/** @param list<int> $bytes */
function bufferToText(array $bytes): string
{
    return pack('C*', ...$bytes);
}

/** @param list<int> $bytes */
function textToBuffer(string $text): array
{
    return array_values(unpack('C*', $text) ?: []);
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

    $bytes = bufferBytes($value);

    if ($bytes !== null) {
        return [
            'type' => 'Buffer',
            'data' => textToBuffer(scrubText(bufferToText($bytes), $secrets)),
        ];
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
function assertValueHasNoLeaks(mixed $value, array $secrets): void
{
    if (is_string($value)) {
        assertNoLeaks($value, $secrets);

        return;
    }

    if (! is_array($value)) {
        return;
    }

    $bytes = bufferBytes($value);

    if ($bytes !== null) {
        assertNoLeaks(bufferToText($bytes), $secrets);

        return;
    }

    foreach ($value as $child) {
        assertValueHasNoLeaks($child, $secrets);
    }
}

/** @param list<string> $secrets */
function assertPlainNoLeaks(string $text, array $secrets): void
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

/** @param list<string> $secrets */
function assertNoLeaks(string $text, array $secrets): void
{
    assertPlainNoLeaks($text, $secrets);

    preg_match_all(
        '#(?<![A-Za-z0-9+/])[A-Za-z0-9+/]{16,}={0,2}(?![A-Za-z0-9+/=])#',
        $text,
        $matches,
    );

    foreach ($matches[0] ?? [] as $candidate) {
        $decoded = decodeBase64Candidate($candidate);

        if ($decoded !== null) {
            assertPlainNoLeaks($decoded, $secrets);
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
    $value = htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

function limitedText(string $value, int $maximumCharacters): string
{
    if (preg_match('/\\A.{0,'.$maximumCharacters.'}/us', $value, $matches) !== 1) {
        return substr($value, 0, $maximumCharacters);
    }

    $prefix = $matches[0];

    return strlen($prefix) < strlen($value) ? $prefix.'…' : $value;
}

function limitedMarkdownCell(string $value, int $maximumCharacters = 120): string
{
    return markdownCell(limitedText($value, $maximumCharacters));
}

function safeMarkdownUrl(mixed $value): ?string
{
    if (! is_string($value)
        || $value === ''
        || preg_match('/[\\x00-\\x20\\x7f]/', $value) === 1
        || strpbrk($value, "<>[](){}\\\"'\\\\") !== false) {
        return null;
    }

    $parts = parse_url($value);

    if (! is_array($parts)
        || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
        || ! is_string($parts['host'] ?? null)
        || isset($parts['user'])
        || isset($parts['pass'])) {
        return null;
    }

    return $value;
}

function normalizedArtifactDigest(string $digest): ?string
{
    if (preg_match('/\\A(?:sha256:)?([a-f0-9]{64})\\z/i', $digest, $matches) !== 1) {
        return null;
    }

    return 'sha256:'.strtolower($matches[1]);
}

function boundedSummary(string $summary): string
{
    if (strlen($summary) <= SUMMARY_BYTE_LIMIT) {
        return $summary;
    }

    $notice = "\n\n> Summary content was truncated to stay within the 256 KiB safety limit; inspect the sanitized Newman artifact for complete details.\n";
    $prefix = substr($summary, 0, SUMMARY_BYTE_LIMIT - strlen($notice));
    $lastNewline = strrpos($prefix, "\n");

    if ($lastNewline !== false) {
        $prefix = substr($prefix, 0, $lastNewline + 1);
    }

    while ($prefix !== '' && preg_match('//u', $prefix) !== 1) {
        $prefix = substr($prefix, 0, -1);
    }

    return rtrim($prefix).$notice;
}

function milliseconds(mixed $value): string
{
    return is_numeric($value) ? max(0, (int) round((float) $value)).' ms' : '—';
}

function executionName(array $execution): string
{
    $item = is_array($execution['item'] ?? null) ? $execution['item'] : [];

    return is_string($item['name'] ?? null) ? $item['name'] : 'Request';
}

function executionMethod(array $execution): string
{
    $request = is_array($execution['request'] ?? null) ? $execution['request'] : [];
    $item = is_array($execution['item'] ?? null) ? $execution['item'] : [];
    $itemRequest = is_array($item['request'] ?? null) ? $item['request'] : [];
    $method = $request['method'] ?? $itemRequest['method'] ?? null;

    return is_string($method) && $method !== '' ? strtoupper($method) : '—';
}

function executionStatus(array $execution): string
{
    $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
    $code = $response['code'] ?? null;

    if (is_numeric($code)) {
        return (string) (int) $code;
    }

    return is_string($response['status'] ?? null) && $response['status'] !== ''
        ? $response['status']
        : '—';
}

function executionResponseTime(array $execution): ?int
{
    $response = is_array($execution['response'] ?? null) ? $execution['response'] : [];
    $value = $response['responseTime'] ?? null;

    return is_numeric($value) ? max(0, (int) round((float) $value)) : null;
}

function executionAssertionResult(array $execution): string
{
    $assertions = is_array($execution['assertions'] ?? null) ? $execution['assertions'] : [];
    $total = 0;
    $passed = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($assertions as $assertion) {
        if (! is_array($assertion)) {
            continue;
        }

        $total++;

        if (! empty($assertion['skipped'])) {
            $skipped++;
        } elseif (! empty($assertion['error'])) {
            $failed++;
        } else {
            $passed++;
        }
    }

    $result = $passed.'/'.$total.' passed';

    if ($failed > 0) {
        $result .= ', '.$failed.' failed';
    }

    if ($skipped > 0) {
        $result .= ', '.$skipped.' skipped';
    }

    if (! empty($execution['requestError'])) {
        $result .= '; request error';
    }

    return $result;
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
    $executions = is_array($run['executions'] ?? null) ? $run['executions'] : [];
    $failures = is_array($run['failures'] ?? null) ? $run['failures'] : [];
    $requestFailureMessages = [];
    $rows = [];

    foreach ($failures as $failure) {
        if (! is_array($failure) || ($failure['at'] ?? null) !== 'request') {
            continue;
        }

        $source = is_array($failure['source'] ?? null) ? $failure['source'] : [];
        $error = is_array($failure['error'] ?? null) ? $failure['error'] : [];

        if (is_string($source['id'] ?? null) && is_string($error['message'] ?? null)) {
            $requestFailureMessages[$source['id']] = $error['message'];
        }
    }

    foreach ($executions as $execution) {
        if (! is_array($execution)) {
            continue;
        }

        $request = executionName($execution);
        $assertions = is_array($execution['assertions'] ?? null) ? $execution['assertions'] : [];

        foreach ($assertions as $assertion) {
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
            $item = is_array($execution['item'] ?? null) ? $execution['item'] : [];
            $id = is_string($item['id'] ?? null) ? $item['id'] : '';
            $rows[] = [
                'request' => $request,
                'check' => 'HTTP request',
                'message' => $requestFailureMessages[$id] ?? errorMessage($execution['requestError']),
            ];
        }
    }

    if ($rows === []) {
        foreach ($failures as $failure) {
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

    if ($rows === [] && ! empty($run['error'])) {
        $rows[] = [
            'request' => 'Newman',
            'check' => 'Run error',
            'message' => errorMessage($run['error']),
        ];
    }

    return $rows;
}

function createSummary(array $report, string $runUrl = '', string $artifactUrl = '', string $artifactDigest = ''): string
{
    $run = is_array($report['run'] ?? null) ? $report['run'] : [];
    $stats = is_array($run['stats'] ?? null) ? $run['stats'] : [];
    $requests = is_array($stats['requests'] ?? null) ? $stats['requests'] : [];
    $assertions = is_array($stats['assertions'] ?? null) ? $stats['assertions'] : [];
    $runFailures = max(
        is_array($run['failures'] ?? null) ? count($run['failures']) : 0,
        empty($run['error']) ? 0 : 1,
    );
    $requestFailures = reportCount($requests['failed'] ?? 0);
    $assertionFailures = reportCount($assertions['failed'] ?? 0);
    $failed = max($runFailures, $requestFailures, $assertionFailures);
    $outcome = $failed === 0 ? 'PASS' : 'FAIL';
    $collection = is_array($report['collection']['info'] ?? null) ? $report['collection']['info'] : [];
    $collectionName = is_string($collection['name'] ?? null) ? $collection['name'] : 'Newman collection';
    $executions = is_array($run['executions'] ?? null) ? $run['executions'] : [];
    $timings = is_array($run['timings'] ?? null) ? $run['timings'] : [];
    $transfers = is_array($run['transfers'] ?? null) ? $run['transfers'] : [];
    $duration = '—';

    if (is_numeric($timings['started'] ?? null)
        && is_numeric($timings['completed'] ?? null)
        && (float) $timings['completed'] >= (float) $timings['started']) {
        $duration = number_format(((float) $timings['completed'] - (float) $timings['started']) / 1000, 1).' s';
    }

    $responseTime = is_numeric($timings['responseAverage'] ?? null)
        ? 'avg '.milliseconds($timings['responseAverage']).' · min '.milliseconds($timings['responseMin'] ?? null).' · max '.milliseconds($timings['responseMax'] ?? null)
        : '—';
    $responseBytes = is_numeric($transfers['responseTotal'] ?? null)
        ? ((int) $transfers['responseTotal'] < 1024
            ? max(0, (int) $transfers['responseTotal']).' B'
            : number_format(max(0, (float) $transfers['responseTotal']) / 1024, 1).' KB')
        : '—';

    $summary = [
        '# Newman API contract report',
        '',
        '- Collection: **'.limitedMarkdownCell($collectionName).'**',
        '- Outcome: **'.$outcome.'**',
        '',
        '## Run metrics',
        '',
        '| Metric | Result |',
        '| --- | --- |',
        '| Requests | '.reportCount($requests['total'] ?? 0).' executed, '.$requestFailures.' failed |',
        '| Assertions | '.reportCount($assertions['total'] ?? 0).' executed, '.$assertionFailures.' failed |',
        '| Duration | '.$duration.' |',
        '| Response time | '.$responseTime.' |',
        '| Response bytes | '.$responseBytes.' |',
        '',
    ];

    $runLink = safeMarkdownUrl($runUrl);
    $artifactLink = safeMarkdownUrl($artifactUrl);
    $digest = normalizedArtifactDigest($artifactDigest);

    if ($runLink !== null || $artifactLink !== null || $digest !== null) {
        $summary[] = '## Run evidence';
        $summary[] = '';

        if ($runLink !== null) {
            $summary[] = '[View workflow run]('.$runLink.')';
        }

        if ($artifactLink !== null) {
            $summary[] = '[Download Newman reports artifact]('.$artifactLink.')';
        }

        if ($digest !== null) {
            $summary[] = 'Artifact digest: `'.$digest.'`';
        }

        $summary[] = '';
    }

    $summary[] = '## Per-request evidence';
    $summary[] = '';

    if ($executions === []) {
        $summary[] = 'No request execution details were recorded.';
    } else {
        $summary[] = '| # | Request | Method | HTTP | Response time | Assertions |';
        $summary[] = '| --- | --- | --- | --- | --- | --- |';

        foreach (array_slice($executions, 0, SUMMARY_MAX_REQUEST_ROWS) as $index => $execution) {
            if (! is_array($execution)) {
                continue;
            }

            $responseTime = executionResponseTime($execution);
            $summary[] = '| '.($index + 1)
                .' | '.limitedMarkdownCell(executionName($execution))
                .' | '.limitedMarkdownCell(executionMethod($execution), 24)
                .' | '.limitedMarkdownCell(executionStatus($execution), 32)
                .' | '.($responseTime === null ? '—' : $responseTime.' ms')
                .' | '.limitedMarkdownCell(executionAssertionResult($execution), 80).' |';
        }

        if (count($executions) > SUMMARY_MAX_REQUEST_ROWS) {
            $summary[] = '';
            $summary[] = '_Showing the first '.SUMMARY_MAX_REQUEST_ROWS.' of '.count($executions).' request executions._';
        }
    }

    $ranked = [];

    foreach ($executions as $index => $execution) {
        if (! is_array($execution) || ($time = executionResponseTime($execution)) === null) {
            continue;
        }

        $ranked[] = ['execution' => $execution, 'index' => $index, 'time' => $time];
    }

    usort($ranked, static fn (array $left, array $right): int => ($right['time'] <=> $left['time']) ?: ($left['index'] <=> $right['index']));

    $summary[] = '';
    $summary[] = '## Slowest requests';
    $summary[] = '';

    if ($ranked === []) {
        $summary[] = 'No response timing data was recorded.';
    } else {
        $summary[] = '| Rank | Request | Method | HTTP | Response time |';
        $summary[] = '| --- | --- | --- | --- | --- |';

        foreach (array_slice($ranked, 0, SUMMARY_SLOWEST_REQUESTS) as $rank => $row) {
            $execution = $row['execution'];
            $summary[] = '| '.($rank + 1)
                .' | '.limitedMarkdownCell(executionName($execution))
                .' | '.limitedMarkdownCell(executionMethod($execution), 24)
                .' | '.limitedMarkdownCell(executionStatus($execution), 32)
                .' | '.$row['time'].' ms |';
        }
    }

    $rows = failureRows($report);
    $summary[] = '';
    $summary[] = '## Failure details';
    $summary[] = '';

    if ($rows === [] && $failed === 0) {
        $summary[] = 'No request or assertion failures.';
    } elseif ($rows !== []) {
        $summary[] = '<details>';
        $summary[] = '<summary>Show '.count($rows).' failure detail(s)</summary>';
        $summary[] = '';
        $summary[] = '| Request | Check | Details |';
        $summary[] = '| --- | --- | --- |';

        foreach (array_slice($rows, 0, SUMMARY_MAX_FAILURE_ROWS) as $row) {
            $summary[] = '| '.limitedMarkdownCell($row['request'])
                .' | '.limitedMarkdownCell($row['check'])
                .' | '.limitedMarkdownCell($row['message'], 300).' |';
        }

        if (count($rows) > SUMMARY_MAX_FAILURE_ROWS) {
            $summary[] = '| … | … | '.(count($rows) - SUMMARY_MAX_FAILURE_ROWS).' additional failure details are in the sanitized Newman artifact. |';
        }

        $summary[] = '</details>';
    } else {
        $summary[] = 'Newman reported a failure without a detailed execution row; inspect the sanitized JSON artifact.';
    }

    $summary[] = '';
    $summary[] = 'Credentials, authorization tokens, and webhook signatures were scrubbed and checked before publication.';

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
            assertValueHasNoLeaks($report, $secrets);
        }
    }
}

/** @param list<string> $secrets */
function refreshSummaryWithLinks(string $directory, string $runUrl, string $artifactUrl, string $artifactDigest, array $secrets): void
{
    checkDirectory($directory, $secrets);
    $jsonPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'newman.json';
    $summaryPath = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'summary.md';
    $contents = file_get_contents($jsonPath);

    if ($contents === false) {
        throw new RuntimeException('The sanitized Newman JSON report is not readable.');
    }

    try {
        $report = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('The sanitized Newman JSON report is invalid.');
    }

    if (! is_array($report)) {
        throw new RuntimeException('The sanitized Newman JSON report has an unexpected shape.');
    }

    $summary = scrubText(createSummary($report, $runUrl, $artifactUrl, $artifactDigest), $secrets);
    writeFile($summaryPath, boundedSummary($summary));
    checkDirectory($directory, $secrets);
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

    writeFile($summaryPath, boundedSummary(scrubText(createSummary($report), $secrets)));

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

        $contents = scrubText($contents, $secrets);

        if (basename($path) === 'summary.md') {
            $contents = boundedSummary($contents);
        }

        writeFile($path, $contents);
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

    if (($argv[1] ?? null) === '--with-links') {
        if ($argc !== 6 || ! is_dir($argv[2])) {
            throw new RuntimeException('Usage: newman-report-summary.php --with-links <report-directory> <run-url> <artifact-url> <artifact-digest>');
        }

        refreshSummaryWithLinks($argv[2], $argv[3], $argv[4], $argv[5], $secrets);
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
