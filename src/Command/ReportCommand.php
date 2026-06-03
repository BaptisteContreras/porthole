<?php

namespace Porthole\Command;

use Porthole\Harbor\HarborApiClient;
use Porthole\Report\ImageReport;
use Porthole\Report\ImageReportRow;
use Porthole\Report\ReportBuilder;
use Porthole\Report\UserReport;
use Porthole\Report\UserReportRow;
use Porthole\Result\CsvWriter;
use Revolt\EventLoop;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Tui\Event\CancelEvent;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Style\Border;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\InputWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ReportCommand extends Command
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly bool $interactive = true,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('report')
            ->addOption('harbor-url', null, InputOption::VALUE_REQUIRED, 'Harbor registry URL')
            ->addOption('harbor-token', null, InputOption::VALUE_REQUIRED, 'Harbor API token (falls back to $HARBOR_TOKEN env)')
            ->addOption('harbor-username', null, InputOption::VALUE_REQUIRED, 'Harbor username for Basic auth, e.g. robot account name (falls back to $HARBOR_USERNAME env)')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Report mode: images or users', 'images')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Start date (YYYY-MM-DD)')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'End date (YYYY-MM-DD)')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output CSV file path')
            ->addOption('no-verify-ssl', null, InputOption::VALUE_NONE, 'Disable SSL certificate verification (useful for self-signed certs)')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tokenOption = $input->getOption('harbor-token');
        $token = is_string($tokenOption) ? $tokenOption : (getenv('HARBOR_TOKEN') ?: null);
        if (null === $token) {
            $output->writeln('<error>HARBOR_TOKEN is not set. Provide --harbor-token or set the HARBOR_TOKEN environment variable.</error>');

            return Command::FAILURE;
        }

        $usernameOption = $input->getOption('harbor-username');
        $username = is_string($usernameOption) ? $usernameOption : (getenv('HARBOR_USERNAME') ?: null);

        $modeOption = $input->getOption('mode');
        $mode = is_string($modeOption) ? $modeOption : 'images';
        if (!in_array($mode, ['images', 'users'], true)) {
            $output->writeln(sprintf('<error>Invalid mode "%s". Valid modes are: images, users.</error>', $mode));

            return Command::FAILURE;
        }

        $harborUrlOption = $input->getOption('harbor-url');
        $harborUrl = is_string($harborUrlOption) ? $harborUrlOption : null;
        $outputPathOption = $input->getOption('output');
        $outputPath = is_string($outputPathOption) ? $outputPathOption : null;
        $fromOption = $input->getOption('from');
        $toOption = $input->getOption('to');

        $httpClient = $input->getOption('no-verify-ssl')
            ? $this->httpClient->withOptions(['verify_peer' => false, 'verify_host' => false])
            : $this->httpClient;

        try {
            $from = is_string($fromOption) ? new \DateTimeImmutable($fromOption) : null;
            $to = is_string($toOption) ? new \DateTimeImmutable($toOption) : null;
        } catch (\Exception $e) {
            $output->writeln(sprintf('<error>Invalid date format: %s. Use YYYY-MM-DD (e.g. 2025-01-01).</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        if (!$this->interactive) {
            if (null === $harborUrl || null === $outputPath) {
                $output->writeln('<error>--harbor-url and --output are required in non-interactive mode.</error>');

                return Command::FAILURE;
            }
            try {
                $entries = $this->fetchEntries($harborUrl, $token, $from, $to, null, $httpClient, $username);
                [$header, $csvRows] = $this->buildCsvData($entries, $mode);
                $this->writeCsv($outputPath, $header, $csvRows);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        }

        if (null === $harborUrl || null === $outputPath) {
            [$harborUrl, $outputPath] = $this->runFormPhase($harborUrl, $outputPath);
            if (null === $harborUrl || null === $outputPath) {
                return Command::FAILURE;
            }
        }

        return $this->runTuiPhase($harborUrl, $token, $outputPath, $mode, $from, $to, $httpClient, $username);
    }

    /**
     * Opens a TUI form for missing required fields.
     * Returns [harborUrl, outputPath], or [null, null] if the user cancelled.
     *
     * @return array{string|null, string|null}
     */
    private function runFormPhase(?string $harborUrl, ?string $outputPath): array
    {
        $cancelled = false;

        $stylesheet = new StyleSheet();
        $stylesheet->addRule('.form', new Style(padding: Padding::all(2)));
        $stylesheet->addRule('.input', new Style(border: Border::all(1, 'rounded', 'cyan')));
        $stylesheet->addRule('.hint', new Style(color: 'gray'));

        $titleWidget = new TextWidget('Porthole');
        $titleWidget->addStyleClass('font-big text-cyan-400 bold');

        $hintWidget = new TextWidget('Press Enter to confirm, Ctrl+C to exit');
        $hintWidget->addStyleClass('hint');

        $urlInput = null;
        $outputInput = null;

        $form = new ContainerWidget();
        $form->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $form->addStyleClass('form');
        $form->add($titleWidget);

        if (null === $harborUrl) {
            $form->add(new TextWidget('Harbor URL'));
            $urlInput = new InputWidget();
            $urlInput->setValue('https://');
            $urlInput->addStyleClass('input');
            $form->add($urlInput);
        }

        if (null === $outputPath) {
            $form->add(new TextWidget('Output file'));
            $outputInput = new InputWidget();
            $outputInput->setValue('./report.csv');
            $outputInput->addStyleClass('input');
            $form->add($outputInput);
        }

        $form->add($hintWidget);

        $keybindings = new Keybindings([
            'submit' => ['enter'],
            'next' => [Key::TAB],
            'previous' => ['shift+tab'],
        ]);

        $tui = new Tui(styleSheet: $stylesheet, keybindings: $keybindings);
        $tui->add($form);

        $tui->addListener(function (CancelEvent $event) use ($tui, &$cancelled) {
            $cancelled = true;
            $tui->stop();
        });

        $tui->addListener(function (InputEvent $event) use (
            $keybindings,
            $tui,
            $hintWidget,
            $urlInput,
            $outputInput,
            &$harborUrl,
            &$outputPath
        ) {
            $data = $event->getData();

            if ($keybindings->matches($data, 'next')) {
                $tui->getFocusManager()->focusNext();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'previous')) {
                $tui->getFocusManager()->focusPrevious();
                $event->stopPropagation();

                return;
            }

            if ($keybindings->matches($data, 'submit')) {
                $resolvedUrl = null !== $urlInput ? $urlInput->getValue() : $harborUrl;
                $resolvedOutput = null !== $outputInput ? $outputInput->getValue() : $outputPath;

                if ('' === $resolvedUrl || '' === $resolvedOutput) {
                    $hintWidget->setText('Required field is empty — please fill in all fields.');
                    $event->stopPropagation();

                    return;
                }

                $harborUrl = $resolvedUrl;
                $outputPath = $resolvedOutput;
                $tui->stop();
                $event->stopPropagation();
            }
        });

        $tui->run();

        if ($cancelled) {
            return [null, null];
        }

        return [$harborUrl, $outputPath];
    }

    private function runTuiPhase(
        string $harborUrl,
        string $token,
        string $outputPath,
        string $mode,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        HttpClientInterface $httpClient,
        ?string $username,
    ): int {
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

        $mainContainer = new ContainerWidget();
        $mainContainer->setStyle(new Style(direction: Direction::Vertical, gap: 1));
        $mainContainer->addStyleClass('container');
        $mainContainer->add(new TextWidget('Porthole'));
        $mainContainer->add($stepsContainer);

        $stylesheet = new StyleSheet();
        $stylesheet->addRule('.container', new Style(padding: Padding::all(2)));
        $stylesheet->addRule('.summary', new Style(
            border: Border::all(1, 'rounded', 'cyan'),
            padding: Padding::all(1),
        ));

        $tui = new Tui(styleSheet: $stylesheet);
        $tui->add($mainContainer);

        $exitCode = Command::SUCCESS;
        $startTime = microtime(true);
        $completed = false;

        $tui->addListener(function (CancelEvent $event) use ($tui, &$exitCode, &$completed) {
            // @phpstan-ignore booleanNot.alwaysTrue ($completed is mutated asynchronously via reference)
            if (!$completed) {
                $exitCode = Command::FAILURE;
            }
            $tui->stop();
        });

        // Phase 1: Connect (marks active, then defers the actual fetch)
        EventLoop::defer(function () use (
            $steps, $harborUrl, $token, $outputPath, $mode, $from, $to, $httpClient, $username,
            $mainContainer, $tui, $startTime, &$exitCode, &$completed
        ) {
            $steps['connect']->setText('[⟳] Connecting to Harbor');

            // Phase 2: Fetch
            EventLoop::defer(function () use (
                $steps, $harborUrl, $token, $outputPath, $mode, $from, $to, $httpClient, $username,
                $mainContainer, $tui, $startTime, &$exitCode, &$completed
            ) {
                try {
                    $page = 0;
                    $connectedMarked = false;

                    $entries = $this->fetchEntries(
                        $harborUrl,
                        $token,
                        $from,
                        $to,
                        function (int $p) use ($steps, &$page, &$connectedMarked) {
                            if (!$connectedMarked) {
                                $connectedMarked = true;
                                $steps['connect']->setText('[✓] Connected to Harbor');
                            }
                            $page = $p;
                            $steps['fetch']->setText(sprintf('[⟳] Fetching audit log (page %d)', $p));
                        },
                        $httpClient,
                        $username,
                    );

                    if (!$connectedMarked) {
                        $steps['connect']->setText('[✓] Connected to Harbor');
                    }
                    $steps['fetch']->setText(sprintf('[✓] Fetched audit log (%d entries)', count($entries)));

                    // Phase 3: Build
                    EventLoop::defer(function () use (
                        $steps, $entries, $outputPath, $mode,
                        $mainContainer, $tui, $startTime, &$exitCode, &$completed
                    ) {
                        $steps['build']->setText('[⟳] Building report');

                        try {
                            [$header, $csvRows] = $this->buildCsvData($entries, $mode);
                            $steps['build']->setText('[✓] Built report');

                            // Phase 4: Write
                            EventLoop::defer(function () use (
                                $steps, $header, $csvRows, $outputPath,
                                $mainContainer, $tui, $startTime, &$exitCode, &$completed
                            ) {
                                $steps['write']->setText('[⟳] Writing CSV');

                                try {
                                    $rowCount = $this->writeCsv($outputPath, $header, $csvRows);
                                    $steps['write']->setText('[✓] Written CSV');

                                    // Phase 5: Summary
                                    EventLoop::defer(function () use (
                                        $outputPath, $rowCount, $startTime,
                                        $mainContainer, $tui, &$completed
                                    ) {
                                        $elapsed = round(microtime(true) - $startTime, 1);

                                        $summary = new ContainerWidget();
                                        $summary->setStyle(new Style(direction: Direction::Vertical, gap: 0));
                                        $summary->addStyleClass('summary');
                                        $summary->add(new TextWidget(sprintf('Output  %s', $outputPath)));
                                        $summary->add(new TextWidget(sprintf('Rows    %s', number_format($rowCount, 0, '.', ' '))));
                                        $summary->add(new TextWidget(sprintf('Time    %ss', $elapsed)));
                                        $summary->add(new TextWidget(''));
                                        $summary->add(new TextWidget('Press Enter or Ctrl+C to exit'));
                                        $mainContainer->add($summary);

                                        $completed = true;

                                        $this->addExitListener($tui);
                                    });
                                } catch (\Throwable $e) {
                                    $steps['write']->setText(sprintf('[✗] Writing CSV — %s', $e->getMessage()));
                                    $exitCode = Command::FAILURE;
                                    $this->showErrorSummary($mainContainer, $tui, $e->getMessage(), $completed);
                                }
                            });
                        } catch (\Throwable $e) {
                            $steps['build']->setText(sprintf('[✗] Building report — %s', $e->getMessage()));
                            $exitCode = Command::FAILURE;
                            $this->showErrorSummary($mainContainer, $tui, $e->getMessage(), $completed);
                        }
                    });
                } catch (\Throwable $e) {
                    if (str_contains((string) $e->getMessage(), '401') || str_contains((string) $e->getMessage(), 'auth')) {
                        $steps['connect']->setText(sprintf('[✗] Connecting to Harbor — %s', $e->getMessage()));
                    } else {
                        $steps['fetch']->setText(sprintf('[✗] Fetching audit log — %s', $e->getMessage()));
                        $steps['connect']->setText('[✓] Connected to Harbor');
                    }
                    $exitCode = Command::FAILURE;
                    $this->showErrorSummary($mainContainer, $tui, $e->getMessage(), $completed);
                }
            });
        });

        $tui->run();

        return $exitCode;
    }

    private function showErrorSummary(ContainerWidget $mainContainer, Tui $tui, string $message, bool &$completed): void
    {
        $summary = new ContainerWidget();
        $summary->setStyle(new Style(direction: Direction::Vertical, gap: 0));
        $summary->addStyleClass('summary');
        $summary->add(new TextWidget(sprintf('Error: %s', $message)));
        $summary->add(new TextWidget(''));
        $summary->add(new TextWidget('Press Enter or Ctrl+C to exit'));
        $mainContainer->add($summary);

        $completed = true;

        $this->addExitListener($tui);
    }

    /**
     * @return \Porthole\Harbor\AuditLogEntry[]
     */
    private function fetchEntries(
        string $harborUrl,
        string $token,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
        ?callable $onPageFetched = null,
        ?HttpClientInterface $httpClient = null,
        ?string $username = null,
    ): array {
        return (new HarborApiClient($harborUrl, $token, $httpClient ?? $this->httpClient, $username))
            ->fetchAuditLogs($from, $to, $onPageFetched);
    }

    /**
     * @param \Porthole\Harbor\AuditLogEntry[] $entries
     *
     * @return array{list<string>, list<list<int|string>>}
     */
    private function buildCsvData(array $entries, string $mode): array
    {
        $builder = new ReportBuilder();

        if ('users' === $mode) {
            $report = $builder->buildUsersReport($entries);
            $rows = $report->rows;
            usort($rows, fn (UserReportRow $a, UserReportRow $b) => $a->username <=> $b->username ?: $b->pullCount <=> $a->pullCount
            );

            return [
                ['User', 'Image', 'Tag', 'Number of pulls'],
                array_map(fn (UserReportRow $r) => [$r->username, $r->image, $r->tag, $r->pullCount], $rows),
            ];
        }

        $report = $builder->buildImagesReport($entries);
        $rows = $report->rows;
        usort($rows, fn (ImageReportRow $a, ImageReportRow $b) => $b->pullCount <=> $a->pullCount);

        return [
            ['Image', 'Tag', 'Number of pulls'],
            array_map(fn (ImageReportRow $r) => [$r->image, $r->tag, $r->pullCount], $rows),
        ];
    }

    /**
     * @param list<string>           $header
     * @param list<list<int|string>> $csvRows
     */
    private function writeCsv(string $outputPath, array $header, array $csvRows): int
    {
        return (new CsvWriter())->write($outputPath, $header, $csvRows);
    }

    private function addExitListener(Tui $tui): void
    {
        $exitKeybindings = new Keybindings(['exit' => ['enter']]);
        $tui->addListener(function (InputEvent $event) use ($tui, $exitKeybindings) {
            if ($exitKeybindings->matches($event->getData(), 'exit')) {
                $tui->stop();
                $event->stopPropagation();
            }
        });
    }
}
