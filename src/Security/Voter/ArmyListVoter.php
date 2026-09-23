<?php

namespace App\Security\Voter;

use App\Entity\ArmyList;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur une liste d'armée : visible par son propriétaire ou si elle est publique,
 * modifiable / supprimable uniquement par son propriétaire.
 *
 * @extends Voter<string, ArmyList>
 */
final class ArmyListVoter extends Voter
{
    public const VIEW = 'ARMY_VIEW';
    public const EDIT = 'ARMY_EDIT';
    public const DELETE = 'ARMY_DELETE';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::DELETE];

    public function supportsAttribute(string $attribute): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, ArmyList::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof ArmyList && $this->supportsAttribute($attribute);
    }

    /** @param ArmyList $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $isOwner = $user instanceof User && $subject->getOwner() === $user;

        return match ($attribute) {
            self::VIEW => $isOwner || $subject->isPublic(),
            self::EDIT, self::DELETE => $isOwner,
            default => false,
        };
    }
}
