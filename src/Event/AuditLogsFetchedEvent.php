<?php

namespace Porthole\Event;

final class AuditLogsFetchedEvent
{
    public function __construct(
        public readonly int $totalEntries,
    ) {
    }
}
