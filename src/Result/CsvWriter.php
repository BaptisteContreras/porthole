<?php

namespace Porthole\Result;

use Porthole\Report\ImageReportRow;
use Porthole\Report\UserReportRow;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\SerializerInterface;

final class CsvWriter
{
    public function __construct(
        private readonly SerializerInterface $serializer,
    ) {
    }

    /**
     * @param list<ImageReportRow>|list<UserReportRow> $rows
     */
    public function write(string $outputPath, string $type, array $rows): int
    {
        $handle = @fopen($outputPath, 'w');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot open file for writing: %s', $outputPath));
        }

        fwrite($handle, sprintf("# porthole:type=%s\n", $type));
        fwrite($handle, $this->serializer->serialize($rows, 'csv', [
            CsvEncoder::DELIMITER_KEY => ';',
        ]));
        fclose($handle);

        return count($rows);
    }
}
