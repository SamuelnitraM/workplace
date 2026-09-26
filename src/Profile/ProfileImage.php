<?php

namespace App\Profile;

use App\Entity\User;

/**
 * Images of a member profile: the profile photo (avatar) and the banner shown at the top of the profile (cover).
 * Each kind has its own upload folder (public/uploads/<folder>), aspect ratio, maximum dimension and file size.
 * The stored image always has the aspect ratio of its kind: it is displayed exactly as framed when it was uploaded.
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

    /** Width / height of the stored image (square profile photo, wide banner). */
    public function aspectRatio(): float
    {
        return match ($this) {
            self::Avatar => 1.0,
            self::Cover => 4.0,
        };
    }

    /** Request field holding the crop frame chosen in the browser ("x,y,width,height"). */
    public function cropFieldName(): string
    {
        return $this->value . '_crop';
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
