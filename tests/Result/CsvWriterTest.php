<?php

namespace Porthole\Tests\Result;

use PHPUnit\Framework\TestCase;
use Porthole\Result\CsvWriter;

class CsvWriterTest extends TestCase
{
    private string $outputFile;

    protected function setUp(): void
    {
        $this->outputFile = (string) tempnam(sys_get_temp_dir(), 'porthole_csv_');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputFile)) {
            unlink($this->outputFile);
        }
    }

    public function testWritesHeaderAndRowsReturnsTotalRowCount(): void
    {
        $writer = new CsvWriter();
        $rowCount = $writer->write(
            $this->outputFile,
            ['Image', 'Tag', 'Number of pulls'],
            [
                ['library/nginx', 'latest', 42],
                ['library/redis', '7', 3],
            ]
        );

        $this->assertSame(2, $rowCount);

        $lines = file($this->outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertSame('Image;Tag;"Number of pulls"', $lines[0]);
        $this->assertSame('library/nginx;latest;42', $lines[1]);
        $this->assertSame('library/redis;7;3', $lines[2]);
    }

    public function testWritesEmptyRowsWithHeaderOnly(): void
    {
        $writer = new CsvWriter();
        $rowCount = $writer->write($this->outputFile, ['Image', 'Tag', 'Number of pulls'], []);

        $this->assertSame(0, $rowCount);
        $lines = file($this->outputFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines);
        $this->assertSame('Image;Tag;"Number of pulls"', $lines[0]);
    }

    public function testThrowsRuntimeExceptionOnUnwritablePath(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot open file for writing/');

        $writer = new CsvWriter();
        $writer->write('/nonexistent/path/report.csv', ['Col'], []);
    }
}
