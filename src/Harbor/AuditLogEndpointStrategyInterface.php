<?php

// src/Harbor/AuditLogEndpointStrategyInterface.php

namespace Porthole\Harbor;

interface AuditLogEndpointStrategyInterface
{
    public function buildUrl(string $baseUrl): string;

    public function getKey(): string;
}
