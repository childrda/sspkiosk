<?php

namespace App\Support;

final readonly class DirectoryResetOutcome
{
    public function __construct(
        public bool $retryableFailuresRemain,
    ) {}

    public function hasRetryableFailures(): bool
    {
        return $this->retryableFailuresRemain;
    }
}
