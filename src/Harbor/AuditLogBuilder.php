<?php

namespace Porthole\Harbor;

final class AuditLogBuilder
{
    /**
     * @param array{username: string, resource: string, resource_type: string, operation: string, op_time: string} $item
     */
    public static function buildFromApiResponseItem(array $item): AuditLogEntry
    {
        return new AuditLogEntry(
            username: $item['username'],
            resource: $item['resource'],
            resourceType: $item['resource_type'],
            operation: $item['operation'],
            opTime: new \DateTimeImmutable($item['op_time']),
        );
    }
}
