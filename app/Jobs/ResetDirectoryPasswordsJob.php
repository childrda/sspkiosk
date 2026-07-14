<?php

namespace App\Jobs;

use App\Services\DirectoryPasswordResetCoordinator;
use App\Support\DirectoryResetOutcome;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ResetDirectoryPasswordsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $passwordResetRequestId,
    ) {}

    public function handle(DirectoryPasswordResetCoordinator $coordinator): void
    {
        $outcome = $coordinator->process($this->passwordResetRequestId);

        if ($outcome->hasRetryableFailures() && $this->attempts() < $this->tries) {
            $this->release($this->backoffSeconds($this->attempts()));
        }
    }

    private function backoffSeconds(int $attempt): int
    {
        return match ($attempt) {
            1 => 30,
            2 => 120,
            default => 300,
        };
    }
}
