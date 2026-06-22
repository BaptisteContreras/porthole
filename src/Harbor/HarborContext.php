<?php

namespace Porthole\Harbor;

final class HarborContext
{
    public function __construct(
        public readonly string $url,
        public readonly string $token,
        public readonly ?string $username,
        public readonly bool $verifySsl,
        public readonly AuditLogEndpointStrategyInterface $auditLogEndpointStrategy = new ExtendedAuditLogEndpointStrategy(),
    ) {
    }
}
