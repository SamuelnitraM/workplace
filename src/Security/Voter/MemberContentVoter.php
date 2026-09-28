<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * VIEW (subject: the member): the content published by a member (profile details, gallery, albums,
 * public army lists, badges, friends) is visible unless the member is banned for good
 * (App\Moderation\BannedMembers); the moderation team still sees it.
 *
 * @extends Voter<string, User>
 */
final class MemberContentVoter extends Voter
{
    public const VIEW = 'MEMBER_CONTENT_VIEW';

    public function __construct(private readonly AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var User $subject */
        return !$subject->isBanned() || $this->accessDecisionManager->decide($token, ['ROLE_MODERATOR']);
    }
}
