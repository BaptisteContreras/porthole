<?php

namespace Porthole\Harbor;

final class AuditLogEntry
{
    public function __construct(
        public readonly string $username,
        public readonly string $resource,
        public readonly string $resourceType,
        public readonly string $operation,
        public readonly \DateTimeImmutable $opTime,
    ) {
    }
}
