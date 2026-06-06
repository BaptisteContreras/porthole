<?php

namespace Porthole\Event;

final class CsvWrittenEvent
{
    public function __construct(
        public readonly string $outputPath,
        public readonly int $rowCount,
    ) {
    }
}
