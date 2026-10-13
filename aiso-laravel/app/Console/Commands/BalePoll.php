<?php

namespace App\Console\Commands;

use App\Messaging\BaleBot;
use App\Messaging\BaleClient;
use App\Services\BaleConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Receives messages sent to the Bale bot (long polling) and answers them through BaleBot. Run it as a long-lived
 * process (for example under Supervisor), or with --once from a scheduler.
 */
class BalePoll extends Command
{
    protected $signature = 'bale:poll {--once : Process one batch of updates and exit}';

    protected $description = 'Receive and answer messages sent to the Bale bot';

    private const OFFSET_KEY = 'bale:offset';
    private const LONG_POLL_SECONDS = 25;
    private const BACKOFF_SECONDS = 5;

    public function handle(BaleClient $client, BaleBot $bot): int
    {
        if (!BaleConfig::configured()) {
            $this->error('The Bale bot token is not set: add it in Settings, or BALE_BOT_TOKEN in .env.');

            return self::FAILURE;
        }

        $once = (bool) $this->option('once');
        do {
            try {
                $this->processBatch($client, $bot);
            } catch (Throwable $e) {
                Log::warning("Bale poll failed: {$e->getMessage()}");
                $this->warn("Bale poll failed: {$e->getMessage()}. Waiting " . self::BACKOFF_SECONDS . ' seconds.');
                Sleep::for(self::BACKOFF_SECONDS)->seconds();
                if ($once) {
                    return self::FAILURE;
                }
            }
        } while (!$once);

        return self::SUCCESS;
    }

    private function processBatch(BaleClient $client, BaleBot $bot): void
    {
        $offset = (int) Cache::get(self::OFFSET_KEY, 0);
        foreach ($client->getUpdates($offset, self::LONG_POLL_SECONDS) as $update) {
            if (!is_array($update) || !isset($update['update_id'])) {
                continue;
            }
            try {
                $bot->handleUpdate($update);
            } catch (Throwable $e) {
                // the offset still moves on: one message that cannot be answered must not block the queue
                Log::warning("Bale update {$update['update_id']} was not answered: {$e->getMessage()}");
            }
            Cache::forever(self::OFFSET_KEY, (int) $update['update_id'] + 1);
        }
    }
}
