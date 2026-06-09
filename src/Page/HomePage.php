<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Result\CsvReader;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Tui\Event\SelectEvent;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\TextAlign;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class HomePage implements PageInterface
{
    public function __construct(
        private readonly HarborContext $context,
        private readonly GenerateReportHandler $handler,
        private readonly ?CsvReader $reader = null,
    ) {
    }

    public function mount(Navigator $navigator): ContainerWidget
    {
        $logo = new TextWidget($this->buildLogo());
        $logo->setStyle(new Style(textAlign: TextAlign::Center));

        $title = new TextWidget('PORTHOLE');
        $title->addStyleClass('font-big text-cyan-400 bold');
        $title->setStyle(new Style(textAlign: TextAlign::Center));

        $spacer = new TextWidget('');

        $hint = new TextWidget('Select a use case, Ctrl+C to exit');
        $hint->addStyleClass('hint');

        $menuWidget = new SelectListWidget(
            items: [
                ['value' => 'build_report', 'label' => 'Build report', 'description' => 'generate a CSV pull activity report'],
                ['value' => 'view_report', 'label' => 'View report', 'description' => 'open and explore a saved report'],
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
                $navigator->navigateTo(new BuildReportPage($this->context, $this->handler, $this->reader));

                return;
            }

            if ('credentials' === $item['value']) {
                $navigator->navigateTo(new CredentialsPage($this->handler, $this->context, $this->reader));

                return;
            }

            if ('view_report' === $item['value']) {
                if (null === $this->reader) {
                    $hint->setText('View report is not available.');

                    return;
                }
                $navigator->navigateTo(new SelectCsvPage($this->reader, $this->context, $this->handler));

                return;
            }
        });

        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->addStyleClass('page');
        $container->add($logo);
        $container->add($title);
        $container->add($spacer);
        $container->add($menuWidget);
        $container->add($hint);

        return $container;
    }

    private function buildLogo(): string
    {
        $r = "\e[0m";
        $bolt = "\e[38;2;91;125;138m";
        $rim = "\e[38;2;78;130;152m";
        $teal = "\e[38;2;46;196;182m";
        $blue = "\e[38;2;54;169;225m";
        $yell = "\e[38;2;255;183;3m";
        $wave = "\e[38;2;159;220;224m";

        $bt = $bolt.'◉'.$r;
        $vl = $rim.'│'.$r;
        $b1 = $teal.'▊'.$r;
        $b2 = $blue.'▊'.$r;
        $b3 = $teal.'▊'.$r;
        $b4 = $yell.'▇'.$r;

        return implode("\n", [
            '     '.$bt.'      '.$bt.'       '.$bt,
            '  '.$bt.'  '.$rim.'╭──────────────╮'.$r.'  '.$bt,
            '     '.$vl.'           '.$b4.'  '.$vl,
            '     '.$vl.'     '.$b2.'     '.$b4.'  '.$vl,
            $bt.'    '.$vl.'  '.$b1.'  '.$b2.'  '.$b3.'  '.$b4.'  '.$vl.'    '.$bt,
            '     '.$vl.'  '.$b1.'  '.$b2.'  '.$b3.'  '.$b4.'  '.$vl,
            '     '.$vl.' '.$wave.'≋≋≋≋≋≋≋≋≋≋≋≋'.$r.' '.$vl,
            '  '.$bt.'  '.$rim.'╰──────────────╯'.$r.'  '.$bt,
            '     '.$bt.'      '.$bt.'       '.$bt,
        ]);
    }
}
