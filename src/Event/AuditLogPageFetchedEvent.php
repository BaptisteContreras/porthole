<?php

namespace Porthole\Event;

final class AuditLogPageFetchedEvent
{
    public function __construct(
        public readonly int $page,
        public readonly int $totalEntriesSoFar,
    ) {
    }
}
