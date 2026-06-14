<?php

namespace Porthole\Background\Event;

use Porthole\Background\ProcessId;

final class BackgroundProcessStoppedEvent
{
    public function __construct(
        public readonly ProcessId $id,
        public readonly string $label,
    ) {
    }
}
