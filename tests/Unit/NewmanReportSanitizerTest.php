<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;

class NewmanReportSanitizerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/porsca-newman-'.bin2hex(random_bytes(8));

        if (! mkdir($this->directory, 0700, true)) {
            self::fail('Could not create the sanitizer test directory.');
        }
    }

    protected function tearDown(): void
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($this->directory);

        parent::tearDown();
    }

    public function test_buffer_encoded_tokens_and_credentials_are_scrubbed_before_publication(): void
    {
        $token = '2|'.str_repeat('Qa1B2c3D4e5F6g7H', 4);
        $password = 'buffer-only-secret-123';

        $this->writeBufferReport($token, $password);

        $process = $this->runScript([
            $this->directory.'/newman.json',
            $this->directory.'/summary.md',
            $this->directory,
        ], ['QA_CASHIER_PASSWORD' => $password]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        $stream = $this->decodedStream($this->directory.'/newman.json');

        $this->assertStringNotContainsString($token, $stream);
        $this->assertStringNotContainsString($password, $stream);
        $this->assertStringContainsString('[REDACTED]', $stream);

        $check = $this->runScript(['--check', $this->directory]);

        $this->assertSame(0, $check->getExitCode(), $check->getErrorOutput());
    }

    public function test_leak_check_fails_closed_on_a_buffer_encoded_token(): void
    {
        $token = '2|'.str_repeat('Zz9Yy8Xx7Ww6Vv5', 4);

        $this->writeBufferReport($token, 'unused-password');

        $check = $this->runScript(['--check', $this->directory]);

        $this->assertNotSame(0, $check->getExitCode());
        $this->assertStringContainsString('token-shaped value', $check->getErrorOutput());
    }

    public function test_base64_encoded_buffer_tokens_and_credentials_are_scrubbed_before_publication(): void
    {
        $token = '2|'.str_repeat('Rr4Tt5Yy6Uu7Ii8', 4);
        $password = 'base64-only-secret-456';

        $this->writeBufferReport($token, $password, base64EncodeBody: true);

        $process = $this->runScript([
            $this->directory.'/newman.json',
            $this->directory.'/summary.md',
            $this->directory,
        ], ['QA_CASHIER_PASSWORD' => $password]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        $body = $this->decodedBase64Stream($this->directory.'/newman.json');

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString($password, $body);
        $this->assertStringContainsString('[REDACTED]', $body);

        $check = $this->runScript(['--check', $this->directory]);

        $this->assertSame(0, $check->getExitCode(), $check->getErrorOutput());
    }

    public function test_leak_check_fails_closed_on_a_base64_encoded_buffer_token(): void
    {
        $token = '2|'.str_repeat('Mm5Nn4Bb3Vv2Cc1', 4);

        $this->writeBufferReport($token, 'unused-password', base64EncodeBody: true);

        $check = $this->runScript(['--check', $this->directory]);

        $this->assertNotSame(0, $check->getExitCode());
        $this->assertStringContainsString('token-shaped value', $check->getErrorOutput());
    }

    public function test_with_links_renders_bare_and_sha256_prefixed_artifact_digests(): void
    {
        $digest = hash('sha256', 'newman-reports');

        file_put_contents(
            $this->directory.'/newman.json',
            json_encode([
                'collection' => ['info' => ['name' => 'Digest rendering']],
                'run' => [
                    'stats' => [
                        'requests' => ['total' => 1, 'failed' => 0],
                        'assertions' => ['total' => 1, 'failed' => 0],
                    ],
                    'failures' => [],
                    'executions' => [],
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        foreach ([$digest, 'sha256:'.$digest] as $artifactDigest) {
            $process = $this->runScript([
                '--with-links',
                $this->directory,
                'https://github.com/alfredc-12/PorSca_POS/actions/runs/1',
                'https://github.com/alfredc-12/PorSca_POS/actions/runs/1/artifacts/2',
                $artifactDigest,
            ]);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

            $summary = (string) file_get_contents($this->directory.'/summary.md');

            $this->assertStringContainsString('Artifact digest: `sha256:'.$digest.'`', $summary);
        }
    }

    public function test_apostrophes_render_intact_in_request_and_failure_tables(): void
    {
        $requestName = "Health check's catalog probe";
        $assertionName = "reader's check";
        $message = "Cannot read properties of undefined (reading 'name')";

        $this->writeReport([
            'collection' => ['info' => ['name' => $requestName]],
            'run' => [
                'stats' => [
                    'requests' => ['total' => 1, 'failed' => 0],
                    'assertions' => ['total' => 1, 'failed' => 1],
                ],
                'failures' => [
                    [
                        'at' => 'assertion:0 in test-script',
                        'source' => ['id' => 'request-1', 'name' => $requestName],
                        'error' => ['name' => 'TypeError', 'test' => $assertionName, 'message' => $message],
                    ],
                ],
                'executions' => [
                    [
                        'item' => ['id' => 'request-1', 'name' => $requestName],
                        'request' => ['method' => 'GET'],
                        'response' => ['code' => 200, 'responseTime' => 153],
                        'assertions' => [
                            [
                                'assertion' => $assertionName,
                                'error' => ['name' => 'TypeError', 'message' => $message],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $process = $this->runScript([
            $this->directory.'/newman.json',
            $this->directory.'/summary.md',
            $this->directory,
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        $summary = (string) file_get_contents($this->directory.'/summary.md');

        $this->assertStringContainsString('| 1 | '.$requestName.' | GET | 200 | 153 ms | 0/1 passed, 1 failed |', $summary);
        $this->assertStringContainsString("## Slowest requests\n\n| Rank | Request | Method | HTTP | Response time |\n| --- | --- | --- | --- | --- |\n| 1 | ".$requestName.' | GET | 200 | 153 ms |', $summary);
        $this->assertStringContainsString('| '.$requestName.' | '.$assertionName." | Cannot read properties of undefined \\(reading 'name'\\) |", $summary);
        $this->assertStringNotContainsString('&#039;', $summary);
        $this->assertStringNotContainsString('&\\#039;', $summary);
    }

    public function test_transport_failure_details_use_the_newman_failure_message(): void
    {
        $this->writeReport([
            'collection' => ['info' => ['name' => 'Transport failure']],
            'run' => [
                'stats' => [
                    'requests' => ['total' => 1, 'failed' => 1],
                    'assertions' => ['total' => 0, 'failed' => 0],
                ],
                'failures' => [
                    [
                        'at' => 'request',
                        'source' => ['id' => 'request-1', 'name' => 'Unreachable endpoint'],
                        'error' => ['name' => 'Error', 'message' => 'connect ECONNREFUSED 127.0.0.1:59999'],
                    ],
                ],
                'executions' => [
                    [
                        'item' => ['id' => 'request-1', 'name' => 'Unreachable endpoint'],
                        'request' => ['method' => 'GET'],
                        'assertions' => [],
                        'requestError' => [
                            'errno' => -111,
                            'code' => 'ECONNREFUSED',
                            'syscall' => 'connect',
                            'address' => '127.0.0.1',
                            'port' => 59999,
                        ],
                    ],
                ],
            ],
        ]);

        $process = $this->runScript([
            $this->directory.'/newman.json',
            $this->directory.'/summary.md',
            $this->directory,
        ]);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());

        $summary = (string) file_get_contents($this->directory.'/summary.md');

        $this->assertStringContainsString('| Unreachable endpoint | HTTP request | connect ECONNREFUSED 127\\.0\\.0\\.1:59999 |', $summary);
        $this->assertStringNotContainsString('Request or assertion failed', $summary);
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        file_put_contents(
            $this->directory.'/newman.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function writeBufferReport(string $token, string $password, bool $base64EncodeBody = false): void
    {
        $body = json_encode([
            'token' => $token,
            'password' => $password,
        ], JSON_THROW_ON_ERROR);

        if ($base64EncodeBody) {
            $body = base64_encode($body);
        }

        $report = [
            'collection' => ['info' => ['name' => 'Sanitizer regression']],
            'run' => [
                'stats' => [
                    'requests' => ['total' => 1, 'failed' => 0],
                    'assertions' => ['total' => 1, 'failed' => 0],
                ],
                'failures' => [],
                'executions' => [
                    [
                        'item' => ['name' => 'Login'],
                        'assertions' => [],
                        'response' => [
                            'stream' => [
                                'type' => 'Buffer',
                                'data' => array_values(unpack('C*', $body)),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents(
            $this->directory.'/newman.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    private function decodedStream(string $path): string
    {
        $report = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $bytes = $report['run']['executions'][0]['response']['stream']['data'];

        return pack('C*', ...$bytes);
    }

    private function decodedBase64Stream(string $path): string
    {
        $decoded = base64_decode($this->decodedStream($path), true);

        $this->assertNotFalse($decoded, 'The published response body was not valid base64.');

        return $decoded;
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function runScript(array $arguments, array $environment = []): Process
    {
        $root = dirname(__DIR__, 2);

        $process = new Process(
            [PHP_BINARY, $root.'/scripts/newman-report-summary.php', ...$arguments],
            $root,
            $environment,
        );

        $process->run();

        return $process;
    }
}
