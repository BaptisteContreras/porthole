<?php

namespace Porthole\Result;

use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportRow;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class ImageReportReaderStrategy implements ReportReaderStrategyInterface
{
    public function __construct(private readonly DenormalizerInterface $denormalizer)
    {
    }

    public function supports(string $type): bool
    {
        return 'images' === $type;
    }

    public function build(array $data): ImageReport
    {
        $rows = array_map(
            fn (array $row): ImageReportRow => $this->denormalizer->denormalize($row, ImageReportRow::class, 'csv'),
            $data,
        );

        return new ImageReport($rows);
    }
}
