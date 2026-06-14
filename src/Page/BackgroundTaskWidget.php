<?php

namespace Porthole\Page;

use Porthole\Tui\Navigator;
use Revolt\EventLoop;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\TextWidget;

final class BackgroundTaskWidget
{
    private const array SPINNER_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    private ContainerWidget $widget;

    /** @var array<string, TextWidget> */
    private array $stepWidgets = [];

    /** @var array<string, string> */
    private array $stepLabels = [];

    private ?string $activeStepKey = null;
    private int $spinnerFrame = 0;
    private ?string $spinnerTimerId = null;

    /**
     * @param list<array{key: string, label: string}> $steps
     */
    public function __construct(
        private readonly Navigator $navigator,
        string $title,
        array $steps,
    ) {
        $this->widget = $this->build($title, $steps);
    }

    public function getWidget(): ContainerWidget
    {
        return $this->widget;
    }

    public function setStepRunning(string $key, ?string $label = null): void
    {
        if (null !== $label) {
            $this->stepLabels[$key] = $label;
        }
        $this->activeStepKey = $key;
        $this->updateActiveStepWidget();

        if (null === $this->spinnerTimerId) {
            $this->spinnerTimerId = EventLoop::repeat(0.1, function (): void {
                $this->spinnerFrame = ($this->spinnerFrame + 1) % \count(self::SPINNER_FRAMES);
                $this->updateActiveStepWidget();
                $this->navigator->requestPageRender();
            });
        }

        $this->navigator->requestPageRender();
    }

    public function setStepDone(string $key, ?string $label = null): void
    {
        if (null !== $label) {
            $this->stepLabels[$key] = $label;
        }
        if (isset($this->stepWidgets[$key])) {
            $this->stepWidgets[$key]->setText(sprintf('[✓] %s', $this->stepLabels[$key] ?? ''));
        }
        if ($this->activeStepKey === $key) {
            $this->activeStepKey = null;
        }
        $this->navigator->requestPageRender();
    }

    public function setComplete(string ...$lines): void
    {
        $this->stopSpinner();
        foreach ($lines as $line) {
            $this->widget->add(new TextWidget($line));
        }
        $this->navigator->requestPageRender();
    }

    public function setFailed(string $message): void
    {
        $this->stopSpinner();
        $widget = new TextWidget(sprintf('✗ %s', $message));
        $widget->addStyleClass('text-red-500');
        $this->widget->add($widget);
        $this->navigator->requestPageRender();
    }

    /**
     * @param list<array{key: string, label: string}> $steps
     */
    private function build(string $title, array $steps): ContainerWidget
    {
        $container = new ContainerWidget();
        $container->setStyle(new Style(direction: Direction::Vertical));

        $titleWidget = new TextWidget($title);
        $titleWidget->addStyleClass('font-big text-cyan-400 bold');
        $container->add($titleWidget);

        foreach ($steps as $step) {
            $this->stepLabels[$step['key']] = $step['label'];
            $widget = new TextWidget(sprintf('[ ] %s', $step['label']));
            $this->stepWidgets[$step['key']] = $widget;
            $container->add($widget);
        }

        return $container;
    }

    private function updateActiveStepWidget(): void
    {
        $key = $this->activeStepKey;
        if (null === $key || !isset($this->stepWidgets[$key])) {
            return;
        }
        $frame = self::SPINNER_FRAMES[$this->spinnerFrame];
        $this->stepWidgets[$key]->setText(sprintf('[%s] %s', $frame, $this->stepLabels[$key] ?? ''));
    }

    private function stopSpinner(): void
    {
        if (null !== $this->spinnerTimerId) {
            EventLoop::cancel($this->spinnerTimerId);
            $this->spinnerTimerId = null;
        }
    }
}
