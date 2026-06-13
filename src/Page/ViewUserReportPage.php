<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Porthole\Report\UserReportView;
use Porthole\Result\CsvReader;
use Porthole\Tui\Navigator;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Tui\Event\ChangeEvent;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class ViewUserReportPage extends AbstractViewReportPage
{
    public function __construct(
        private readonly UserReport $report,
        CsvReader $reader,
        HarborContext $context,
        GenerateReportHandler $handler,
    ) {
        parent::__construct($reader, $context, $handler);
    }

    protected function buildView(): UserReportView
    {
        return $this->report->asView();
    }

    /**
     * @param \Closure(list<array{value: string, label: string}>): SelectListWidget $buildDataList
     * @param \Closure(int): string                                                 $hintText
     */
    protected function activateTab(
        string $tabKey,
        ContainerWidget $filterContainer,
        ContainerWidget $dataContainer,
        TextWidget $hint,
        TextWidget $shaDetail,
        Navigator $navigator,
        \Closure $buildDataList,
        \Closure $hintText,
    ): void {
        if ('all' !== $tabKey) {
            parent::activateTab($tabKey, $filterContainer, $dataContainer, $hint, $shaDetail, $navigator, $buildDataList, $hintText);

            return;
        }

        $view = $this->report->asView();

        $filterContainer->clear();
        $dataContainer->clear();
        $shaDetail->setText('');

        $dataList = $buildDataList($view->rows('all'));
        $dataContainer->add($dataList);

        $filterInput = new InputWidget();
        $filterInput->setPrompt('Filter user: > ');
        $filterInput->onChange(function (ChangeEvent $event) use ($dataList, $hint, $navigator, $hintText, $view): void {
            $items = $this->filterUserRows($event->getValue(), $view);
            $dataList->setItems($items);
            $hint->setText($hintText(count($items)));
            $navigator->requestPageRender();
        });
        $filterContainer->add($filterInput);

        $hint->setText($hintText(count($view->rows('all'))));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function filterUserRows(string $prefix, UserReportView $view): array
    {
        $prefix = strtolower($prefix);
        if ('' === $prefix) {
            return $view->rows('all');
        }
        // prefix-only match by design: substring search would make bob_alice match 'alice'
        $filtered = array_values(array_filter(
            $this->report->rows,
            static fn (UserReportRow $r) => str_starts_with(strtolower($r->username), $prefix),
        ));

        return array_map($view->formatRow(...), $filtered);
    }
}
