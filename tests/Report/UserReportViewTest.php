<?php

namespace Porthole\Tests\Report;

use PHPUnit\Framework\TestCase;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;

final class UserReportViewTest extends TestCase
{
    private function makeReport(): UserReport
    {
        return new UserReport([
            new UserReportRow('alice', 'nginx', 'latest', 42),
            new UserReportRow('bob', 'redis', '7.0', 8),
            new UserReportRow('alice', 'redis', 'latest', 10),
        ]);
    }

    public function testTabDefinitionsReturnsFiveTabs(): void
    {
        $view = $this->makeReport()->asView();
        $tabs = $view->tabDefinitions();

        self::assertArrayHasKey('all', $tabs);
        self::assertArrayHasKey('least', $tabs);
        self::assertArrayHasKey('by_image', $tabs);
        self::assertArrayHasKey('top_users', $tabs);
        self::assertArrayHasKey('total', $tabs);

        foreach ($tabs as $tab) {
            self::assertArrayHasKey('label', $tab);
            self::assertArrayHasKey('header', $tab);
        }
    }

    public function testAllRowsCountMatchesReport(): void
    {
        $view = $this->makeReport()->asView();

        self::assertCount(3, $view->rows('all'));
    }

    public function testAllRowsIsCached(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame($view->rows('all'), $view->rows('all'));
    }

    public function testLeastPulledSortsAscending(): void
    {
        $view = $this->makeReport()->asView();
        $rows = $view->rows('least');

        self::assertCount(3, $rows);
        self::assertStringContainsString('8', $rows[0]['label']);
        self::assertStringContainsString('42', $rows[2]['label']);
    }

    public function testLeastPulledIsCached(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame($view->rows('least'), $view->rows('least'));
    }

    public function testByImageAggregatesByNormalisedName(): void
    {
        $view = $this->makeReport()->asView();
        $rows = $view->rows('by_image');

        // nginx: 42, redis: 18 — sorted descending
        self::assertCount(2, $rows);
        self::assertStringContainsString('42', $rows[0]['label']);
        self::assertStringContainsString('18', $rows[1]['label']);
    }

    public function testByImageIsCached(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame($view->rows('by_image'), $view->rows('by_image'));
    }

    public function testTopUsersAggregatesByUsername(): void
    {
        $view = $this->makeReport()->asView();
        $rows = $view->rows('top_users');

        // alice: 52, bob: 8 — sorted descending
        self::assertCount(2, $rows);
        self::assertStringContainsString('alice', $rows[0]['label']);
        self::assertStringContainsString('52', $rows[0]['label']);
    }

    public function testTopUsersIsCached(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame($view->rows('top_users'), $view->rows('top_users'));
    }

    public function testTotalContainsCorrectMetrics(): void
    {
        $view = $this->makeReport()->asView();
        $rows = $view->rows('total');

        self::assertCount(3, $rows);
        self::assertStringContainsString('Total pulls', $rows[0]['label']);
        self::assertStringContainsString('60', $rows[0]['label']);
        self::assertStringContainsString('Unique users', $rows[1]['label']);
        self::assertStringContainsString('2', $rows[1]['label']);
        self::assertStringContainsString('Unique images', $rows[2]['label']);
        self::assertStringContainsString('2', $rows[2]['label']);
    }

    public function testByImageNormalisesDigestImageNames(): void
    {
        $report = new UserReport([
            new UserReportRow('alice', 'nginx', 'latest', 10),
            new UserReportRow('bob', 'nginx@sha256:abcdef1234567890', 'latest', 5),
        ]);
        $view = $report->asView();
        $rows = $view->rows('by_image');

        // Both rows refer to 'nginx' after @ stripping — should aggregate into one entry
        self::assertCount(1, $rows);
        self::assertStringContainsString('nginx', $rows[0]['label']);
        self::assertStringContainsString('15', $rows[0]['label']);
    }

    public function testTotalIsCached(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame($view->rows('total'), $view->rows('total'));
    }

    public function testUnknownTabReturnsEmpty(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame([], $view->rows('nonexistent'));
    }

    public function testFormatRowNonShaTagHasEmptyValue(): void
    {
        $view = $this->makeReport()->asView();
        $row = new UserReportRow('alice', 'nginx', 'latest', 1);

        $formatted = $view->formatRow($row);

        self::assertSame('', $formatted['value']);
        self::assertStringContainsString('alice', $formatted['label']);
        self::assertStringContainsString('latest', $formatted['label']);
    }

    public function testFormatRowShaTagIsStoredInValueAndTruncatedInLabel(): void
    {
        $view = $this->makeReport()->asView();
        $sha = 'abcdef1234567890abcdef1234567890';
        $row = new UserReportRow('alice', 'nginx', $sha, 1);

        $formatted = $view->formatRow($row);

        self::assertSame($sha, $formatted['value']);
        self::assertStringContainsString('abcd...7890', $formatted['label']);
        self::assertStringNotContainsString($sha, $formatted['label']);
    }
}
