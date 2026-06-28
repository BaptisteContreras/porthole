<?php

namespace Porthole\UseCase;

use Porthole\Event\AuditLogPageFetchedEvent;
use Porthole\Event\AuditLogsFetchedEvent;
use Porthole\Event\CsvWrittenEvent;
use Porthole\Event\ReportBuiltEvent;
use Porthole\Harbor\AuditLogEndpointStrategyInterface;
use Porthole\Harbor\ExtendedAuditLogEndpointStrategy;
use Porthole\Harbor\HarborApiClient;
use Porthole\Harbor\HarborContext;
use Porthole\Harbor\LegacyAuditLogEndpointStrategy;
use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportRow;
use Porthole\Report\ReportBuilder;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Porthole\Result\CsvWriter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AsAlias(GenerateReportHandlerInterface::class)]
final class GenerateReportHandler implements GenerateReportHandlerInterface
{
    public function __construct(
        private readonly HarborApiClient $client,
        private readonly ReportBuilder $reportBuilder,
        private readonly CsvWriter $csvWriter,
    ) {
    }

    public function handle(GenerateReportCommand $command, EventDispatcherInterface $dispatcher): void
    {
        $context = new HarborContext(
            url: $command->harborUrl,
            token: $command->token,
            username: $command->username,
            verifySsl: $command->verifySsl,
            auditLogEndpointStrategy: self::resolveStrategy($command->auditLogEndpoint),
        );

        $totalEntries = 0;
        $stream = (function () use ($context, $command, $dispatcher, &$totalEntries): \Generator {
            foreach ($this->client->streamAuditLogs($context, $command->from, $command->to) as $page => $pageEntries) {
                $totalEntries += count($pageEntries);
                $dispatcher->dispatch(new AuditLogPageFetchedEvent($page, $totalEntries));
                yield from $pageEntries;
            }
        })();

        $report = $this->buildReport($stream, $command->mode);
        $dispatcher->dispatch(new AuditLogsFetchedEvent($totalEntries));
        $dispatcher->dispatch(new ReportBuiltEvent(count($report->rows)));

        $rowCount = $this->csvWriter->write($command->outputPath, $command->mode, $report->rows);
        $dispatcher->dispatch(new CsvWrittenEvent($command->outputPath, $rowCount));
    }

    private static function resolveStrategy(string $key): AuditLogEndpointStrategyInterface
    {
        return match ($key) {
            'legacy' => new LegacyAuditLogEndpointStrategy(),
            default => new ExtendedAuditLogEndpointStrategy(),
        };
    }

    /**
     * @param iterable<\Porthole\Harbor\AuditLogEntry> $entries
     */
    private function buildReport(iterable $entries, string $mode): ImageReport|UserReport
    {
        if ('users' === $mode) {
            $report = $this->reportBuilder->buildUsersReport($entries);
            $rows = $report->rows;
            usort($rows, fn (UserReportRow $a, UserReportRow $b) => $a->username <=> $b->username ?: $b->pullCount <=> $a->pullCount);

            return new UserReport($rows);
        }

        $report = $this->reportBuilder->buildImagesReport($entries);
        $rows = $report->rows;
        usort($rows, fn (ImageReportRow $a, ImageReportRow $b) => $b->pullCount <=> $a->pullCount);

        return new ImageReport($rows);
    }
}
