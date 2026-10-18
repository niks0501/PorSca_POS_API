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

    private function writeBufferReport(string $token, string $password): void
    {
        $body = json_encode([
            'token' => $token,
            'password' => $password,
        ], JSON_THROW_ON_ERROR);

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

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
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
