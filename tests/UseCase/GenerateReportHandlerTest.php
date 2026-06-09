<?php

namespace Porthole\Tests\UseCase;

use PHPUnit\Framework\TestCase;
use Porthole\Event\AuditLogPageFetchedEvent;
use Porthole\Event\AuditLogsFetchedEvent;
use Porthole\Event\CsvWrittenEvent;
use Porthole\Event\ReportBuiltEvent;
use Porthole\Harbor\HarborApiClient;
use Porthole\Report\ReportBuilder;
use Porthole\Result\CsvWriter;
use Porthole\UseCase\GenerateReportCommand;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class GenerateReportHandlerTest extends TestCase
{
    private string $outputFile;

    protected function setUp(): void
    {
        $this->outputFile = (string) tempnam(sys_get_temp_dir(), 'porthole_handler_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputFile)) {
            unlink($this->outputFile);
        }
    }

    public function testDispatchesAllEventsInOrderForSinglePage(): void
    {
        $body = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T10:00:00.000Z'],
            ['username' => 'bob',   'resource' => 'library/redis:7',      'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-03T11:00:00.000Z'],
        ]);
        assert(is_string($body));

        $handler = new GenerateReportHandler(
            new HarborApiClient(new MockHttpClient([new MockResponse($body)])),
            new ReportBuilder(),
            $this->makeWriter(),
        );

        $command = new GenerateReportCommand(
            harborUrl: 'https://registry.example.com',
            token: 'test-token',
            username: null,
            mode: 'images',
            from: null,
            to: null,
            outputPath: $this->outputFile,
            verifySsl: true,
        );

        $dispatched = [];
        $dispatcher = new EventDispatcher();
        foreach ([
            AuditLogPageFetchedEvent::class,
            AuditLogsFetchedEvent::class,
            ReportBuiltEvent::class,
            CsvWrittenEvent::class,
        ] as $eventClass) {
            $dispatcher->addListener($eventClass, function (object $e) use (&$dispatched) {
                $dispatched[] = $e;
            });
        }

        $handler->handle($command, $dispatcher);

        $this->assertCount(4, $dispatched);

        $this->assertInstanceOf(AuditLogPageFetchedEvent::class, $dispatched[0]);
        $this->assertSame(1, $dispatched[0]->page);
        $this->assertSame(3, $dispatched[0]->totalEntriesSoFar);

        $this->assertInstanceOf(AuditLogsFetchedEvent::class, $dispatched[1]);
        $this->assertSame(3, $dispatched[1]->totalEntries);

        $this->assertInstanceOf(ReportBuiltEvent::class, $dispatched[2]);
        $this->assertSame(2, $dispatched[2]->rowCount);

        $this->assertInstanceOf(CsvWrittenEvent::class, $dispatched[3]);
        $this->assertSame(2, $dispatched[3]->rowCount);
        $this->assertSame($this->outputFile, $dispatched[3]->outputPath);
    }

    public function testDispatchesPageFetchedEventForEachPage(): void
    {
        $page1 = json_encode([
            ['username' => 'alice', 'resource' => 'library/nginx:latest', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-01T10:00:00.000Z'],
        ]);
        $page2 = json_encode([
            ['username' => 'bob', 'resource' => 'library/redis:7', 'resource_type' => 'artifact', 'operation' => 'pull', 'op_time' => '2025-06-02T11:00:00.000Z'],
        ]);
        assert(is_string($page1));
        assert(is_string($page2));

        $handler = new GenerateReportHandler(
            new HarborApiClient(new MockHttpClient([
                new MockResponse($page1, ['response_headers' => ['Link' => '</api/v2.0/audit-logs?page=2&page_size=100>; rel="next"']]),
                new MockResponse($page2),
            ])),
            new ReportBuilder(),
            $this->makeWriter(),
        );

        $command = new GenerateReportCommand(
            harborUrl: 'https://registry.example.com',
            token: 'test-token',
            username: null,
            mode: 'images',
            from: null,
            to: null,
            outputPath: $this->outputFile,
            verifySsl: true,
        );

        $pageEvents = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(AuditLogPageFetchedEvent::class, function (AuditLogPageFetchedEvent $e) use (&$pageEvents) {
            $pageEvents[] = ['page' => $e->page, 'total' => $e->totalEntriesSoFar];
        });

        $handler->handle($command, $dispatcher);

        $this->assertSame([
            ['page' => 1, 'total' => 1],
            ['page' => 2, 'total' => 2],
        ], $pageEvents);
    }

    private function makeWriter(): CsvWriter
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $propertyInfo = new PropertyInfoExtractor(typeExtractors: [new ReflectionExtractor()]);

        return new CsvWriter(new Serializer(
            [new ObjectNormalizer(classMetadataFactory: $classMetadataFactory, nameConverter: $nameConverter, propertyTypeExtractor: $propertyInfo)],
            [new CsvEncoder()]
        ));
    }
}
