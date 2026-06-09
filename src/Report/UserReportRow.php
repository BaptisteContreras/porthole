<?php

namespace Porthole\Report;

use Symfony\Component\Serializer\Attribute\SerializedName;

final class UserReportRow
{
    public function __construct(
        #[SerializedName('User')]
        public readonly string $username,
        #[SerializedName('Image')]
        public readonly string $image,
        #[SerializedName('Tag')]
        public readonly string $tag,
        #[SerializedName('Number of pulls')]
        public readonly int $pullCount,
    ) {
    }
}
