<?php

namespace Porthole\Tests\Result;

use PHPUnit\Framework\TestCase;
use Porthole\Report\ImageReport;
use Porthole\Report\UserReport;
use Porthole\Result\CsvReader;
use Porthole\Result\InvalidReportFileException;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class CsvReaderTest extends TestCase
{
    private CsvReader $reader;
    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory);
        $propertyInfo = new PropertyInfoExtractor(typeExtractors: [new ReflectionExtractor()]);
        $this->reader = new CsvReader(new Serializer(
            [new ObjectNormalizer(classMetadataFactory: $classMetadataFactory, nameConverter: $nameConverter, propertyTypeExtractor: $propertyInfo)],
            [new CsvEncoder()]
        ));
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    public function testReadsImagesReport(): void
    {
        $file = $this->writeTempCsv(
            "# porthole:type=images\nImage;Tag;\"Number of pulls\"\nlibrary/nginx;latest;42\nlibrary/redis;7;3\n"
        );

        $report = $this->reader->read($file);

        $this->assertInstanceOf(ImageReport::class, $report);
        $this->assertCount(2, $report->rows);
        $this->assertSame('library/nginx', $report->rows[0]->image);
        $this->assertSame('latest', $report->rows[0]->tag);
        $this->assertSame(42, $report->rows[0]->pullCount);
        $this->assertSame('library/redis', $report->rows[1]->image);
        $this->assertSame('7', $report->rows[1]->tag);
        $this->assertSame(3, $report->rows[1]->pullCount);
    }

    public function testReadsUsersReport(): void
    {
        $file = $this->writeTempCsv(
            "# porthole:type=users\nUser;Image;Tag;\"Number of pulls\"\nalice;library/nginx;latest;5\n"
        );

        $report = $this->reader->read($file);

        $this->assertInstanceOf(UserReport::class, $report);
        $this->assertCount(1, $report->rows);
        $this->assertSame('alice', $report->rows[0]->username);
        $this->assertSame('library/nginx', $report->rows[0]->image);
        $this->assertSame('latest', $report->rows[0]->tag);
        $this->assertSame(5, $report->rows[0]->pullCount);
    }

    public function testThrowsRuntimeExceptionForUnreadableFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot open file/');
        $this->reader->read('/nonexistent/path/report.csv');
    }

    public function testThrowsInvalidReportFileExceptionWhenMetadataCommentMissing(): void
    {
        $file = $this->writeTempCsv("Image;Tag;\"Number of pulls\"\nlibrary/nginx;latest;42\n");

        $this->expectException(InvalidReportFileException::class);
        $this->expectExceptionMessage('Not a porthole report file.');
        $this->reader->read($file);
    }

    public function testThrowsInvalidReportFileExceptionForUnknownType(): void
    {
        $file = $this->writeTempCsv("# porthole:type=unknown\nCol1;Col2\nval1;val2\n");

        $this->expectException(InvalidReportFileException::class);
        $this->expectExceptionMessage('Unknown report type "unknown".');
        $this->reader->read($file);
    }

    private function writeTempCsv(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'porthole_reader_');
        $this->tempFiles[] = $file;
        file_put_contents($file, $content);

        return $file;
    }
}
