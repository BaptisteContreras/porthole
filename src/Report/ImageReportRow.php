<?php

namespace Porthole\Report;

use Symfony\Component\Serializer\Attribute\SerializedName;

final class ImageReportRow
{
    public function __construct(
        #[SerializedName('Image')]
        public readonly string $image,
        #[SerializedName('Tag')]
        public readonly string $tag,
        #[SerializedName('Number of pulls')]
        public readonly int $pullCount,
    ) {
    }
}
