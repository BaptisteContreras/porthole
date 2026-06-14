<?php

namespace Porthole\Background;

use Porthole\Background\Event\BackgroundProcessStartedEvent;
use Porthole\Background\Event\BackgroundProcessStoppedEvent;
use Porthole\Background\Event\BackgroundTaskCompletedEvent;
use Porthole\Background\Event\BackgroundTaskFailedEvent;
use Porthole\Background\Event\BackgroundTaskProgressEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class BackgroundProcessManager
{
    /** @var array<string, array{id: ProcessId, label: string, process: BackgroundProcess, dispatcher: EventDispatcher}> */
    private array $entries = [];

    /** @var array<string, true> */
    private array $stopped = [];

    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<string, mixed>   $payload
     */
    public function start(
        string $label,
        array $command,
        array $payload,
        int $timeoutSeconds = 120,
    ): ProcessId {
        $id = ProcessId::generate();
        $internalDispatcher = new EventDispatcher();
        $process = new BackgroundProcess($command, $internalDispatcher, $timeoutSeconds);

        $this->entries[$id->value] = [
            'id' => $id,
            'label' => $label,
            'process' => $process,
            'dispatcher' => $internalDispatcher,
        ];

        foreach ([BackgroundTaskCompletedEvent::class, BackgroundTaskFailedEvent::class] as $eventClass) {
            $internalDispatcher->addListener($eventClass, function () use ($id, $label): void {
                if (isset($this->stopped[$id->value])) {
                    return;
                }
                $this->stopped[$id->value] = true;
                $this->dispatcher->dispatch(new BackgroundProcessStoppedEvent($id, $label));
            });
        }

        $this->dispatcher->dispatch(new BackgroundProcessStartedEvent($id, $label));
        $process->start($payload);

        return $id;
    }

    public function stop(ProcessId $id): void
    {
        $entry = $this->entries[$id->value] ?? null;
        if (null === $entry || isset($this->stopped[$id->value])) {
            return;
        }

        $this->stopped[$id->value] = true;
        $entry['process']->kill();
        $this->dispatcher->dispatch(new BackgroundProcessStoppedEvent($id, $entry['label']));
    }

    public function onProcessProgress(ProcessId $id, callable $listener): void
    {
        $this->addListener($id, BackgroundTaskProgressEvent::class, $listener);
    }

    public function onProcessCompleted(ProcessId $id, callable $listener): void
    {
        $this->addListener($id, BackgroundTaskCompletedEvent::class, $listener);
    }

    public function onProcessFailed(ProcessId $id, callable $listener): void
    {
        $this->addListener($id, BackgroundTaskFailedEvent::class, $listener);
    }

    private function addListener(ProcessId $id, string $eventClass, callable $listener): void
    {
        $entry = $this->entries[$id->value] ?? null;
        if (null === $entry) {
            return;
        }

        $entry['dispatcher']->addListener($eventClass, $listener);
    }
}
