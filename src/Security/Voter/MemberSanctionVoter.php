<?php

namespace App\Security\Voter;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Who may suspend a member or lift a suspension:
 *  - a moderator sanctions regular members only;
 *  - an administrator also sanctions moderators;
 *  - nobody sanctions an administrator or themselves.
 *
 * @extends Voter<string, User>
 */
final class MemberSanctionVoter extends Voter
{
    public const SANCTION = 'MEMBER_SANCTION';

    public function __construct(private readonly RoleHierarchyInterface $roleHierarchy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::SANCTION && $subject instanceof User;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moderator = $token->getUser();
        if (!$moderator instanceof User || $moderator->getId() === $subject->getId()) {
            return false;
        }
        $moderatorRoles = $this->roleHierarchy->getReachableRoleNames($moderator->getRoles());
        $memberRoles = $this->roleHierarchy->getReachableRoleNames($subject->getRoles());
        return match (true) {
            in_array('ROLE_ADMIN', $memberRoles, true) => false,
            in_array('ROLE_MODERATOR', $memberRoles, true) => in_array('ROLE_ADMIN', $moderatorRoles, true),
            default => in_array('ROLE_MODERATOR', $moderatorRoles, true),
        };
    }
}
