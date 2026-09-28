<?php

namespace App\Security\Voter;

use App\Entity\ArmyList;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Rights on an army list: visible to its owner, or when it is public and its owner is not banned
 * (MemberContentVoter); editable and deletable by its owner only.
 *
 * @extends Voter<string, ArmyList>
 */
final class ArmyListVoter extends Voter
{
    public const VIEW = 'ARMY_VIEW';
    public const EDIT = 'ARMY_EDIT';
    public const DELETE = 'ARMY_DELETE';

    private const ATTRIBUTES = [self::VIEW, self::EDIT, self::DELETE];

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

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
            self::VIEW => $isOwner || ($subject->isPublic() && $this->accessDecisionManager->decide($token, [MemberContentVoter::VIEW], $subject->getOwner())),
            self::EDIT, self::DELETE => $isOwner,
            default => false,
        };
    }
}
