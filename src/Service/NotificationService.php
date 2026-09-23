<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Création des notifications (avec agrégation) et publication temps réel.
 *
 * La publication Pusher a lieu après l'écriture en base (postFlush) : l'identifiant est connu
 * et aucune notification fantôme n'est poussée si l'enregistrement échoue. Cela permet aussi
 * de créer des notifications sans flush immédiat (ex. badges, enregistrés par l'appelant).
 */
#[AsDoctrineListener(event: Events::postFlush)]
class NotificationService
{
    public const CSRF_TOKEN_ID = 'notification';
    public const PUSHER_EVENT = 'notification';

    /** @var array<int, Notification> notifications à publier après le prochain flush (clé : spl_object_id) */
    private array $pendingPush = [];

    /** @var array<string, Notification> notifications non encore flushées, pour l'agrégation (clé : destinataire|groupKey) */
    private array $pendingByKey = [];

    private bool $pushing = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NotificationRepository $repository,
        private readonly NotificationRenderer $renderer,
        private readonly PusherService $pusher,
    ) {
    }

    /**
     * Notifie un utilisateur. Ne notifie jamais l'auteur de l'action lui-même.
     * Si $groupKey est fourni et qu'une notification NON LUE existe déjà pour ce destinataire
     * et cette clé, elle est mise à jour (compteur, acteur, date) au lieu d'en créer une nouvelle.
     */
    public function notify(
        User $recipient,
        string $type,
        ?User $actor,
        array $data,
        string $url,
        ?string $groupKey = null,
        bool $flush = true,
    ): ?Notification {
        return $this->notifyMany([$recipient], $type, $actor, $data, $url, $groupKey, $flush)[0] ?? null;
    }

    /**
     * Même règle que notify() pour plusieurs destinataires, en une requête d'agrégation et un flush.
     *
     * @param iterable<User> $recipients
     * @return Notification[]
     */
    public function notifyMany(
        iterable $recipients,
        string $type,
        ?User $actor,
        array $data,
        string $url,
        ?string $groupKey = null,
        bool $flush = true,
    ): array {
        $targets = [];
        foreach ($recipients as $recipient) {
            $id = $recipient->getId();
            if ($id === null || ($actor !== null && $actor->getId() === $id)) {
                continue; // jamais de notification pour sa propre action
            }
            $targets[$id] = $recipient;
        }
        if (!$targets) {
            return [];
        }

        $existing = $groupKey !== null
            ? $this->repository->findUnreadByGroupKey(array_keys($targets), $groupKey)
            : [];

        $notifications = [];
        foreach ($targets as $id => $recipient) {
            $notification = $groupKey !== null ? $this->pendingFor($id, $groupKey) ?? ($existing[$id] ?? null) : null;

            if ($notification) {
                $notification->aggregate($actor, $data, $url);
            } else {
                $notification = new Notification($recipient, $type, $actor, $data, $url, $groupKey);
                $this->em->persist($notification);
            }

            if ($groupKey !== null && $notification->getId() === null) {
                $this->pendingByKey[$id . '|' . $groupKey] = $notification;
            }
            $this->pendingPush[spl_object_id($notification)] = $notification;
            $notifications[] = $notification;
        }

        if ($flush) {
            $this->em->flush();
        }

        return $notifications;
    }

    /** Représentation JSON d'une notification (dropdown, temps réel). */
    public function serialize(Notification $notification): array
    {
        $actor = $notification->getActor();

        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'text' => $this->renderer->text($notification),
            'icon' => $this->renderer->icon($notification),
            'url' => $notification->getUrl(),
            'groupKey' => $notification->getGroupKey(),
            'actor' => $actor ? ['username' => $actor->getUsername(), 'avatar' => $actor->getAvatar()] : null,
            'count' => $notification->getCount(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $notification->getUpdatedAt()->format(\DateTimeInterface::ATOM),
            'read' => $notification->isRead(),
        ];
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->pushing || !$this->pendingPush) {
            return;
        }

        $this->pushing = true;
        try {
            $notifications = array_filter($this->pendingPush, static fn (Notification $n) => $n->getId() !== null);
            $this->pendingPush = [];
            $this->pendingByKey = [];
            if (!$notifications) {
                return;
            }

            $recipientIds = array_values(array_unique(array_map(
                static fn (Notification $n) => $n->getRecipient()->getId(),
                $notifications
            )));
            $unread = $this->repository->countUnreadByRecipients($recipientIds);

            $events = [];
            foreach ($notifications as $notification) {
                $recipientId = $notification->getRecipient()->getId();
                $events[] = [
                    'channel' => PusherService::userChannel($recipientId),
                    'name' => self::PUSHER_EVENT,
                    'data' => [
                        'notification' => $this->serialize($notification),
                        'unreadCount' => $unread[$recipientId] ?? 0,
                    ],
                ];
            }
            $this->pusher->sendBatch($events);
        } finally {
            $this->pushing = false;
        }
    }

    /** Notification encore en attente d'écriture pour ce destinataire et cette clé (même requête). */
    private function pendingFor(int $recipientId, string $groupKey): ?Notification
    {
        $notification = $this->pendingByKey[$recipientId . '|' . $groupKey] ?? null;
        // Après une réinitialisation de l'EntityManager (flush en échec), l'objet n'est plus géré
        if ($notification && !$this->em->contains($notification)) {
            unset($this->pendingByKey[$recipientId . '|' . $groupKey]);

            return null;
        }

        return $notification;
    }
}
