<?php

namespace App\Profile;

use App\Entity\User;

/**
 * Images of a member profile: the profile photo (avatar) and the banner shown at the top of the profile (cover).
 * Each kind has its own upload folder (public/uploads/<folder>), maximum dimension and file size.
 */
enum ProfileImage: string
{
    case Avatar = 'avatar';
    case Cover = 'cover';

    /** Folder under public/uploads. */
    public function folder(): string
    {
        return match ($this) {
            self::Avatar => 'avatars',
            self::Cover => 'covers',
        };
    }

    /** Largest side of the re-encoded WebP image, in pixels. */
    public function maxDimension(): int
    {
        return match ($this) {
            self::Avatar => 512,
            self::Cover => 1920,
        };
    }

    /** Maximum size of the uploaded file (Symfony File constraint format). */
    public function maxFileSize(): string
    {
        return match ($this) {
            self::Avatar => '2M',
            self::Cover => '5M',
        };
    }

    /** French label used in messages ("la photo de profil", "la bannière"). */
    public function label(): string
    {
        return match ($this) {
            self::Avatar => 'la photo de profil',
            self::Cover => 'la bannière',
        };
    }

    public function filenameOf(User $user): ?string
    {
        return match ($this) {
            self::Avatar => $user->getAvatar(),
            self::Cover => $user->getCover(),
        };
    }

    public function assignTo(User $user, ?string $filename): void
    {
        match ($this) {
            self::Avatar => $user->setAvatar($filename),
            self::Cover => $user->setCover($filename),
        };
    }
}
