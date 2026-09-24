<?php

namespace App\Feed;

use App\Entity\User;

/**
 * Élément du fil d'actualité.
 *
 * subject selon le type : GalleryPhoto (photo), Thread (thread), ArmyList (army), Badge[] (badges).
 * meta : compléments d'affichage (compteurs, extrait…) renseignés par FeedService.
 */
final class FeedItem
{
    public const TYPE_PHOTO = 'photo';
    public const TYPE_THREAD = 'thread';
    public const TYPE_ARMY = 'army';
    public const TYPE_BADGES = 'badges';

    public array $meta = [];

    public function __construct(
        public readonly string $type,
        public readonly string $key,
        public readonly \DateTimeImmutable $date,
        public readonly User $actor,
        public readonly mixed $subject,
    ) {
    }
}
