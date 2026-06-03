<?php

namespace Porthole\Tests\Harbor;

use PHPUnit\Framework\TestCase;
use Porthole\Harbor\AuditLogBuilder;
use Porthole\Harbor\AuditLogEntry;

class AuditLogBuilderTest extends TestCase
{
    public function testBuildsAuditLogEntryFromApiResponseItem(): void
    {
        $item = [
            'username' => 'alice',
            'resource' => 'library/nginx:latest',
            'resource_type' => 'artifact',
            'operation' => 'pull',
            'op_time' => '2025-06-01T10:00:00.000Z',
        ];

        $entry = AuditLogBuilder::buildFromApiResponseItem($item);

        $this->assertInstanceOf(AuditLogEntry::class, $entry);
        $this->assertSame('alice', $entry->username);
        $this->assertSame('library/nginx:latest', $entry->resource);
        $this->assertSame('artifact', $entry->resourceType);
        $this->assertSame('pull', $entry->operation);
        $this->assertSame('2025-06-01T10:00:00+00:00', $entry->opTime->format(\DateTimeInterface::ATOM));
    }
}
