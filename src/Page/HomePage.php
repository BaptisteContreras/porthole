<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class HomePage implements PageInterface
{
    public function __construct(
        private readonly HarborContext $context,
        private readonly GenerateReportHandler $handler,
    ) {
    }

    public function mount(Navigator $navigator): ContainerWidget
    {
        $title = new TextWidget('Porthole');
        $title->addStyleClass('font-big text-cyan-400 bold');

        $hint = new TextWidget('Select a use case, Ctrl+C to exit');
        $hint->addStyleClass('hint');

        $menuWidget = new SelectListWidget(
            items: [
                ['value' => 'build_report', 'label' => 'Build report', 'description' => 'generate a CSV pull activity report'],
                ['value' => 'view_report', 'label' => 'View report', 'description' => 'coming soon'],
                ['value' => 'credentials', 'label' => 'Change credentials', 'description' => 'update Harbor connection settings'],
            ],
            maxVisible: 3,
        );

        $menuWidget->onSelect(function (SelectEvent $event) use ($navigator, $menuWidget, $hint): void {
            $item = $menuWidget->getSelectedItem();
            if (null === $item) {
                return;
            }

            if ('build_report' === $item['value']) {
                $navigator->navigateTo(new BuildReportPage($this->context, $this->handler));

                return;
            }

            if ('credentials' === $item['value']) {
                $navigator->navigateTo(new CredentialsPage($this->handler, $this->context));

                return;
            }

            $hint->setText('View report is not yet implemented.');
        });

        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->addStyleClass('page');
        $container->add($title);
        $container->add($menuWidget);
        $container->add($hint);

        return $container;
    }
}
