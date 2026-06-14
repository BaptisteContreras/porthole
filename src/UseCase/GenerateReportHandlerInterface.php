<?php

namespace Porthole\UseCase;

use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

interface GenerateReportHandlerInterface
{
    public function handle(GenerateReportCommand $command, EventDispatcherInterface $dispatcher): void;
}
