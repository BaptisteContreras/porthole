<?php

namespace Porthole\Tests;

use PHPUnit\Framework\TestCase;
use Porthole\Command\ReportCommand;
use Porthole\Harbor\HarborApiClient;
use Porthole\Report\ReportBuilder;
use Porthole\Result\CsvWriter;
use Symfony\Component\HttpClient\MockHttpClient;

class AutoloadTest extends TestCase
{
    public function testReportCommandIsInstantiable(): void
    {
        $this->assertInstanceOf(ReportCommand::class, new ReportCommand(new MockHttpClient()));
    }

    public function testHarborApiClientIsInstantiable(): void
    {
        $this->assertInstanceOf(HarborApiClient::class, new HarborApiClient('https://registry.example.com', 'token', new MockHttpClient()));
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
