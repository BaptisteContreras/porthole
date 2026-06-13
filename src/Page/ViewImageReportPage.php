<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportView;
use Porthole\Result\CsvReader;
use Porthole\UseCase\GenerateReportHandler;

final class ViewImageReportPage extends AbstractViewReportPage
{
    public function __construct(
        private readonly ImageReport $report,
        CsvReader $reader,
        HarborContext $context,
        GenerateReportHandler $handler,
    ) {
        parent::__construct($reader, $context, $handler);
    }

    protected function buildView(): ImageReportView
    {
        return $this->report->asView();
    }
}
