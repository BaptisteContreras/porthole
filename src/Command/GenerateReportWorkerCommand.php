<?php

namespace Porthole\Command;

use Porthole\Event\AuditLogPageFetchedEvent;
use Porthole\Event\AuditLogsFetchedEvent;
use Porthole\Event\CsvWrittenEvent;
use Porthole\Event\ReportBuiltEvent;
use Porthole\UseCase\GenerateReportCommand;
use Porthole\UseCase\GenerateReportHandlerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class GenerateReportWorkerCommand extends Command
{
    /** @var resource */
    private mixed $inputStream;

    /** @var resource */
    private mixed $outputStream;

    /**
     * @param resource|null $inputStream
     * @param resource|null $outputStream
     */
    public function __construct(
        private readonly GenerateReportHandlerInterface $handler,
        mixed $inputStream = null,
        mixed $outputStream = null,
    ) {
        parent::__construct();
        $this->inputStream = is_resource($inputStream) ? $inputStream : \STDIN;
        $this->outputStream = is_resource($outputStream) ? $outputStream : \STDOUT;
    }

    protected function configure(): void
    {
        $this
            ->setName('generate-report:worker')
            ->setHidden(true);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $raw = stream_get_contents($this->inputStream);

        try {
            $data = json_decode(is_string($raw) ? $raw : '', true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->writeLine(['type' => 'error', 'message' => 'Invalid task payload']);

            return Command::FAILURE;
        }

        if (!\is_array($data)) {
            $this->writeLine(['type' => 'error', 'message' => 'Invalid task payload']);

            return Command::FAILURE;
        }

        /** @var array<string, mixed> $data */
        $command = $this->buildCommand($data);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AuditLogPageFetchedEvent::class, function (AuditLogPageFetchedEvent $event): void {
            $this->writeLine(['type' => 'page_fetched', 'page' => $event->page, 'total' => $event->totalEntriesSoFar]);
        });
        $dispatcher->addListener(AuditLogsFetchedEvent::class, function (AuditLogsFetchedEvent $event): void {
            $this->writeLine(['type' => 'logs_fetched', 'total' => $event->totalEntries]);
        });
        $dispatcher->addListener(ReportBuiltEvent::class, function (ReportBuiltEvent $_event): void {
            $this->writeLine(['type' => 'report_built']);
        });
        $dispatcher->addListener(CsvWrittenEvent::class, function (CsvWrittenEvent $event): void {
            $this->writeLine(['type' => 'csv_written', 'path' => $event->outputPath, 'rows' => $event->rowCount]);
        });

        try {
            $this->handler->handle($command, $dispatcher);
        } catch (\Throwable $e) {
            $this->writeLine(['type' => 'error', 'message' => $e->getMessage()]);

            return Command::FAILURE;
        }

        $this->writeLine(['type' => 'done']);

        return Command::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function buildCommand(array $data): GenerateReportCommand
    {
        $rawUsername = $data['username'] ?? null;
        $username = is_string($rawUsername) ? $rawUsername : null;

        $rawFrom = $data['from'] ?? null;
        try {
            $from = is_string($rawFrom) ? new \DateTimeImmutable($rawFrom) : null;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Invalid "from" date "%s"', $rawFrom), 0, $e);
        }

        $rawTo = $data['to'] ?? null;
        try {
            $to = is_string($rawTo) ? new \DateTimeImmutable($rawTo) : null;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(sprintf('Invalid "to" date "%s"', $rawTo), 0, $e);
        }

        $rawSsl = $data['verifySsl'] ?? true;
        $verifySsl = is_bool($rawSsl) ? $rawSsl : (bool) $rawSsl;

        return new GenerateReportCommand(
            harborUrl: self::stringField($data, 'harborUrl'),
            token: self::stringField($data, 'token'),
            username: $username,
            mode: self::stringField($data, 'mode'),
            from: $from,
            to: $to,
            outputPath: self::stringField($data, 'outputPath'),
            verifySsl: $verifySsl,
        );
    }

    /** @param array<string, mixed> $data */
    private static function stringField(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $data */
    private function writeLine(array $data): void
    {
        $stream = $this->outputStream;
        fwrite($stream, json_encode($data, \JSON_THROW_ON_ERROR)."\n");
        fflush($stream);
    }
}
