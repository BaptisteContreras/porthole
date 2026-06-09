<?php

namespace Porthole\Command;

use Porthole\Page\CredentialsPage;
use Porthole\Result\CsvReader;
use Porthole\Tui\Navigator;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class StartCommand extends Command
{
    public function __construct(
        private readonly GenerateReportHandler $handler,
        private readonly CsvReader $reader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('start');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stylesheet = new StyleSheet();
        $stylesheet->addRule('.page', new Style(padding: Padding::all(2)));
        $stylesheet->addRule('.input', new Style(border: Border::all(1, 'rounded', 'cyan')));
        $stylesheet->addRule('.hint', new Style(color: 'gray'));
        $stylesheet->addRule('.summary', new Style(
            border: Border::all(1, 'rounded', 'cyan'),
            padding: Padding::all(1),
        ));

        $root = new ContainerWidget();
        $root->setStyle(new Style(direction: Direction::Vertical));

        $tui = new Tui(styleSheet: $stylesheet);
        $tui->add($root);

        $tui->addListener(function (CancelEvent $event) use ($tui): void {
            $tui->stop();
        });

        $navigator = new Navigator($tui, $root);
        $navigator->navigateTo(new CredentialsPage($this->handler, reader: $this->reader));

        $tui->run();

        return Command::SUCCESS;
    }
}
