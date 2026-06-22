<?php

namespace Porthole\UseCase;

final class GenerateReportCommand
{
    public function __construct(
        public readonly string $harborUrl,
        public readonly string $token,
        public readonly ?string $username,
        public readonly string $mode,
        public readonly ?\DateTimeImmutable $from,
        public readonly ?\DateTimeImmutable $to,
        public readonly string $outputPath,
        public readonly bool $verifySsl,
        public readonly string $auditLogEndpoint = 'extended',
    ) {
    }
}
