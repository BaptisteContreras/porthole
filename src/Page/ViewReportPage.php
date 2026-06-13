<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Report\ImageReport;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Porthole\Report\UserReportView;
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

        $view = $this->report->asView();
        $tabDefs = $view->tabDefinitions();
        $firstKey = (string) array_key_first($tabDefs);

        $tabItems = array_map(
            fn (string $key, array $def) => ['value' => $key, 'label' => $def['label']],
            array_keys($tabDefs),
            array_values($tabDefs),
        );

        $tabWidget = new SelectListWidget(items: $tabItems, maxVisible: count($tabItems));

        $headerWidget = new TextWidget($tabDefs[$firstKey]['header']);
        $headerWidget->addStyleClass('hint');

        $dataContainer = new ContainerWidget();
        $dataContainer->setStyle(new Style(direction: Direction::Vertical));

        $shaDetail = new TextWidget('');
        $shaDetail->addStyleClass('hint');

        $hintText = static fn (int $count): string => sprintf('[%d rows]  Tab: switch focus  ↑↓ to scroll  Ctrl+B: back', $count);

        $hint = new TextWidget($hintText(count($view->rows($firstKey))));
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

        $userView = $view instanceof UserReportView ? $view : null;
        $allUserRows = $this->report instanceof UserReport ? $this->report->rows : [];

        $activateAllUserTab = function () use (
            $userView,
            $allUserRows,
            $filterContainer,
            $dataContainer,
            $shaDetail,
            $hint,
            $navigator,
            $buildDataList,
            $hintText,
        ): void {
            if (null === $userView) {
                return;
            }
            $filterContainer->clear();
            $dataContainer->clear();
            $shaDetail->setText('');

            $dataList = $buildDataList($userView->rows('all'));
            $dataContainer->add($dataList);

            $filterInput = new InputWidget();
            $filterInput->setPrompt('Filter user: > ');
            $filterInput->onChange(function (ChangeEvent $event) use ($dataList, $allUserRows, $hint, $navigator, $hintText, $userView): void {
                $items = $this->filterUserRows($event->getValue(), $allUserRows, $userView);
                $dataList->setItems($items);
                $hint->setText($hintText(count($items)));
                $navigator->requestPageRender();
            });
            $filterContainer->add($filterInput);

            $hint->setText($hintText(count($userView->rows('all'))));
        };

        if ($this->report instanceof UserReport) {
            $activateAllUserTab();
        } else {
            $dataContainer->add($buildDataList($view->rows($firstKey)));
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
            $tabDefs,
            $view,
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
            $tabDef = $tabDefs[$item['value']] ?? null;
            if (null === $tabDef) {
                return;
            }

            $headerWidget->setText($tabDef['header']);

            if ('all' === $item['value'] && $this->report instanceof UserReport) {
                $activateAllUserTab();
            } else {
                $filterContainer->clear();
                $shaDetail->setText('');
                $dataContainer->clear();
                $rows = $view->rows($item['value']);
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
     * @param list<UserReportRow> $rawRows
     *
     * @return list<array{value: string, label: string}>
     */
    private function filterUserRows(string $prefix, array $rawRows, UserReportView $view): array
    {
        $prefix = strtolower($prefix);
        if ('' === $prefix) {
            return $view->rows('all');
        }
        // prefix-only match by design: substring search would make bob_alice match 'alice'
        $filtered = array_values(array_filter(
            $rawRows,
            static fn (UserReportRow $r) => str_starts_with(strtolower($r->username), $prefix),
        ));

        return array_map($view->formatRow(...), $filtered);
    }
}
