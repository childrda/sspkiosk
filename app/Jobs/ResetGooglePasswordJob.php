<?php

namespace App\Jobs;

use App\Services\DirectoryPasswordResetCoordinator;

/**
 * @deprecated Use ResetDirectoryPasswordsJob. Kept briefly so queued payloads still resolve.
 */
class ResetGooglePasswordJob extends ResetDirectoryPasswordsJob
{
    public function handle(DirectoryPasswordResetCoordinator $coordinator): void
    {
        parent::handle($coordinator);
    }
}
