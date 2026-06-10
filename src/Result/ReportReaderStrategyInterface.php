<?php

namespace Porthole\Result;

use Porthole\Report\ImageReport;
use Porthole\Report\UserReport;

interface ReportReaderStrategyInterface
{
    public function supports(string $type): bool;

    /**
     * @param list<array<string, string>> $data
     */
    public function build(array $data): ImageReport|UserReport;
}
