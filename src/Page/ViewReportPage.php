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
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
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

        $tabItems = array_map(
            fn (string $key, array $view) => ['value' => $key, 'label' => $view['label']],
            array_keys($views),
            array_values($views),
        );

        $tabWidget = new SelectListWidget(items: $tabItems, maxVisible: count($tabItems));

        $headerWidget = new TextWidget($views[$firstKey]['header']);
        $headerWidget->addStyleClass('hint');

        $dataContainer = new ContainerWidget();
        $dataContainer->setStyle(new Style(direction: Direction::Vertical));

        $initialRows = $views[$firstKey]['rows'];
        $dataList = new SelectListWidget(
            items: array_map(
                fn (string $line) => ['value' => $line, 'label' => $line],
                $initialRows,
            ),
            maxVisible: 15,
        );
        $dataContainer->add($dataList);

        $hint = new TextWidget(sprintf('[%d rows]  ↑↓ to scroll  Ctrl+B: back', count($initialRows)));
        $hint->addStyleClass('hint');

        $container->add($title);
        $container->add($tabWidget);
        $container->add($headerWidget);
        $container->add($dataContainer);
        $container->add($hint);

        $tabWidget->onSelect(function (SelectEvent $event) use (
            $tabWidget,
            $views,
            $headerWidget,
            $dataContainer,
            $hint,
            $navigator,
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
            $dataContainer->clear();
            $rows = $view['rows'];
            $dataContainer->add(new SelectListWidget(
                items: array_map(
                    fn (string $line) => ['value' => $line, 'label' => $line],
                    $rows,
                ),
                maxVisible: 15,
            ));
            $hint->setText(sprintf('[%d rows]  ↑↓ to scroll  Ctrl+B: back', count($rows)));
            $navigator->requestPageRender();
        });

        $keybindings = new Keybindings(['back' => ['ctrl+b']]);
        $navigator->listen(function (InputEvent $event) use ($keybindings, $navigator): void {
            if ($keybindings->matches($event->getData(), 'back')) {
                $navigator->navigateTo(new SelectCsvPage($this->reader, $this->context, $this->handler));
                $event->stopPropagation();
            }
        });

        return $container;
    }

    /**
     * @return array<string, array{label: string, header: string, rows: list<string>}>
     */
    private function computeViews(): array
    {
        if ($this->report instanceof ImageReport) {
            return $this->computeImageViews($this->report);
        }

        return $this->computeUserViews($this->report);
    }

    /**
     * @return array<string, array{label: string, header: string, rows: list<string>}>
     */
    private function computeImageViews(ImageReport $report): array
    {
        $allRows = $report->rows;

        $leastPulled = $allRows;
        usort($leastPulled, fn (ImageReportRow $a, ImageReportRow $b) => $a->pullCount <=> $b->pullCount);

        $byImageTotals = [];
        foreach ($allRows as $row) {
            $byImageTotals[$row->image] = ($byImageTotals[$row->image] ?? 0) + $row->pullCount;
        }
        arsort($byImageTotals);

        $imageHeader = sprintf('%-40s  %-20s  %6s', 'Image', 'Tag', 'Pulls');
        $byImageHeader = sprintf('%-40s  %6s', 'Image', 'Pulls');

        return [
            'all' => [
                'label' => 'All rows',
                'header' => $imageHeader,
                'rows' => array_map(
                    fn (ImageReportRow $r) => sprintf('%-40s  %-20s  %6d', $r->image, $r->tag, $r->pullCount),
                    $allRows,
                ),
            ],
            'least' => [
                'label' => 'Least pulled',
                'header' => $imageHeader,
                'rows' => array_map(
                    fn (ImageReportRow $r) => sprintf('%-40s  %-20s  %6d', $r->image, $r->tag, $r->pullCount),
                    $leastPulled,
                ),
            ],
            'by_image' => [
                'label' => 'By image',
                'header' => $byImageHeader,
                'rows' => array_map(
                    fn (string $image, int $pulls) => sprintf('%-40s  %6d', $image, $pulls),
                    array_keys($byImageTotals),
                    array_values($byImageTotals),
                ),
            ],
        ];
    }

    /**
     * @return array<string, array{label: string, header: string, rows: list<string>}>
     */
    private function computeUserViews(UserReport $report): array
    {
        $allRows = $report->rows;

        $leastPulled = $allRows;
        usort($leastPulled, fn (UserReportRow $a, UserReportRow $b) => $a->pullCount <=> $b->pullCount);

        $byImageTotals = [];
        foreach ($allRows as $row) {
            $byImageTotals[$row->image] = ($byImageTotals[$row->image] ?? 0) + $row->pullCount;
        }
        arsort($byImageTotals);

        $topUserTotals = [];
        foreach ($allRows as $row) {
            $topUserTotals[$row->username] = ($topUserTotals[$row->username] ?? 0) + $row->pullCount;
        }
        arsort($topUserTotals);

        $userHeader = sprintf('%-20s  %-35s  %-15s  %6s', 'User', 'Image', 'Tag', 'Pulls');
        $byImageHeader = sprintf('%-40s  %6s', 'Image', 'Pulls');
        $topUserHeader = sprintf('%-20s  %6s', 'User', 'Pulls');

        return [
            'all' => [
                'label' => 'All rows',
                'header' => $userHeader,
                'rows' => array_map(
                    fn (UserReportRow $r) => sprintf('%-20s  %-35s  %-15s  %6d', $r->username, $r->image, $r->tag, $r->pullCount),
                    $allRows,
                ),
            ],
            'least' => [
                'label' => 'Least pulled',
                'header' => $userHeader,
                'rows' => array_map(
                    fn (UserReportRow $r) => sprintf('%-20s  %-35s  %-15s  %6d', $r->username, $r->image, $r->tag, $r->pullCount),
                    $leastPulled,
                ),
            ],
            'by_image' => [
                'label' => 'By image',
                'header' => $byImageHeader,
                'rows' => array_map(
                    fn (string $image, int $pulls) => sprintf('%-40s  %6d', $image, $pulls),
                    array_keys($byImageTotals),
                    array_values($byImageTotals),
                ),
            ],
            'top_users' => [
                'label' => 'Top users',
                'header' => $topUserHeader,
                'rows' => array_map(
                    fn (string $user, int $pulls) => sprintf('%-20s  %6d', $user, $pulls),
                    array_keys($topUserTotals),
                    array_values($topUserTotals),
                ),
            ],
        ];
    }
}
