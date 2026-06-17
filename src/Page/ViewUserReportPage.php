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

        $userFilter = '';
        $tagFilter = '';

        $filterInput = new InputWidget();
        $filterInput->setPrompt('Filter user: > ');
        $filterInput->onChange(function (ChangeEvent $event) use ($dataList, $hint, $navigator, $hintText, $view, &$userFilter, &$tagFilter): void {
            $userFilter = $event->getValue();
            $items = $this->filterRows($userFilter, $tagFilter, $view);
            $dataList->setItems($items);
            $hint->setText($hintText(count($items)));
            $navigator->requestPageRender();
        });
        $filterContainer->add($filterInput);

        $tagFilterInput = new InputWidget();
        $tagFilterInput->setPrompt('Filter tag: > ');
        $tagFilterInput->onChange(function (ChangeEvent $event) use ($dataList, $hint, $navigator, $hintText, $view, &$userFilter, &$tagFilter): void {
            $tagFilter = $event->getValue();
            $items = $this->filterRows($userFilter, $tagFilter, $view);
            $dataList->setItems($items);
            $hint->setText($hintText(count($items)));
            $navigator->requestPageRender();
        });
        $filterContainer->add($tagFilterInput);

        $hint->setText($hintText(count($view->rows('all'))));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function filterRows(string $userPrefix, string $tagPrefix, UserReportView $view): array
    {
        $userPrefix = strtolower($userPrefix);
        $tagPrefix = strtolower($tagPrefix);

        if ('' === $userPrefix && '' === $tagPrefix) {
            return $view->rows('all');
        }

        // prefix-only match by design: substring search would make bob_alice match 'alice'
        $filtered = array_values(array_filter(
            $this->report->rows,
            static fn (UserReportRow $r) => ('' === $userPrefix || str_starts_with(strtolower($r->username), $userPrefix))
                && ('' === $tagPrefix || str_starts_with(strtolower($r->tag), $tagPrefix)),
        ));

        return array_map($view->formatRow(...), $filtered);
    }
}
