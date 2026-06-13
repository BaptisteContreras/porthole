<?php

namespace Porthole\Report;

final class ImageReportView
{
    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedAllRows = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedLeastPulled = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedByImage = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedTotal = null;

    public function __construct(private readonly ImageReport $report)
    {
    }

    /**
     * @return array<string, array{label: string, header: string}>
     */
    public function tabDefinitions(): array
    {
        $imageHeader = sprintf('%-40s  %-11s  %6s', 'Image', 'Tag', 'Pulls');
        $byImageHeader = sprintf('%-40s  %6s', 'Image', 'Pulls');
        $totalHeader = sprintf('%-20s  %6s', 'Metric', 'Value');

        return [
            'all' => ['label' => 'All rows', 'header' => $imageHeader],
            'least' => ['label' => 'Least pulled', 'header' => $imageHeader],
            'by_image' => ['label' => 'By image', 'header' => $byImageHeader],
            'total' => ['label' => 'Total', 'header' => $totalHeader],
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function rows(string $tab): array
    {
        return match ($tab) {
            'all' => $this->allRows(),
            'least' => $this->leastPulled(),
            'by_image' => $this->byImage(),
            'total' => $this->total(),
            default => [],
        };
    }

    /**
     * @return array{value: string, label: string}
     */
    public function formatRow(ImageReportRow $r): array
    {
        return [
            'value' => $this->isSha($r->tag) ? $r->tag : '',
            'label' => sprintf('%-40s  %-11s  %6d', $r->image, $this->truncateSha($r->tag), $r->pullCount),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function allRows(): array
    {
        return $this->cachedAllRows ??= array_map($this->formatRow(...), $this->report->rows);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function leastPulled(): array
    {
        if (null !== $this->cachedLeastPulled) {
            return $this->cachedLeastPulled;
        }
        $rows = $this->report->rows;
        usort($rows, fn (ImageReportRow $a, ImageReportRow $b) => $a->pullCount <=> $b->pullCount);

        return $this->cachedLeastPulled = array_map($this->formatRow(...), $rows);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function byImage(): array
    {
        if (null !== $this->cachedByImage) {
            return $this->cachedByImage;
        }
        $totals = [];
        foreach ($this->report->rows as $row) {
            $key = $this->normalizeImageName($row->image);
            $totals[$key] = ($totals[$key] ?? 0) + $row->pullCount;
        }
        arsort($totals);

        return $this->cachedByImage = array_map(
            static fn (string $image, int $pulls) => [
                'value' => '',
                'label' => sprintf('%-40s  %6d', $image, $pulls),
            ],
            array_keys($totals),
            array_values($totals),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function total(): array
    {
        if (null !== $this->cachedTotal) {
            return $this->cachedTotal;
        }
        $totalPulls = 0;
        $uniqueImages = [];
        foreach ($this->report->rows as $row) {
            $totalPulls += $row->pullCount;
            $uniqueImages[$this->normalizeImageName($row->image)] = true;
        }

        return $this->cachedTotal = [
            ['value' => '', 'label' => sprintf('%-20s  %6d', 'Total pulls', $totalPulls)],
            ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique images', count($uniqueImages))],
            ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique tags', count($this->report->rows))],
        ];
    }

    private function isSha(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{16,}$/i', $value);
    }

    private function truncateSha(string $value): string
    {
        if ($this->isSha($value)) {
            return substr($value, 0, 4).'...'.substr($value, -4);
        }

        return $value;
    }

    private function normalizeImageName(string $image): string
    {
        return explode('@', $image)[0];
    }
}
