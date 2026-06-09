<?php

namespace Porthole\Result;

use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportRow;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class CsvReader
{
    public function __construct(
        private readonly DecoderInterface&DenormalizerInterface $serializer,
    ) {
    }

    public function read(string $path): ImageReport|UserReport
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('Cannot open file: %s', $path));
        }

        $firstLine = fgets($handle);
        if (false === $firstLine || !str_starts_with(trim($firstLine), '# porthole:')) {
            fclose($handle);
            throw new InvalidReportFileException('Not a porthole report file.');
        }

        $meta = trim(substr(trim($firstLine), \strlen('# porthole:')));
        $params = [];
        foreach (explode(';', $meta) as $pair) {
            $parts = explode('=', $pair, 2);
            if (2 === \count($parts)) {
                $params[trim($parts[0])] = trim($parts[1]);
            }
        }

        $type = $params['type'] ?? null;
        if (null === $type) {
            fclose($handle);
            throw new InvalidReportFileException('Not a porthole report file.');
        }

        if (!\in_array($type, ['images', 'users'], true)) {
            fclose($handle);
            throw new InvalidReportFileException(sprintf('Unknown report type "%s".', $type));
        }

        $csvContent = (string) stream_get_contents($handle);
        fclose($handle);

        if ('images' === $type) {
            /** @var list<array<string, string>> $data */
            $data = $this->serializer->decode($csvContent, 'csv', [
                CsvEncoder::DELIMITER_KEY => ';',
                CsvEncoder::AS_COLLECTION_KEY => true,
            ]);

            $rows = array_map(
                fn (array $row): ImageReportRow => $this->serializer->denormalize($row, ImageReportRow::class, 'csv'),
                $data,
            );

            return new ImageReport($rows);
        }

        /** @var list<array<string, string>> $data */
        $data = $this->serializer->decode($csvContent, 'csv', [
            CsvEncoder::DELIMITER_KEY => ';',
            CsvEncoder::AS_COLLECTION_KEY => true,
        ]);

        $rows = array_map(
            fn (array $row): UserReportRow => $this->serializer->denormalize($row, UserReportRow::class, 'csv'),
            $data,
        );

        return new UserReport($rows);
    }
}
