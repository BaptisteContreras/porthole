<?php

namespace Porthole\Report;

final class ImageReport
{
    /**
     * @param list<ImageReportRow> $rows
     */
    public function __construct(
        public readonly array $rows,
    ) {
    }
}
