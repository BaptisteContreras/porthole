<?php

// tests/Harbor/AuditLogEndpointStrategyTest.php

namespace Porthole\Tests\Harbor;

use PHPUnit\Framework\TestCase;
use Porthole\Harbor\ExtendedAuditLogEndpointStrategy;
use Porthole\Harbor\LegacyAuditLogEndpointStrategy;

final class AuditLogEndpointStrategyTest extends TestCase
{
    public function testExtendedBuildsCorrectUrl(): void
    {
        $strategy = new ExtendedAuditLogEndpointStrategy();
        $this->assertSame(
            'https://registry.example.com/api/v2.0/auditlog-exts',
            $strategy->buildUrl('https://registry.example.com'),
        );
    }

    public function testExtendedReturnsKey(): void
    {
        $this->assertSame('extended', (new ExtendedAuditLogEndpointStrategy())->getKey());
    }

    public function testLegacyBuildsCorrectUrl(): void
    {
        $strategy = new LegacyAuditLogEndpointStrategy();
        $this->assertSame(
            'https://registry.example.com/api/v2.0/audit-logs',
            $strategy->buildUrl('https://registry.example.com'),
        );
    }

    public function testLegacyReturnsKey(): void
    {
        $this->assertSame('legacy', (new LegacyAuditLogEndpointStrategy())->getKey());
    }
}
