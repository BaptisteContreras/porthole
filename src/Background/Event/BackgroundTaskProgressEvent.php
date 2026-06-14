<?php

namespace Porthole\Background\Event;

final class BackgroundTaskProgressEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(public readonly array $data)
    {
    }
}
