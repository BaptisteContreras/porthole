<?php

namespace Porthole\Page;

use Porthole\Harbor\HarborContext;
use Porthole\Result\CsvReader;
use Porthole\Result\InvalidReportFileException;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class SelectCsvPage implements PageInterface
{
    public function __construct(
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

        $pathInput = new InputWidget();
        $pathInput->setValue('./report.csv');
        $pathInput->addStyleClass('input');

        $hint = new TextWidget('Enter: open  Ctrl+B: back  Ctrl+C: exit');
        $hint->addStyleClass('hint');

        $container->add($title);
        $container->add(new TextWidget('CSV file path'));
        $container->add($pathInput);
        $container->add($hint);

        $keybindings = new Keybindings([
            'submit' => ['enter'],
            'back' => ['ctrl+b'],
        ]);

        $navigator->listen(function (InputEvent $event) use ($keybindings, $navigator, $pathInput, $hint): void {
            $data = $event->getData();

            if ($keybindings->matches($data, 'back')) {
                $navigator->navigateTo(new HomePage($this->context, $this->handler, $this->reader));
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'submit')) {
                $path = $pathInput->getValue();

                if ('' === $path) {
                    $hint->setText('CSV file path is required.');
                    $event->stopPropagation();

                    return;
                }

                try {
                    $report = $this->reader->read($path);
                } catch (InvalidReportFileException $e) {
                    $hint->setText($e->getMessage());
                    $event->stopPropagation();
                    $navigator->requestPageRender();

                    return;
                } catch (\RuntimeException $e) {
                    $hint->setText($e->getMessage());
                    $event->stopPropagation();
                    $navigator->requestPageRender();

                    return;
                }

                $navigator->navigateTo(new ViewReportPage($report, $this->reader, $this->context, $this->handler));
                $event->stopPropagation();
            }
        });

        return $container;
    }
}
