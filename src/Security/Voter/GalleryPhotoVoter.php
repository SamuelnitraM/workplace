<?php

namespace App\Security\Voter;

use App\Entity\GalleryPhoto;
use App\Entity\GalleryPhotoComment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Règles d'accès à la galerie :
 *  - VIEW    : photo visible, ou propriétaire ;
 *  - LIKE    : connecté, photo visible, pas sa propre photo ;
 *  - COMMENT : connecté, photo visible (le propriétaire peut répondre sous sa propre photo) ;
 *  - EDIT / DELETE : propriétaire ;
 *  - COMMENT_DELETE (sujet : GalleryPhotoComment) : auteur du commentaire ou propriétaire de la photo.
 *
 * @extends Voter<string, GalleryPhoto|GalleryPhotoComment>
 */
final class GalleryPhotoVoter extends Voter
{
    public const VIEW = 'GALLERY_PHOTO_VIEW';
    public const LIKE = 'GALLERY_PHOTO_LIKE';
    public const COMMENT = 'GALLERY_PHOTO_COMMENT';
    public const EDIT = 'GALLERY_PHOTO_EDIT';
    public const DELETE = 'GALLERY_PHOTO_DELETE';
    public const COMMENT_DELETE = 'GALLERY_PHOTO_COMMENT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::COMMENT_DELETE) {
            return $subject instanceof GalleryPhotoComment;
        }

        return $subject instanceof GalleryPhoto
            && in_array($attribute, [self::VIEW, self::LIKE, self::COMMENT, self::EDIT, self::DELETE], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $user = $user instanceof User ? $user : null;

        if ($subject instanceof GalleryPhotoComment) {
            return $user !== null
                && (self::isSameUser($subject->getAuthor(), $user) || self::isSameUser($subject->getPhoto()?->getOwner(), $user));
        }

        /** @var GalleryPhoto $subject */
        $isOwner = $user !== null && self::isSameUser($subject->getOwner(), $user);

        return match ($attribute) {
            self::VIEW => $subject->isVisible() || $isOwner,
            self::LIKE => $user !== null && !$isOwner && $subject->isVisible(),
            self::COMMENT => $user !== null && $subject->isVisible(),
            self::EDIT, self::DELETE => $isOwner,
            default => false,
        };
    }

    private static function isSameUser(?User $a, User $b): bool
    {
        return $a !== null && $a->getId() !== null && $a->getId() === $b->getId();
    }
}
