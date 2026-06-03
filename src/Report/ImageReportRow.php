<?php

namespace Porthole\Report;

final class ImageReportRow
{
    public function __construct(
        public readonly string $image,
        public readonly string $tag,
        public readonly int $pullCount,
    ) {
    }
}
