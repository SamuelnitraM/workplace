<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Pusher\Pusher;

class PusherService
{
    private Pusher $pusher;

    public function __construct(private LoggerInterface $logger)
    {
        $this->pusher = new Pusher(
            $_ENV['PUSHER_KEY'],
            $_ENV['PUSHER_SECRET'],
            $_ENV['PUSHER_APP_ID'],
            ['cluster' => $_ENV['PUSHER_CLUSTER'], 'useTLS' => true]
        );
    }

    /**
     * Publie un évènement temps réel. Une panne Pusher ne doit jamais faire échouer
     * la requête : les données sont déjà enregistrées en base à ce stade.
     *
     * @param string|string[] $channels
     */
    public function sendMessage(string|array $channels, string $event, array $data): void
    {
        $channels = (array) $channels;
        try {
            // L'API Pusher accepte au plus 100 canaux par appel
            foreach (array_chunk($channels, 100) as $chunk) {
                $this->pusher->trigger($chunk, $event, $data);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Échec de la publication Pusher', [
                'event' => $event,
                'channels' => $channels,
                'exception' => $e,
            ]);
        }
    }

    /**
     * Publie plusieurs évènements (canaux/données différents) en regroupant les appels HTTP.
     * Comme sendMessage, ne lève jamais d'exception.
     *
     * @param list<array{channel: string, name: string, data: array}> $events
     */
    public function sendBatch(array $events): void
    {
        try {
            // L'API Pusher accepte au plus 10 évènements par lot
            foreach (array_chunk($events, 10) as $chunk) {
                $this->pusher->triggerBatch($chunk);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Échec de la publication Pusher (lot)', [
                'events' => count($events),
                'exception' => $e,
            ]);
        }
    }

    public function authorizeChannel(string $channel, string $socketId): string
    {
        return $this->pusher->authorizeChannel($channel, $socketId);
    }

    public static function userChannel(int $userId): string
    {
        return 'private-user-' . $userId;
    }

    public static function conversationChannel(int $conversationId): string
    {
        return 'private-conversation-' . $conversationId;
    }

    public static function groupChannel(int $channelId): string
    {
        return 'private-group-channel-' . $channelId;
    }
}
