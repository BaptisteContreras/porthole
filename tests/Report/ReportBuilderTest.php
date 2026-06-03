<?php

namespace Porthole\Tests\Report;

use PHPUnit\Framework\TestCase;
use Porthole\Harbor\AuditLogEntry;
use Porthole\Report\ImageReportRow;
use Porthole\Report\ReportBuilder;
use Porthole\Report\UserReportRow;

class ReportBuilderTest extends TestCase
{
    public function testImageReportRowHoldsData(): void
    {
        $row = new ImageReportRow(image: 'library/nginx', tag: 'latest', pullCount: 42);

        $this->assertSame('library/nginx', $row->image);
        $this->assertSame('latest', $row->tag);
        $this->assertSame(42, $row->pullCount);
    }

    public function testUserReportRowHoldsData(): void
    {
        $row = new UserReportRow(username: 'alice', image: 'library/nginx', tag: 'latest', pullCount: 7);

        $this->assertSame('alice', $row->username);
        $this->assertSame('library/nginx', $row->image);
        $this->assertSame('latest', $row->tag);
        $this->assertSame(7, $row->pullCount);
    }

    public function testBuildImagesReportAggregatesPullsByImageAndTag(): void
    {
        $entries = [
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
            new AuditLogEntry('bob', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
            new AuditLogEntry('alice', 'library/redis:7', 'artifact', 'pull', new \DateTimeImmutable()),
        ];

        $rows = (new ReportBuilder())->buildImagesReport($entries)->rows;

        $this->assertCount(2, $rows);
        $this->assertSame('library/nginx', $rows[0]->image);
        $this->assertSame('latest', $rows[0]->tag);
        $this->assertSame(2, $rows[0]->pullCount);
        $this->assertSame('library/redis', $rows[1]->image);
        $this->assertSame('7', $rows[1]->tag);
        $this->assertSame(1, $rows[1]->pullCount);
    }

    public function testBuildImagesReportIgnoresNonPullOperations(): void
    {
        $entries = [
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'push', new \DateTimeImmutable()),
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'delete', new \DateTimeImmutable()),
        ];

        $rows = (new ReportBuilder())->buildImagesReport($entries)->rows;

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->pullCount);
    }

    public function testBuildImagesReportHandlesResourceWithNoTag(): void
    {
        $entries = [
            new AuditLogEntry('alice', 'library/nginx', 'artifact', 'pull', new \DateTimeImmutable()),
        ];

        $rows = (new ReportBuilder())->buildImagesReport($entries)->rows;

        $this->assertCount(1, $rows);
        $this->assertSame('library/nginx', $rows[0]->image);
        $this->assertSame('', $rows[0]->tag);
    }

    public function testBuildUsersReportAggregatesPullsByUserImageAndTag(): void
    {
        $entries = [
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
            new AuditLogEntry('bob', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
        ];

        $rows = (new ReportBuilder())->buildUsersReport($entries)->rows;

        $this->assertCount(2, $rows);
        $this->assertSame('alice', $rows[0]->username);
        $this->assertSame('library/nginx', $rows[0]->image);
        $this->assertSame('latest', $rows[0]->tag);
        $this->assertSame(2, $rows[0]->pullCount);
        $this->assertSame('bob', $rows[1]->username);
        $this->assertSame('library/nginx', $rows[1]->image);
        $this->assertSame('latest', $rows[1]->tag);
        $this->assertSame(1, $rows[1]->pullCount);
    }

    public function testBuildUsersReportIgnoresNonPullOperations(): void
    {
        $entries = [
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'pull', new \DateTimeImmutable()),
            new AuditLogEntry('alice', 'library/nginx:latest', 'artifact', 'push', new \DateTimeImmutable()),
        ];

        $rows = (new ReportBuilder())->buildUsersReport($entries)->rows;

        $this->assertCount(1, $rows);
        $this->assertSame(1, $rows[0]->pullCount);
    }
}
