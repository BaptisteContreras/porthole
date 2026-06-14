<?php

namespace Porthole\Background;

use Porthole\Background\Event\BackgroundTaskCompletedEvent;
use Porthole\Background\Event\BackgroundTaskFailedEvent;
use Porthole\Background\Event\BackgroundTaskProgressEvent;
use Revolt\EventLoop;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class BackgroundProcess
{
    private ?ProcessSocket $socket = null;
    private ?string $timerId = null;
    private bool $started = false;
    private float $startTime = 0.0;

    /**
     * @param non-empty-list<string> $command
     */
    public function __construct(
        private readonly array $command,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly int $timeoutSeconds = 120,
    ) {
    }

    /**
     * @param array<string, mixed> $taskPayload
     */
    public function start(array $taskPayload): void
    {
        if ($this->started) {
            throw new \LogicException('BackgroundProcess already started. Create a new instance to run another task.');
        }
        $this->started = true;

        try {
            $encoded = json_encode($taskPayload, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->dispatcher->dispatch(new BackgroundTaskFailedEvent('Failed to encode task payload: '.$e->getMessage()));

            return;
        }

        try {
            $socket = new ProcessSocket($this->command);
            $socket->write($encoded);
        } catch (\RuntimeException $e) {
            $this->dispatcher->dispatch(new BackgroundTaskFailedEvent($e->getMessage()));

            return;
        }

        $this->socket = $socket;
        $this->startTime = microtime(true);

        $this->timerId = EventLoop::repeat(0.05, function (): void {
            $socket = $this->socket;
            if (null === $socket) {
                return;
            }

            while (null !== ($line = $socket->readLine())) {
                if ('' === $line) {
                    continue;
                }
                $event = json_decode($line, true);
                if (is_array($event)) {
                    /** @var array<string, mixed> $event */
                    if ($this->dispatchProgressOrTerminal($event)) {
                        return;
                    }
                }
            }

            if (microtime(true) - $this->startTime > $this->timeoutSeconds) {
                $this->terminate(new BackgroundTaskFailedEvent(sprintf('Worker timed out after %ds', $this->timeoutSeconds)));

                return;
            }

            if ($socket->isDone()) {
                $this->terminate(new BackgroundTaskFailedEvent('Worker process exited unexpectedly'));
            }
        });
    }

    public function kill(): void
    {
        if (null !== $this->timerId) {
            EventLoop::cancel($this->timerId);
            $this->timerId = null;
        }
        $this->socket?->close();
        $this->socket = null;
    }

    private function terminate(object $event): void
    {
        \assert(null !== $this->timerId, 'terminate() called without an active timer');
        EventLoop::cancel($this->timerId);
        $this->timerId = null;
        $this->socket?->close();
        $this->socket = null;
        $this->dispatcher->dispatch($event);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function dispatchProgressOrTerminal(array $event): bool
    {
        $typeValue = $event['type'] ?? null;
        $type = is_string($typeValue) ? $typeValue : '';

        if ('done' === $type) {
            $this->terminate(new BackgroundTaskCompletedEvent());

            return true;
        }

        if ('error' === $type) {
            $messageValue = $event['message'] ?? null;
            $message = is_string($messageValue) ? $messageValue : 'Unknown error';
            $this->terminate(new BackgroundTaskFailedEvent($message));

            return true;
        }

        $this->dispatcher->dispatch(new BackgroundTaskProgressEvent($event));

        return false;
    }
}
