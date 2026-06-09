<?php

namespace Porthole\Tests\Result;

use PHPUnit\Framework\TestCase;
use Porthole\Report\ImageReportRow;
use Porthole\Report\UserReportRow;
use Porthole\Result\CsvWriter;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class CsvWriterTest extends TestCase
{
    private string $outputFile;
    private CsvWriter $writer;

    protected function setUp(): void
    {
        $this->outputFile = (string) tempnam(sys_get_temp_dir(), 'porthole_csv_');
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $propertyInfo = new \Symfony\Component\PropertyInfo\PropertyInfoExtractor(
            typeExtractors: [new ReflectionExtractor()]
        );
        $this->writer = new CsvWriter(new Serializer(
            [new ObjectNormalizer(classMetadataFactory: $classMetadataFactory, nameConverter: $nameConverter, propertyTypeExtractor: $propertyInfo)],
            [new CsvEncoder()]
        ));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputFile)) {
            unlink($this->outputFile);
        }
    }

    public function testWritesImagesReportWithMetadataCommentAndData(): void
    {
        $rowCount = $this->writer->write(
            $this->outputFile,
            'images',
            [
                new ImageReportRow('library/nginx', 'latest', 42),
                new ImageReportRow('library/redis', '7', 3),
            ]
        );

        $this->assertSame(2, $rowCount);

        $lines = file($this->outputFile, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertSame('# porthole:type=images', $lines[0]);
        $this->assertStringContainsString('Image', $lines[1]);
        $this->assertStringContainsString('Tag', $lines[1]);
        $this->assertStringContainsString('Number of pulls', $lines[1]);
        $this->assertStringContainsString('library/nginx', $lines[2]);
        $this->assertStringContainsString('42', $lines[2]);
        $this->assertStringContainsString('library/redis', $lines[3]);
        $this->assertStringContainsString('3', $lines[3]);
    }

    public function testWritesUsersReportWithMetadataComment(): void
    {
        $rowCount = $this->writer->write(
            $this->outputFile,
            'users',
            [
                new UserReportRow('alice', 'library/nginx', 'latest', 5),
            ]
        );

        $this->assertSame(1, $rowCount);

        $lines = file($this->outputFile, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);
        $this->assertSame('# porthole:type=users', $lines[0]);
        $this->assertStringContainsString('User', $lines[1]);
        $this->assertStringContainsString('alice', $lines[2]);
        $this->assertStringContainsString('5', $lines[2]);
    }

    public function testWritesZeroRowsReturnsZero(): void
    {
        $rowCount = $this->writer->write($this->outputFile, 'images', []);

        $this->assertSame(0, $rowCount);
        $content = (string) file_get_contents($this->outputFile);
        $this->assertStringStartsWith('# porthole:type=images', $content);
    }

    public function testThrowsRuntimeExceptionOnUnwritablePath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot open file for writing/');
        $this->writer->write('/nonexistent/path/report.csv', 'images', []);
    }
}
