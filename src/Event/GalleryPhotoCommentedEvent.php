<?php

namespace App\Event;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use App\Entity\User;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatché après l'enregistrement d'un commentaire sur une photo de galerie (point d'accroche pour les notifications).
 */
final class GalleryPhotoCommentedEvent extends Event
{
    public function __construct(
        public readonly GalleryPhoto $photo,
        public readonly User $actor,
        public readonly GalleryPhotoComment $comment,
    ) {
    }
}
