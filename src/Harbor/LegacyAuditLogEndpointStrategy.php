<?php

// src/Harbor/LegacyAuditLogEndpointStrategy.php

namespace Porthole\Harbor;

final class LegacyAuditLogEndpointStrategy implements AuditLogEndpointStrategyInterface
{
    public function buildUrl(string $baseUrl): string
    {
        return sprintf('%s/api/v2.0/audit-logs', $baseUrl);
    }

    public function getKey(): string
    {
        return 'legacy';
    }
}
