<?php

namespace Porthole\Report;

final class UserReportView
{
    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedAllRows = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedLeastPulled = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedByImage = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedTopUsers = null;

    /** @var list<array{value: string, label: string}>|null */
    private ?array $cachedTotal = null;

    public function __construct(private readonly UserReport $report)
    {
    }

    /**
     * @return array<string, array{label: string, header: string}>
     */
    public function tabDefinitions(): array
    {
        $userHeader = sprintf('%-20s  %-35s  %-11s  %6s', 'User', 'Image', 'Tag', 'Pulls');
        $byImageHeader = sprintf('%-40s  %6s', 'Image', 'Pulls');
        $topUserHeader = sprintf('%-20s  %6s', 'User', 'Pulls');
        $totalHeader = sprintf('%-20s  %6s', 'Metric', 'Value');

        return [
            'all' => ['label' => 'All rows', 'header' => $userHeader],
            'least' => ['label' => 'Least pulled', 'header' => $userHeader],
            'by_image' => ['label' => 'By image', 'header' => $byImageHeader],
            'top_users' => ['label' => 'Top users', 'header' => $topUserHeader],
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
            'top_users' => $this->topUsers(),
            'total' => $this->total(),
            default => [],
        };
    }

    /**
     * @return array{value: string, label: string}
     */
    public function formatRow(UserReportRow $r): array
    {
        return [
            'value' => $this->isSha($r->tag) ? $r->tag : '',
            'label' => sprintf('%-20s  %-35s  %-11s  %6d', $r->username, $r->image, $this->truncateSha($r->tag), $r->pullCount),
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
        usort($rows, fn (UserReportRow $a, UserReportRow $b) => $a->pullCount <=> $b->pullCount);

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
    private function topUsers(): array
    {
        if (null !== $this->cachedTopUsers) {
            return $this->cachedTopUsers;
        }
        $totals = [];
        foreach ($this->report->rows as $row) {
            $totals[$row->username] = ($totals[$row->username] ?? 0) + $row->pullCount;
        }
        arsort($totals);

        return $this->cachedTopUsers = array_map(
            static fn (string $user, int $pulls) => [
                'value' => '',
                'label' => sprintf('%-20s  %6d', $user, $pulls),
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
        $uniqueUsers = [];
        foreach ($this->report->rows as $row) {
            $totalPulls += $row->pullCount;
            $uniqueImages[$this->normalizeImageName($row->image)] = true;
            $uniqueUsers[$row->username] = true;
        }

        return $this->cachedTotal = [
            ['value' => '', 'label' => sprintf('%-20s  %6d', 'Total pulls', $totalPulls)],
            ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique users', count($uniqueUsers))],
            ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique images', count($uniqueImages))],
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
