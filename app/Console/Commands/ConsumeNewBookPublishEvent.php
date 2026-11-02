<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

#[Signature('events:consume-new-book-publish-event')]
#[Description('Consume NewBookPublish events from Redis')]
class ConsumeNewBookPublishEvent extends Command
{
    /**
     * Execute the console command.
     */
    public function handle()
    {
        $queue = 'new-book-published';

        $this->info("Listening to Redis queue: {$queue}");

        Log::info('CONSUMER STARTED', [
            'service' => 'library-member-service',
            'queue' => $queue,
        ]);

        while (true) {
            try {
                // Wait for a message. Timeout 5 means the
                // command periodically regains control.
                $result = Redis::brpop([$queue], 5);

                if (!$result) {
                    continue;
                }

                // BRPOP returns [queue_name, message].
                $rawMessage = $result[1];

                $event = json_decode(
                    $rawMessage,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

                if (
                    !is_array($event) ||
                    ($event['event_type'] ?? null)
                        !== 'NewBookPublished' ||
                    !isset($event['event_id'], $event['data'])
                ) {
                    throw new \UnexpectedValueException(
                        'Invalid NewBookPublished event'
                    );
                }

                // Simulate Service B processing the event.
                Log::info('MESSAGE CONSUMED', [
                    'service' => 'library-member-service',
                    'queue' => $queue,
                    'event' => $event,
                ]);

                $this->info(
                    "Consumed event: {$event['event_id']}"
                );
            } catch (Throwable $e) {
                Log::error('CONSUMER ERROR', [
                    'service' => 'library-member-service',
                    'error' => $e->getMessage(),
                ]);

                $this->error($e->getMessage());

                // Keep the demonstration worker alive.
                sleep(1);
            }
        }

        return self::SUCCESS;

    }
}
