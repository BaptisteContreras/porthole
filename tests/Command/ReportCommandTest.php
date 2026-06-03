<?php

namespace Porthole\Tests\Command;

use PHPUnit\Framework\TestCase;
use Porthole\Command\ReportCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ReportCommandTest extends TestCase
{
    private string $outputFile;
    private string|false $originalToken;

    protected function setUp(): void
    {
        $this->outputFile = (string) tempnam(sys_get_temp_dir(), 'porthole_report_');
        $this->originalToken = getenv('HARBOR_TOKEN');
        putenv('HARBOR_TOKEN'); // unset so tests are deterministic
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputFile)) {
            unlink($this->outputFile);
        }
        if (false !== $this->originalToken) {
            putenv(sprintf('HARBOR_TOKEN=%s', $this->originalToken));
        }
    }

    public function testRunsImagesReportSortedByPullsDesc(): void
    {
        $body = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T10:00:00.000Z'],
            ['username' => 'bob',   'resource' => 'library/redis:7',      'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-03T11:00:00.000Z'],
        ]);

        $command = new ReportCommand(new MockHttpClient([new MockResponse($body)]), interactive: false);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--harbor-url' => 'https://registry.example.com',
            '--harbor-token' => 'test-token',
            '--output' => $this->outputFile,
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $lines = file($this->outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertSame('Image;Tag;"Number of pulls"', $lines[0]);
        $this->assertSame('library/nginx;latest;2', $lines[1]); // highest pulls first
        $this->assertSame('library/redis;7;1', $lines[2]);
    }

    public function testRunsUsersReportSortedByUsernameAscThenPullsDesc(): void
    {
        $body = json_encode([
            ['username' => 'charlie', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
            ['username' => 'alice',   'resource' => 'library/redis:7',      'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T10:00:00.000Z'],
            ['username' => 'alice',   'resource' => 'library/redis:7',      'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-03T10:00:00.000Z'],
        ]);

        $command = new ReportCommand(new MockHttpClient([new MockResponse($body)]), interactive: false);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--harbor-url' => 'https://registry.example.com',
            '--harbor-token' => 'test-token',
            '--output' => $this->outputFile,
            '--mode' => 'users',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $lines = file($this->outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertSame('User;Image;Tag;"Number of pulls"', $lines[0]);
        $this->assertSame('alice;library/redis;7;2', $lines[1]);   // alice < charlie
        $this->assertSame('charlie;library/nginx;latest;1', $lines[2]);
    }

    public function testFailsWhenHarborTokenIsMissing(): void
    {
        $command = new ReportCommand(new MockHttpClient(), interactive: false);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--harbor-url' => 'https://registry.example.com',
            '--output' => $this->outputFile,
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('HARBOR_TOKEN', $tester->getDisplay());
    }

    public function testFailsOnInvalidMode(): void
    {
        $command = new ReportCommand(new MockHttpClient(), interactive: false);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--harbor-url' => 'https://registry.example.com',
            '--harbor-token' => 'test-token',
            '--output' => $this->outputFile,
            '--mode' => 'invalid',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('invalid', $tester->getDisplay());
    }

    public function testFailsOnInvalidFromDate(): void
    {
        $command = new ReportCommand(new MockHttpClient(), interactive: false);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            '--harbor-url' => 'https://registry.example.com',
            '--harbor-token' => 'test-token',
            '--output' => $this->outputFile,
            '--from' => 'not-a-date',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('Invalid date format', $tester->getDisplay());
    }
}
