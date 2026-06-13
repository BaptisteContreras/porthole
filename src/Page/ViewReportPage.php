<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportRow;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Porthole\Result\CsvReader;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class ViewReportPage implements PageInterface
{
    public function __construct(
        private readonly ImageReport|UserReport $report,
        private readonly CsvReader $reader,
        private readonly HarborContext $context,
        private readonly GenerateReportHandler $handler,
    ) {
    }

    public function mount(Navigator $navigator): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->addStyleClass('page');

        $title = new TextWidget('View report');
        $title->addStyleClass('font-big text-cyan-400 bold');

        $views = $this->computeViews();
        $firstKey = (string) array_key_first($views);

        $tabItems = [];
        $index = 0;
        foreach ($views as $key => $view) {
            $tabItems[$index] = ['value' => (string) $key, 'label' => (string) $view['label']];
            ++$index;
        }

        $tabWidget = new SelectListWidget(items: $tabItems, maxVisible: count($tabItems));

        $headerWidget = new TextWidget($views[$firstKey]['header']);
        $headerWidget->addStyleClass('hint');

        $dataContainer = new ContainerWidget();
        $dataContainer->setStyle(new Style(direction: Direction::Vertical));

        $shaDetail = new TextWidget('');
        $shaDetail->addStyleClass('hint');

        $hintText = static fn (int $count): string => sprintf('[%d rows]  Tab: switch focus  ↑↓ to scroll  Ctrl+B: back', $count);

        $hint = new TextWidget($hintText(count($views[$firstKey]['rows'])));
        $hint->addStyleClass('hint');

        $filterContainer = new ContainerWidget();

        $buildDataList = function (array $items) use ($shaDetail, $navigator): SelectListWidget {
            /** @phpstan-ignore-next-line argument.type */
            $list = new SelectListWidget(items: $items, maxVisible: 15);
            $list->onSelect(function (SelectEvent $event) use ($list, $shaDetail, $navigator): void {
                $item = $list->getSelectedItem();
                $sha = $item['value'] ?? '';
                $shaDetail->setText('' !== $sha ? 'SHA: '.$sha : '');
                $navigator->requestPageRender();
            });

            return $list;
        };

        $allUserRows = $this->report instanceof UserReport ? $this->report->rows : [];

        $activateAllUserTab = function () use (
            $allUserRows,
            $views,
            $filterContainer,
            $dataContainer,
            $shaDetail,
            $hint,
            $navigator,
            $buildDataList,
            $hintText,
        ): void {
            $allView = $views['all'] ?? null;
            if (null === $allView) {
                return;
            }
            $filterContainer->clear();
            $dataContainer->clear();
            $shaDetail->setText('');

            $dataList = $buildDataList($allView['rows']);
            $dataContainer->add($dataList);

            $filterInput = new InputWidget();
            $filterInput->setPrompt('Filter user: > ');
            $filterInput->onChange(function (ChangeEvent $event) use ($dataList, $allUserRows, $hint, $navigator, $hintText): void {
                $filter = strtolower($event->getValue());
                $filteredRows = '' === $filter
                    ? $allUserRows
                    // prefix-only match by design: substring search would make bob_alice match 'alice'
                    : array_values(array_filter(
                        $allUserRows,
                        static fn (UserReportRow $r) => str_starts_with(strtolower($r->username), $filter),
                    ));
                $items = array_map(
                    fn (UserReportRow $r) => [
                        'value' => $this->isSha($r->tag) ? $r->tag : '',
                        'label' => sprintf('%-20s  %-35s  %-11s  %6d', $r->username, $r->image, $this->truncateSha($r->tag), $r->pullCount),
                    ],
                    $filteredRows,
                );
                $dataList->setItems($items);
                $hint->setText($hintText(count($items)));
                $navigator->requestPageRender();
            });
            $filterContainer->add($filterInput);

            $hint->setText($hintText(count($allView['rows'])));
        };

        if ($this->report instanceof UserReport && 'all' === $firstKey) {
            $activateAllUserTab();
        } else {
            $dataContainer->add($buildDataList($views[$firstKey]['rows']));
        }

        $container->add($title);
        $container->add($tabWidget);
        $container->add($filterContainer);
        $container->add($headerWidget);
        $container->add($dataContainer);
        $container->add($shaDetail);
        $container->add($hint);

        $tabWidget->onSelect(function (SelectEvent $event) use (
            $tabWidget,
            $views,
            $headerWidget,
            $dataContainer,
            $filterContainer,
            $shaDetail,
            $hint,
            $navigator,
            $buildDataList,
            $activateAllUserTab,
            $hintText,
        ): void {
            $item = $tabWidget->getSelectedItem();
            if (null === $item) {
                return;
            }
            $view = $views[$item['value']] ?? null;
            if (null === $view) {
                return;
            }

            $headerWidget->setText($view['header']);

            if ('all' === $item['value'] && $this->report instanceof UserReport) {
                $activateAllUserTab();
            } else {
                $filterContainer->clear();
                $shaDetail->setText('');
                $dataContainer->clear();
                $rows = $view['rows'];
                $dataContainer->add($buildDataList($rows));
                $hint->setText($hintText(count($rows)));
            }

            $navigator->requestPageRender();
        });

        $keybindings = new Keybindings(['back' => ['ctrl+b'], 'focus_next' => ['tab']]);
        $navigator->listen(function (InputEvent $event) use ($keybindings, $navigator): void {
            if ($keybindings->matches($event->getData(), 'back')) {
                $navigator->navigateTo(new SelectCsvPage($this->reader, $this->context, $this->handler));
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($event->getData(), 'focus_next')) {
                $navigator->focusNextVisibleWidget();
                $event->stopPropagation();
            }
        });

        return $container;
    }

    /**
     * @return array<string, array{label: string, header: string, rows: list<array{value: string, label: string}>}>
     */
    private function computeViews(): array
    {
        if ($this->report instanceof ImageReport) {
            return $this->computeImageViews($this->report);
        }

        return $this->computeUserViews($this->report);
    }

    private function normalizeImageName(string $image): string
    {
        return explode('@', $image)[0];
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

    /**
     * @return array<string, array{label: string, header: string, rows: list<array{value: string, label: string}>}>
     */
    private function computeImageViews(ImageReport $report): array
    {
        $allRows = $report->rows;

        $leastPulled = $allRows;
        usort($leastPulled, fn (ImageReportRow $a, ImageReportRow $b) => $a->pullCount <=> $b->pullCount);

        $byImageTotals = [];
        foreach ($allRows as $row) {
            $key = $this->normalizeImageName($row->image);
            $byImageTotals[$key] = ($byImageTotals[$key] ?? 0) + $row->pullCount;
        }
        arsort($byImageTotals);

        $totalPulls = array_sum(array_map(fn (ImageReportRow $r) => $r->pullCount, $allRows));

        $imageHeader = sprintf('%-40s  %-11s  %6s', 'Image', 'Tag', 'Pulls');
        $byImageHeader = sprintf('%-40s  %6s', 'Image', 'Pulls');
        $totalHeader = sprintf('%-20s  %6s', 'Metric', 'Value');

        return [
            'all' => [
                'label' => 'All rows',
                'header' => $imageHeader,
                'rows' => array_map(
                    fn (ImageReportRow $r) => [
                        'value' => $this->isSha($r->tag) ? $r->tag : '',
                        'label' => sprintf('%-40s  %-11s  %6d', $r->image, $this->truncateSha($r->tag), $r->pullCount),
                    ],
                    $allRows,
                ),
            ],
            'least' => [
                'label' => 'Least pulled',
                'header' => $imageHeader,
                'rows' => array_map(
                    fn (ImageReportRow $r) => [
                        'value' => $this->isSha($r->tag) ? $r->tag : '',
                        'label' => sprintf('%-40s  %-11s  %6d', $r->image, $this->truncateSha($r->tag), $r->pullCount),
                    ],
                    $leastPulled,
                ),
            ],
            'by_image' => [
                'label' => 'By image',
                'header' => $byImageHeader,
                'rows' => array_map(
                    fn (string $image, int $pulls) => [
                        'value' => '',
                        'label' => sprintf('%-40s  %6d', $image, $pulls),
                    ],
                    array_keys($byImageTotals),
                    array_values($byImageTotals),
                ),
            ],
            'total' => [
                'label' => 'Total',
                'header' => $totalHeader,
                'rows' => [
                    ['value' => '', 'label' => sprintf('%-20s  %6d', 'Total pulls', $totalPulls)],
                    ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique images', count($byImageTotals))],
                    ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique tags', count($allRows))],
                ],
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, header: string, rows: list<array{value: string, label: string}>}>
     */
    private function computeUserViews(UserReport $report): array
    {
        $allRows = $report->rows;

        $leastPulled = $allRows;
        usort($leastPulled, fn (UserReportRow $a, UserReportRow $b) => $a->pullCount <=> $b->pullCount);

        $byImageTotals = [];
        foreach ($allRows as $row) {
            $key = $this->normalizeImageName($row->image);
            $byImageTotals[$key] = ($byImageTotals[$key] ?? 0) + $row->pullCount;
        }
        arsort($byImageTotals);

        $topUserTotals = [];
        foreach ($allRows as $row) {
            $topUserTotals[$row->username] = ($topUserTotals[$row->username] ?? 0) + $row->pullCount;
        }
        arsort($topUserTotals);

        $totalPulls = array_sum(array_map(fn (UserReportRow $r) => $r->pullCount, $allRows));

        $userHeader = sprintf('%-20s  %-35s  %-11s  %6s', 'User', 'Image', 'Tag', 'Pulls');
        $byImageHeader = sprintf('%-40s  %6s', 'Image', 'Pulls');
        $topUserHeader = sprintf('%-20s  %6s', 'User', 'Pulls');
        $totalHeader = sprintf('%-20s  %6s', 'Metric', 'Value');

        return [
            'all' => [
                'label' => 'All rows',
                'header' => $userHeader,
                'rows' => array_map(
                    fn (UserReportRow $r) => [
                        'value' => $this->isSha($r->tag) ? $r->tag : '',
                        'label' => sprintf('%-20s  %-35s  %-11s  %6d', $r->username, $r->image, $this->truncateSha($r->tag), $r->pullCount),
                    ],
                    $allRows,
                ),
            ],
            'least' => [
                'label' => 'Least pulled',
                'header' => $userHeader,
                'rows' => array_map(
                    fn (UserReportRow $r) => [
                        'value' => $this->isSha($r->tag) ? $r->tag : '',
                        'label' => sprintf('%-20s  %-35s  %-11s  %6d', $r->username, $r->image, $this->truncateSha($r->tag), $r->pullCount),
                    ],
                    $leastPulled,
                ),
            ],
            'by_image' => [
                'label' => 'By image',
                'header' => $byImageHeader,
                'rows' => array_map(
                    fn (string $image, int $pulls) => [
                        'value' => '',
                        'label' => sprintf('%-40s  %6d', $image, $pulls),
                    ],
                    array_keys($byImageTotals),
                    array_values($byImageTotals),
                ),
            ],
            'top_users' => [
                'label' => 'Top users',
                'header' => $topUserHeader,
                'rows' => array_map(
                    fn (string $user, int $pulls) => [
                        'value' => '',
                        'label' => sprintf('%-20s  %6d', $user, $pulls),
                    ],
                    array_keys($topUserTotals),
                    array_values($topUserTotals),
                ),
            ],
            'total' => [
                'label' => 'Total',
                'header' => $totalHeader,
                'rows' => [
                    ['value' => '', 'label' => sprintf('%-20s  %6d', 'Total pulls', $totalPulls)],
                    ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique users', count($topUserTotals))],
                    ['value' => '', 'label' => sprintf('%-20s  %6d', 'Unique images', count($byImageTotals))],
                ],
            ],
        ];
    }
}
