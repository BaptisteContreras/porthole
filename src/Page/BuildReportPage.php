<?php

namespace Porthole\Page;

use Porthole\Event\AuditLogPageFetchedEvent;
use Porthole\Event\AuditLogsFetchedEvent;
use Porthole\Event\CsvWrittenEvent;
use Porthole\Event\ReportBuiltEvent;
use Porthole\Harbor\HarborContext;
use Porthole\Result\CsvReader;
use Porthole\Tui\Navigator;
use Porthole\Tui\PageInterface;
use Porthole\UseCase\GenerateReportCommand as GenerateReportUseCase;
use Porthole\UseCase\GenerateReportHandler;
use Revolt\EventLoop;
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

        $hint = new TextWidget('Press Enter to generate, Ctrl+C to exit');
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
                $this->startProgress($container, $command, static function () use (&$phase): void {
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
        $capturedOutputPath = '';
        $capturedRowCount = 0;

        $dispatcher = new EventDispatcher();
        $connectedMarked = false;

        $dispatcher->addListener(
            AuditLogPageFetchedEvent::class,
            function (AuditLogPageFetchedEvent $e) use ($steps, &$connectedMarked): void {
                if (!$connectedMarked) {
                    $connectedMarked = true;
                    $steps['connect']->setText('[✓] Connected to Harbor');
                }
                $steps['fetch']->setText(sprintf('[⟳] Fetching audit log (page %d)', $e->page));
            }
        );
        $dispatcher->addListener(
            AuditLogsFetchedEvent::class,
            fn (AuditLogsFetchedEvent $e) => $steps['fetch']->setText(
                sprintf('[✓] Fetched audit log (%d entries)', $e->totalEntries)
            )
        );
        $dispatcher->addListener(
            ReportBuiltEvent::class,
            fn (ReportBuiltEvent $e) => $steps['build']->setText('[✓] Built report')
        );
        $dispatcher->addListener(
            CsvWrittenEvent::class,
            function (CsvWrittenEvent $e) use ($steps, &$capturedOutputPath, &$capturedRowCount): void {
                $steps['write']->setText('[✓] Written CSV');
                $capturedOutputPath = $e->outputPath;
                $capturedRowCount = $e->rowCount;
            }
        );

        EventLoop::defer(function () use (
            $steps,
            $command,
            $dispatcher,
            $container,
            $startTime,
            $onFinished,
            &$capturedOutputPath,
            &$capturedRowCount,
        ): void {
            $steps['connect']->setText('[⟳] Connecting to Harbor');

            try {
                $this->handler->handle($command, $dispatcher);

                EventLoop::defer(function () use (
                    $container,
                    $startTime,
                    $onFinished,
                    &$capturedOutputPath,
                    &$capturedRowCount,
                ): void {
                    $elapsed = round(microtime(true) - $startTime, 1);

                    $summary = new ContainerWidget();
                    $summary->setStyle(new Style(direction: Direction::Vertical, gap: 0));
                    $summary->addStyleClass('summary');
                    $summary->add(new TextWidget(sprintf('Output  %s', $capturedOutputPath)));
                    $summary->add(new TextWidget(sprintf('Rows    %s', number_format((int) $capturedRowCount, 0, '.', ' '))));
                    $summary->add(new TextWidget(sprintf('Time    %ss', $elapsed)));
                    $summary->add(new TextWidget(''));
                    $summary->add(new TextWidget('Press Enter to go back'));
                    $container->add($summary);

                    $onFinished();
                });
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '401') || str_contains($e->getMessage(), 'auth')) {
                    $steps['connect']->setText(sprintf('[✗] Connecting to Harbor — %s', $e->getMessage()));
                } else {
                    $steps['fetch']->setText(sprintf('[✗] Fetching audit log — %s', $e->getMessage()));
                    $steps['connect']->setText('[✓] Connected to Harbor');
                }

                $summary = new ContainerWidget();
                $summary->setStyle(new Style(direction: Direction::Vertical, gap: 0));
                $summary->addStyleClass('summary');
                $summary->add(new TextWidget(sprintf('Error: %s', $e->getMessage())));
                $summary->add(new TextWidget(''));
                $summary->add(new TextWidget('Press Enter to go back'));
                $container->add($summary);

                $onFinished();
            }
        });
    }
}
