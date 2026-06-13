<?php

namespace Porthole\Tests\Report;

use PHPUnit\Framework\TestCase;
use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportRow;

final class ImageReportViewTest extends TestCase
{
    private function makeReport(): ImageReport
    {
        return new ImageReport([
            new ImageReportRow('nginx', 'latest', 42),
            new ImageReportRow('nginx', '1.25', 5),
            new ImageReportRow('redis', '7.0', 8),
        ]);
    }

    public function testTabDefinitionsReturnsFourTabs(): void
    {
        $view = $this->makeReport()->asView();
        $tabs = $view->tabDefinitions();

        self::assertCount(4, $tabs);
        self::assertArrayHasKey('all', $tabs);
        self::assertArrayHasKey('least', $tabs);
        self::assertArrayHasKey('by_image', $tabs);
        self::assertArrayHasKey('total', $tabs);
        self::assertArrayNotHasKey('top_users', $tabs);

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
        self::assertStringContainsString('5', $rows[0]['label']);
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

        // nginx: 47 (42+5), redis: 8 — sorted descending
        self::assertCount(2, $rows);
        self::assertStringContainsString('47', $rows[0]['label']);
        self::assertStringContainsString('8', $rows[1]['label']);
    }

    public function testByImageIsCached(): void
    {
        $view = $this->makeReport()->asView();

        self::assertSame($view->rows('by_image'), $view->rows('by_image'));
    }

    public function testByImageNormalisesDigestImageNames(): void
    {
        $report = new ImageReport([
            new ImageReportRow('nginx', 'latest', 10),
            new ImageReportRow('nginx@sha256:abcdef1234567890', 'latest', 5),
        ]);
        $view = $report->asView();
        $rows = $view->rows('by_image');

        // Both refer to 'nginx' after @ stripping — should aggregate into one entry
        self::assertCount(1, $rows);
        self::assertStringContainsString('nginx', $rows[0]['label']);
        self::assertStringContainsString('15', $rows[0]['label']);
    }

    public function testTotalContainsCorrectMetrics(): void
    {
        $view = $this->makeReport()->asView();
        $rows = $view->rows('total');

        self::assertCount(3, $rows);
        self::assertStringContainsString('Total pulls', $rows[0]['label']);
        self::assertStringContainsString('55', $rows[0]['label']);
        self::assertStringContainsString('Unique images', $rows[1]['label']);
        self::assertStringContainsString('2', $rows[1]['label']);
        self::assertStringContainsString('Unique tags', $rows[2]['label']);
        self::assertStringContainsString('3', $rows[2]['label']);
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
        $row = new ImageReportRow('nginx', 'latest', 42);

        $formatted = $view->formatRow($row);

        self::assertSame('', $formatted['value']);
        self::assertStringContainsString('nginx', $formatted['label']);
        self::assertStringContainsString('latest', $formatted['label']);
    }

    public function testFormatRowShaTagIsStoredInValueAndTruncatedInLabel(): void
    {
        $view = $this->makeReport()->asView();
        $sha = 'abcdef1234567890abcdef1234567890';
        $row = new ImageReportRow('nginx', $sha, 1);

        $formatted = $view->formatRow($row);

        self::assertSame($sha, $formatted['value']);
        self::assertStringContainsString('abcd...7890', $formatted['label']);
        self::assertStringNotContainsString($sha, $formatted['label']);
    }
}
