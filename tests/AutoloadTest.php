<?php

namespace Porthole\Tests;

use PHPUnit\Framework\TestCase;
use Porthole\Command\StartCommand;
use Porthole\Harbor\HarborApiClient;
use Porthole\Report\ReportBuilder;
use Porthole\Result\CsvReader;
use Porthole\Result\CsvWriter;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class AutoloadTest extends TestCase
{
    public function testStartCommandIsInstantiable(): void
    {
        $this->assertInstanceOf(StartCommand::class, new StartCommand(
            new GenerateReportHandler(
                new HarborApiClient(new MockHttpClient()),
                new ReportBuilder(),
                $this->makeWriter(),
            ),
            $this->makeReader(),
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
        $this->assertInstanceOf(CsvWriter::class, $this->makeWriter());
    }

    private function makeSerializer(): Serializer
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $propertyInfo = new PropertyInfoExtractor(typeExtractors: [new ReflectionExtractor()]);

        return new Serializer(
            [new ObjectNormalizer(classMetadataFactory: $classMetadataFactory, nameConverter: $nameConverter, propertyTypeExtractor: $propertyInfo)],
            [new CsvEncoder()]
        );
    }

    private function makeWriter(): CsvWriter
    {
        return new CsvWriter($this->makeSerializer());
    }

    private function makeReader(): CsvReader
    {
        return new CsvReader($this->makeSerializer());
    }
}
