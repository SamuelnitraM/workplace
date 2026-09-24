<?php

namespace App\Moderation;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use App\Entity\Post;
use App\Entity\PrivateMessage;
use App\Entity\Thread;
use App\Entity\User;

/** Kinds of content a member can report. The value is stored in report.target_type and used in URLs. */
enum ReportTargetType: string
{
    case Thread = 'sujet';
    case Post = 'reponse';
    case Photo = 'photo';
    case PhotoComment = 'commentaire';
    case PrivateMessage = 'message';
    case Profile = 'profil';

    public function label(): string
    {
        return match ($this) {
            self::Thread => 'Sujet du forum',
            self::Post => 'Réponse du forum',
            self::Photo => 'Photo',
            self::PhotoComment => 'Commentaire de photo',
            self::PrivateMessage => 'Message privé',
            self::Profile => 'Profil',
        };
    }

    /** @return class-string */
    public function entityClass(): string
    {
        return match ($this) {
            self::Thread => Thread::class,
            self::Post => Post::class,
            self::Photo => GalleryPhoto::class,
            self::PhotoComment => GalleryPhotoComment::class,
            self::PrivateMessage => PrivateMessage::class,
            self::Profile => User::class,
        };
    }

    /** Hiding keeps the content for moderators while members only see a notice. */
    public function canBeHidden(): bool
    {
        return in_array($this, [self::Thread, self::Post, self::Photo], true);
    }

    public function canBeDeleted(): bool
    {
        return $this !== self::Profile;
    }
}
