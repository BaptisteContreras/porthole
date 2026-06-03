<?php

namespace Porthole\Report;

final class UserReportRow
{
    public function __construct(
        public readonly string $username,
        public readonly string $image,
        public readonly string $tag,
        public readonly int $pullCount,
    ) {
    }
}
