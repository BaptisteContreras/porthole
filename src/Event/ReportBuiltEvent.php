<?php

namespace Porthole\Event;

final class ReportBuiltEvent
{
    public function __construct(
        public readonly int $rowCount,
    ) {
    }
}
