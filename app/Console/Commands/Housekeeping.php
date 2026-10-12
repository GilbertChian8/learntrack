<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

/**
 * The daily scheduled task (ADR-014). Every step is safe to repeat.
 */
#[Signature('app:housekeeping')]
#[Description('Purge expired and revoked OAuth tokens, expired cache rows and old sessions')]
class Housekeeping extends Command
{
    public function handle(): int
    {
        if ($this->call('passport:purge', ['--hours' => 0]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        $now = now()->getTimestamp();
        $cacheRows = DB::table('cache')->where('expiration', '<=', $now)->delete();
        $cacheLocks = DB::table('cache_locks')->where('expiration', '<=', $now)->delete();
        $sessions = Session::getHandler()->gc(Config::integer('session.lifetime') * 60);

        $this->components->info("Deleted {$cacheRows} cache rows, {$cacheLocks} cache locks and {$sessions} sessions.");

        return self::SUCCESS;
    }
}
