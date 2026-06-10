<?php

namespace Porthole\Result;

use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class UserReportReaderStrategy implements ReportReaderStrategyInterface
{
    public function __construct(private readonly DenormalizerInterface $denormalizer)
    {
    }

    public function supports(string $type): bool
    {
        return 'users' === $type;
    }

    public function build(array $data): UserReport
    {
        $rows = array_map(
            fn (array $row): UserReportRow => $this->denormalizer->denormalize($row, UserReportRow::class, 'csv'),
            $data,
        );

        return new UserReport($rows);
    }
}
