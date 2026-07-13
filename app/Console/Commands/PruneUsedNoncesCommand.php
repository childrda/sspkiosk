<?php

namespace App\Console\Commands;

use App\Models\UsedNonce;
use Illuminate\Console\Command;

class PruneUsedNoncesCommand extends Command
{
    protected $signature = 'ssp:prune-nonces';

    protected $description = 'Delete used kiosk nonces older than twice the HMAC tolerance window';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(config('kiosk.hmac_tolerance_seconds') * 2);

        $deleted = UsedNonce::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Pruned {$deleted} used nonce row(s) older than {$cutoff->toDateTimeString()}.");

        return self::SUCCESS;
    }
}
