<?php

namespace Porthole\Result;

use Porthole\Report\ImageReport;
use Porthole\Report\UserReport;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Serializer\Encoder\CsvEncoder;
use Symfony\Component\Serializer\Encoder\DecoderInterface;

final class CsvReader
{
    /**
     * @param iterable<ReportReaderStrategyInterface> $strategies
     */
    public function __construct(
        private readonly DecoderInterface $decoder,
        #[AutowireIterator('csv.reader_strategy')]
        private readonly iterable $strategies,
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

        $strategy = $this->findStrategy($type);
        if (null === $strategy) {
            fclose($handle);
            throw new InvalidReportFileException(sprintf('Unknown report type "%s".', $type));
        }

        $csvContent = (string) stream_get_contents($handle);
        fclose($handle);

        /** @var list<array<string, string>> $data */
        $data = $this->decoder->decode($csvContent, 'csv', [
            CsvEncoder::DELIMITER_KEY => ';',
            CsvEncoder::AS_COLLECTION_KEY => true,
        ]);

        return $strategy->build($data);
    }

    private function findStrategy(string $type): ?ReportReaderStrategyInterface
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($type)) {
                return $strategy;
            }
        }

        return null;
    }
}
