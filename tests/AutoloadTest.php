<?php

namespace Porthole\Tests;

use PHPUnit\Framework\TestCase;
use Porthole\Command\StartCommand;
use Porthole\Harbor\HarborApiClient;
use Porthole\Report\ReportBuilder;
use Porthole\Result\CsvWriter;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\HttpClient\MockHttpClient;

class AutoloadTest extends TestCase
{
    public function testStartCommandIsInstantiable(): void
    {
        $this->assertInstanceOf(StartCommand::class, new StartCommand(
            new GenerateReportHandler(
                new HarborApiClient(new MockHttpClient()),
                new ReportBuilder(),
                new CsvWriter(),
            )
        ));
    }

    public function testHarborApiClientIsInstantiable(): void
    {
        $this->assertInstanceOf(HarborApiClient::class, new HarborApiClient(new MockHttpClient()));
    }

    public function testReportBuilderIsInstantiable(): void
    {
        $this->assertInstanceOf(ReportBuilder::class, new ReportBuilder());
    }

    public function testCsvWriterIsInstantiable(): void
    {
        $this->assertInstanceOf(CsvWriter::class, new CsvWriter());
    }
}
