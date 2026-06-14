<?php

namespace Porthole\Tests\Command;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Porthole\Command\GenerateReportWorkerCommand;
use Porthole\Event\AuditLogPageFetchedEvent;
use Porthole\Event\AuditLogsFetchedEvent;
use Porthole\Event\CsvWrittenEvent;
use Porthole\Event\ReportBuiltEvent;
use Porthole\UseCase\GenerateReportCommand;
use Porthole\UseCase\GenerateReportHandlerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class GenerateReportWorkerCommandTest extends TestCase
{
    /** @var resource */
    private mixed $inputStream;

    /** @var resource */
    private mixed $outputStream;

    /** @var GenerateReportHandlerInterface&MockObject */
    private GenerateReportHandlerInterface $mockHandler;

    private GenerateReportWorkerCommand $command;

    protected function setUp(): void
    {
        $this->inputStream = fopen('php://temp', 'r+');
        $this->outputStream = fopen('php://temp', 'r+');
        $this->mockHandler = $this->createMock(GenerateReportHandlerInterface::class);
        $this->command = new GenerateReportWorkerCommand(
            $this->mockHandler,
            $this->inputStream,
            $this->outputStream,
        );
    }

    protected function tearDown(): void
    {
        fclose($this->inputStream);
        fclose($this->outputStream);
    }

    private function writePayload(array $payload): void
    {
        fwrite($this->inputStream, json_encode($payload) ?: '');
        rewind($this->inputStream);
    }

    /** @return list<array<string, mixed>> */
    private function readLines(): array
    {
        rewind($this->outputStream);
        $raw = stream_get_contents($this->outputStream);

        return array_values(array_map(
            static fn (string $l) => json_decode($l, true),
            array_filter(explode("\n", (string) $raw)),
        ));
    }

    private function defaultPayload(): array
    {
        return [
            'harborUrl' => 'https://registry.example.com',
            'token' => 'secret',
            'username' => null,
            'mode' => 'images',
            'from' => null,
            'to' => null,
            'outputPath' => './report.csv',
            'verifySsl' => true,
        ];
    }

    public function testWritesJsonLineForEachDomainEventThenDone(): void
    {
        $this->mockHandler
            ->method('handle')
            ->willReturnCallback(
                function (GenerateReportCommand $cmd, EventDispatcherInterface $dispatcher): void {
                    $dispatcher->dispatch(new AuditLogPageFetchedEvent(1, 0));
                    $dispatcher->dispatch(new AuditLogPageFetchedEvent(2, 100));
                    $dispatcher->dispatch(new AuditLogsFetchedEvent(100));
                    $dispatcher->dispatch(new ReportBuiltEvent(3));
                    $dispatcher->dispatch(new CsvWrittenEvent('./report.csv', 3));
                }
            );

        $this->writePayload($this->defaultPayload());

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());

        $lines = $this->readLines();

        self::assertSame(['type' => 'page_fetched', 'page' => 1, 'total' => 0], $lines[0]);
        self::assertSame(['type' => 'page_fetched', 'page' => 2, 'total' => 100], $lines[1]);
        self::assertSame(['type' => 'logs_fetched', 'total' => 100], $lines[2]);
        self::assertSame(['type' => 'report_built'], $lines[3]);
        self::assertSame(['type' => 'csv_written', 'path' => './report.csv', 'rows' => 3], $lines[4]);
        self::assertSame(['type' => 'done'], $lines[5]);
    }

    public function testWritesErrorLineAndReturnsFailureOnHandlerException(): void
    {
        $this->mockHandler
            ->method('handle')
            ->willThrowException(new \RuntimeException('connection refused'));

        $this->writePayload($this->defaultPayload());

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());

        $lines = $this->readLines();
        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['type']);
        self::assertSame('connection refused', $lines[0]['message']);
    }

    public function testWritesErrorLineOnInvalidStdinJson(): void
    {
        fwrite($this->inputStream, 'not-valid-json');
        rewind($this->inputStream);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());

        $lines = $this->readLines();
        self::assertCount(1, $lines);
        self::assertSame('error', $lines[0]['type']);
        self::assertSame('Invalid task payload', $lines[0]['message']);
    }

    public function testReconstructsDateRangeFromPayload(): void
    {
        $capturedCommand = null;
        $this->mockHandler
            ->method('handle')
            ->willReturnCallback(
                function (GenerateReportCommand $cmd) use (&$capturedCommand): void {
                    $capturedCommand = $cmd;
                }
            );

        $this->writePayload([
            'harborUrl' => 'https://example.com',
            'token' => 'tok',
            'username' => 'admin',
            'mode' => 'users',
            'from' => '2025-01-01',
            'to' => '2025-12-31',
            'outputPath' => './out.csv',
            'verifySsl' => false,
        ]);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        self::assertInstanceOf(GenerateReportCommand::class, $capturedCommand);
        self::assertSame('https://example.com', $capturedCommand->harborUrl);
        self::assertSame('admin', $capturedCommand->username);
        self::assertSame('users', $capturedCommand->mode);
        self::assertFalse($capturedCommand->verifySsl);
        self::assertSame('2025-01-01', $capturedCommand->from?->format('Y-m-d'));
        self::assertSame('2025-12-31', $capturedCommand->to?->format('Y-m-d'));
    }

    public function testStringFieldsFallBackToEmptyStringWhenMissing(): void
    {
        $capturedCommand = null;
        $this->mockHandler
            ->method('handle')
            ->willReturnCallback(
                function (GenerateReportCommand $cmd) use (&$capturedCommand): void {
                    $capturedCommand = $cmd;
                }
            );

        // Empty object: all string fields absent — stringField() must return '' not throw
        $this->writePayload([]);

        $tester = new CommandTester($this->command);
        $tester->execute([]);

        self::assertInstanceOf(GenerateReportCommand::class, $capturedCommand);
        self::assertSame('', $capturedCommand->harborUrl);
        self::assertSame('', $capturedCommand->token);
        self::assertNull($capturedCommand->username);
        self::assertSame('', $capturedCommand->mode);
        self::assertNull($capturedCommand->from);
        self::assertNull($capturedCommand->to);
        self::assertSame('', $capturedCommand->outputPath);
        self::assertTrue($capturedCommand->verifySsl);
    }
}
