<?php

namespace Porthole\Background;

final class ProcessSocket
{
    /** @var resource|null */
    private mixed $process = null;

    /** @var resource|null */
    private mixed $stdin = null;

    /** @var resource|null */
    private mixed $stdout = null;

    private string $lineBuffer = '';
    private bool $closed = false;

    /**
     * @param non-empty-list<string> $command
     */
    public function __construct(array $command)
    {
        $pipes = [];
        $process = proc_open(
            command: $command,
            descriptor_spec: [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => \STDERR,
            ],
            pipes: $pipes,
        );

        if (false === $process) {
            throw new \RuntimeException('Failed to spawn process.');
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        stream_set_blocking($pipes[1], false);
    }

    public function write(string $data): void
    {
        if (null === $this->stdin) {
            throw new \LogicException('Cannot write: stdin already closed.');
        }
        fwrite($this->stdin, $data);
        fclose($this->stdin);
        $this->stdin = null;
    }

    public function readLine(): ?string
    {
        $stdout = $this->stdout;
        if (null === $stdout) {
            return null;
        }

        $chunk = fread($stdout, 4096);
        if (false !== $chunk && '' !== $chunk) {
            $this->lineBuffer .= $chunk;
        }

        if (false !== ($pos = strpos($this->lineBuffer, "\n"))) {
            $line = substr($this->lineBuffer, 0, $pos);
            $this->lineBuffer = substr($this->lineBuffer, $pos + 1);

            return $line;
        }

        if (feof($stdout) && '' !== $this->lineBuffer) {
            $line = $this->lineBuffer;
            $this->lineBuffer = '';

            return $line;
        }

        return null;
    }

    public function isDone(): bool
    {
        $stdout = $this->stdout;
        $process = $this->process;

        if (null === $stdout || null === $process || !feof($stdout) || '' !== $this->lineBuffer) {
            return false;
        }

        return !proc_get_status($process)['running'];
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (null !== $this->stdin) {
            fclose($this->stdin);
            $this->stdin = null;
        }
        if (null !== $this->stdout) {
            fclose($this->stdout);
            $this->stdout = null;
        }
        if (null !== $this->process) {
            if (proc_get_status($this->process)['running']) {
                proc_terminate($this->process);
            }
            proc_close($this->process);
            $this->process = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
