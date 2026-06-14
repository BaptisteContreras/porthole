<?php

namespace Porthole\Background;

use Porthole\Background\Event\BackgroundTaskCompletedEvent;
use Porthole\Background\Event\BackgroundTaskFailedEvent;
use Porthole\Background\Event\BackgroundTaskProgressEvent;
use Revolt\EventLoop;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class BackgroundProcess
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var resource|null */
    private mixed $stdout = null;

    private ?string $timerId = null;

    private string $lineBuffer = '';

    private bool $started = false;

    private bool $shutdownRegistered = false;

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
            throw new \LogicException('BackgroundProcess is already started. Create a new instance to run another task.');
        }

        $this->started = true;

        try {
            $encoded = json_encode($taskPayload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->dispatcher->dispatch(new BackgroundTaskFailedEvent('Failed to encode task payload: '.$e->getMessage()));

            return;
        }

        $pipes = [];
        $process = proc_open(
            command: $this->command,
            descriptor_spec: [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => STDERR,
            ],
            pipes: $pipes,
        );

        if (false === $process) {
            $this->dispatcher->dispatch(new BackgroundTaskFailedEvent('Failed to spawn worker process'));

            return;
        }

        $this->process = $process;
        $this->stdout = $pipes[1];
        stream_set_blocking($pipes[1], false);

        fwrite($pipes[0], $encoded);
        fclose($pipes[0]);

        if (!$this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            $stdoutPipe = $pipes[1];
            register_shutdown_function(function () use ($process, $stdoutPipe): void {
                if (!is_resource($process)) {
                    return;
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                    if (is_resource($stdoutPipe)) {
                        fclose($stdoutPipe);
                    }
                    proc_close($process);
                }
            });
        }

        $startTime = microtime(true);

        $this->timerId = EventLoop::repeat(0.05, function (string $timerId) use ($startTime): void {
            // Assign to locals so PHPStan can narrow types; properties may be null after terminate()
            $stdout = $this->stdout;
            $process = $this->process;
            if (null === $stdout || null === $process) {
                return;
            }

            $chunk = fread($stdout, 4096);
            if (false !== $chunk && '' !== $chunk) {
                $this->lineBuffer .= $chunk;
                while (false !== ($pos = strpos($this->lineBuffer, "\n"))) {
                    $line = substr($this->lineBuffer, 0, $pos);
                    $this->lineBuffer = substr($this->lineBuffer, $pos + 1);
                    if ('' === $line) {
                        continue;
                    }
                    $event = json_decode($line, true);
                    if (!is_array($event)) {
                        continue;
                    }
                    /** @var array<string, mixed> $event */
                    if ($this->dispatchProgressOrTerminal($event)) {
                        return;
                    }
                }
            }

            if (microtime(true) - $startTime > $this->timeoutSeconds) {
                proc_terminate($process);
                $this->terminate(
                    new BackgroundTaskFailedEvent(sprintf('Worker timed out after %ds', $this->timeoutSeconds)),
                );

                return;
            }

            if (feof($stdout) && !proc_get_status($process)['running']) {
                // Drain any newline-terminated lines buffered before the process exited
                while (false !== ($pos = strpos($this->lineBuffer, "\n"))) {
                    $line = substr($this->lineBuffer, 0, $pos);
                    $this->lineBuffer = substr($this->lineBuffer, $pos + 1);
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
                // Attempt to parse a partial line with no trailing newline (child crashed mid-write)
                $remaining = trim($this->lineBuffer);
                if ('' !== $remaining) {
                    $event = json_decode($remaining, true);
                    if (is_array($event)) {
                        /** @var array<string, mixed> $event */
                        if ($this->dispatchProgressOrTerminal($event)) {
                            return;
                        }
                    }
                }
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
        $process = $this->process;
        if (null !== $process && proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        $this->cleanup();
    }

    private function terminate(object $event): void
    {
        \assert(null !== $this->timerId, 'terminate() called without an active timer');
        EventLoop::cancel($this->timerId);
        $this->timerId = null;
        $this->cleanup();
        $this->dispatcher->dispatch($event);
    }

    private function cleanup(): void
    {
        $stdout = $this->stdout;
        if (null !== $stdout) {
            fclose($stdout);
            $this->stdout = null;
        }
        $process = $this->process;
        if (null !== $process) {
            proc_close($process);
            $this->process = null;
        }
    }

    /**
     * Dispatches a progress or terminal event from a decoded JSON object.
     * Returns true if a terminal event (done/error) was dispatched and the caller should return.
     *
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
