<?php

// src/Harbor/ExtendedAuditLogEndpointStrategy.php

namespace Porthole\Harbor;

final class ExtendedAuditLogEndpointStrategy implements AuditLogEndpointStrategyInterface
{
    public function buildUrl(string $baseUrl): string
    {
        return sprintf('%s/api/v2.0/auditlog-exts', $baseUrl);
    }

    public function getKey(): string
    {
        return 'extended';
    }
}
