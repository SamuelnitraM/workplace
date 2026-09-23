<?php

namespace App\Security\Voter;

use App\Entity\GroupChannel;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un channel de groupe : membre du groupe dont le rôle est au moins
 * celui requis par le channel (canRead / canWrite).
 *
 * @extends Voter<string, GroupChannel>
 */
final class GroupChannelVoter extends Voter
{
    public const READ = 'CHANNEL_READ';
    public const WRITE = 'CHANNEL_WRITE';

    public function __construct(private GroupMembershipResolver $membership) {}

    public function supportsAttribute(string $attribute): bool
    {
        return $attribute === self::READ || $attribute === self::WRITE;
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, GroupChannel::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof GroupChannel && $this->supportsAttribute($attribute);
    }

    /** @param GroupChannel $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $member = $this->membership->getMember($user, $subject->getUsergroup());
        if (!$member) {
            return false;
        }

        return $member->hasAtLeastRole($attribute === self::READ ? $subject->getCanRead() : $subject->getCanWrite());
    }
}
