<?php

namespace Porthole\Page;

use Porthole\Background\BackgroundProcess;
use Porthole\Background\Event\BackgroundTaskCompletedEvent;
use Porthole\Background\Event\BackgroundTaskFailedEvent;
use Porthole\Background\Event\BackgroundTaskProgressEvent;
use Porthole\Harbor\HarborContext;
use Porthole\Result\CsvReader;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportCommand as GenerateReportUseCase;
use Porthole\UseCase\GenerateReportHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class BuildReportPage implements PageInterface
{
    private const string MODE_IMAGES = 'images';
    private const string MODE_USERS = 'users';

    private ?BackgroundProcess $backgroundProcess = null;

    public function __construct(
        private readonly HarborContext $context,
        private readonly GenerateReportHandler $handler,
        private readonly ?CsvReader $reader = null,
    ) {
    }

    public function mount(Navigator $navigator): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $container->addStyleClass('page');

        $title = new TextWidget('Build report');
        $title->addStyleClass('font-big text-cyan-400 bold');

        $modeWidget = new SelectListWidget(
            items: [
                ['value' => self::MODE_IMAGES, 'label' => self::MODE_IMAGES, 'description' => 'pulls per image/tag'],
                ['value' => self::MODE_USERS, 'label' => self::MODE_USERS, 'description' => 'pulls per user'],
            ],
            maxVisible: 2,
        );

        $outputInput = new InputWidget();
        $outputInput->setValue('./report.csv');
        $outputInput->addStyleClass('input');

        $fromInput = new InputWidget();
        $fromInput->addStyleClass('input');

        $toInput = new InputWidget();
        $toInput->addStyleClass('input');

        $hint = new TextWidget('Press Enter to generate, Ctrl+B: back, Ctrl+C to exit');
        $hint->addStyleClass('hint');

        $container->add($title);
        $container->add(new TextWidget('Report mode'));
        $container->add($modeWidget);
        $container->add(new TextWidget('Output file'));
        $container->add($outputInput);
        $container->add(new TextWidget('From date (optional, YYYY-MM-DD)'));
        $container->add($fromInput);
        $container->add(new TextWidget('To date (optional, YYYY-MM-DD)'));
        $container->add($toInput);
        $container->add($hint);

        $keybindings = new Keybindings([
            'submit' => ['enter'],
            'next' => [Key::TAB],
            'previous' => ['shift+tab'],
            'back' => ['ctrl+b'],
        ]);

        $phase = 'form';

        $navigator->listen(function (InputEvent $event) use (
            $keybindings,
            $navigator,
            $container,
            $hint,
            $modeWidget,
            $outputInput,
            $fromInput,
            $toInput,
            &$phase,
        ): void {
            $data = $event->getData();

            if ('finished' === $phase) {
                if ($keybindings->matches($data, 'submit')) {
                    $navigator->navigateTo(new HomePage($this->context, $this->handler, $this->reader));
                    $event->stopPropagation();
                }

                return;
            }

            if ('running' === $phase) {
                if ($keybindings->matches($data, 'back')) {
                    $this->backgroundProcess?->kill();
                    $navigator->navigateTo(new HomePage($this->context, $this->handler, $this->reader));
                    $event->stopPropagation();

                    return;
                }
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'back')) {
                $navigator->navigateTo(new HomePage($this->context, $this->handler, $this->reader));
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'next')) {
                $navigator->getTui()->getFocusManager()->focusNext();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'previous')) {
                $navigator->getTui()->getFocusManager()->focusPrevious();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'submit')) {
                $outputPath = $outputInput->getValue();
                if ('' === $outputPath) {
                    $hint->setText('Output file is required.');
                    $event->stopPropagation();

                    return;
                }

                $fromValue = $fromInput->getValue();
                $toValue = $toInput->getValue();

                try {
                    $from = '' !== $fromValue ? new \DateTimeImmutable($fromValue) : null;
                    $to = '' !== $toValue ? new \DateTimeImmutable($toValue) : null;
                } catch (\Exception $e) {
                    $hint->setText(sprintf('Invalid date: %s', $e->getMessage()));
                    $event->stopPropagation();

                    return;
                }

                $selected = $modeWidget->getSelectedItem();
                $mode = ($selected['value'] ?? null) ?? self::MODE_IMAGES;
                $command = new GenerateReportUseCase(
                    harborUrl: $this->context->url,
                    token: $this->context->token,
                    username: $this->context->username,
                    mode: $mode,
                    from: $from,
                    to: $to,
                    outputPath: $outputPath,
                    verifySsl: $this->context->verifySsl,
                );

                $phase = 'running';
                $this->startProgress($container, $command, $navigator, static function () use (&$phase): void {
                    $phase = 'finished';
                });
                $event->stopPropagation();
            }
        });

        return $container;
    }

    private function startProgress(
        ContainerWidget $container,
        GenerateReportUseCase $command,
        Navigator $navigator,
        \Closure $onFinished,
    ): void {
        $container->clear();

        $title = new TextWidget('Build report');
        $title->addStyleClass('font-big text-cyan-400 bold');

        $steps = [
            'connect' => new TextWidget('[ ] Connecting to Harbor'),
            'fetch' => new TextWidget('[ ] Fetching audit log'),
            'build' => new TextWidget('[ ] Building report'),
            'write' => new TextWidget('[ ] Writing CSV'),
        ];

        $stepsContainer = new ContainerWidget();
        $stepsContainer->setStyle(new Style(direction: Direction::Vertical));
        foreach ($steps as $step) {
            $stepsContainer->add($step);
        }

        $container->add($title);
        $container->add($stepsContainer);

        $startTime = microtime(true);
        $capturedPath = '';
        $capturedRows = 0;
        $connectedMarked = false;

        $dispatcher = new EventDispatcher();

        $dispatcher->addListener(
            BackgroundTaskProgressEvent::class,
            function (BackgroundTaskProgressEvent $e) use (
                $steps,
                $navigator,
                &$connectedMarked,
                &$capturedPath,
                &$capturedRows,
            ): void {
                $typeValue = $e->data['type'] ?? null;
                $type = is_string($typeValue) ? $typeValue : '';

                if ('page_fetched' === $type) {
                    if (!$connectedMarked) {
                        $connectedMarked = true;
                        $steps['connect']->setText('[✓] Connected to Harbor');
                    }
                    $rawPage = $e->data['page'] ?? 0;
                    $steps['fetch']->setText(sprintf('[⟳] Fetching audit log (page %d)', is_int($rawPage) ? $rawPage : 0));
                } elseif ('logs_fetched' === $type) {
                    $rawTotal = $e->data['total'] ?? 0;
                    $steps['fetch']->setText(sprintf('[✓] Fetched audit log (%d entries)', is_int($rawTotal) ? $rawTotal : 0));
                } elseif ('report_built' === $type) {
                    $steps['build']->setText('[✓] Built report');
                } elseif ('csv_written' === $type) {
                    $steps['write']->setText('[✓] Written CSV');
                    $pathValue = $e->data['path'] ?? null;
                    $capturedPath = is_string($pathValue) ? $pathValue : '';
                    $rawRows = $e->data['rows'] ?? 0;
                    $capturedRows = is_int($rawRows) ? $rawRows : 0;
                }

                $navigator->requestPageRender();
            }
        );

        $dispatcher->addListener(
            BackgroundTaskCompletedEvent::class,
            function () use ($container, $startTime, $onFinished, $navigator, &$capturedPath, &$capturedRows): void {
                $elapsed = round(microtime(true) - $startTime, 1);
                $summary = new ContainerWidget();
                $summary->setStyle(new Style(direction: Direction::Vertical, gap: 0));
                $summary->addStyleClass('summary');
                $summary->add(new TextWidget(sprintf('Output  %s', $capturedPath)));
                $summary->add(new TextWidget(sprintf('Rows    %s', number_format($capturedRows, 0, '.', ' '))));
                $summary->add(new TextWidget(sprintf('Time    %ss', $elapsed)));
                $summary->add(new TextWidget(''));
                $summary->add(new TextWidget('Press Enter to go back'));
                $container->add($summary);
                $onFinished();
                $navigator->requestPageRender();
            }
        );

        $dispatcher->addListener(
            BackgroundTaskFailedEvent::class,
            function (BackgroundTaskFailedEvent $e) use ($container, $onFinished, $navigator): void {
                $summary = new ContainerWidget();
                $summary->setStyle(new Style(direction: Direction::Vertical, gap: 0));
                $summary->addStyleClass('summary');
                $summary->add(new TextWidget(sprintf('Error: %s', $e->message)));
                $summary->add(new TextWidget(''));
                $summary->add(new TextWidget('Press Enter to go back'));
                $container->add($summary);
                $onFinished();
                $navigator->requestPageRender();
            }
        );

        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $this->backgroundProcess = new BackgroundProcess(
            command: [PHP_BINARY, is_string($scriptFilename) ? $scriptFilename : '', 'generate-report:worker'],
            dispatcher: $dispatcher,
            timeoutSeconds: 300,
        );

        $this->backgroundProcess->start([
            'harborUrl' => $command->harborUrl,
            'token' => $command->token,
            'username' => $command->username,
            'mode' => $command->mode,
            'from' => $command->from?->format('Y-m-d'),
            'to' => $command->to?->format('Y-m-d'),
            'outputPath' => $command->outputPath,
            'verifySsl' => $command->verifySsl,
        ]);

        // Show connecting spinner immediately — before first page_fetched arrives
        $steps['connect']->setText('[⟳] Connecting to Harbor');
        $navigator->requestPageRender();
    }
}
