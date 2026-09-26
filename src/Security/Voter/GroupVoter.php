<?php

namespace App\Security\Voter;

use App\Entity\Group;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Rights on a group. Role hierarchy: member < admin < owner (GroupMember::ROLE_LEVELS).
 *
 * @extends Voter<string, Group>
 */
final class GroupVoter extends Voter
{
    /** View the group page: public group, or member of a private group. */
    public const VIEW = 'GROUP_VIEW';
    /** Be a member (whatever the role): chat, to-do, leave... */
    public const MEMBER = 'GROUP_MEMBER';
    /** Admin or owner: settings, channel permissions (the to-do depends on TODO_WRITE). */
    public const MANAGE = 'GROUP_MANAGE';
    /** Owner only: delete the group, remove members, change roles, create/delete a channel. */
    public const OWNER = 'GROUP_OWNER';
    /** Invite someone: member with at least Group::inviteRole, or any member of a free-access group. */
    public const INVITE = 'GROUP_INVITE';
    /** Join freely: logged in, not yet a member, group public AND open to join requests. */
    public const JOIN = 'GROUP_JOIN';
    /**
     * "Writer" of the group to-do: member with at least the Group::todoWriteRole role.
     * Creates lists/categories/tasks, renames, assigns, deletes, progresses any task.
     */
    public const TODO_WRITE = 'TODO_WRITE';
    /**
     * View the WHOLE group to-do (read-only unless writer): member with at least the
     * Group::todoViewRole role, or writer. Otherwise, a member only sees what is assigned to them.
     */
    public const TODO_VIEW_ALL = 'TODO_VIEW_ALL';

    private const ATTRIBUTES = [self::VIEW, self::MEMBER, self::MANAGE, self::OWNER, self::INVITE, self::JOIN, self::TODO_WRITE, self::TODO_VIEW_ALL];

    public function __construct(private GroupMembershipResolver $membership) {}

    public function supportsAttribute(string $attribute): bool
    {
        return in_array($attribute, self::ATTRIBUTES, true);
    }

    public function supportsType(string $subjectType): bool
    {
        return is_a($subjectType, Group::class, true);
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Group && $this->supportsAttribute($attribute);
    }

    /** @param Group $subject */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $user = $user instanceof User ? $user : null;

        if ($attribute === self::VIEW && $subject->isPublic()) {
            return true;
        }

        if (!$user) {
            return false;
        }

        $member = $this->membership->getMember($user, $subject);

        return match ($attribute) {
            self::VIEW, self::MEMBER => $member !== null,
            self::INVITE => $member !== null && ($subject->isOpenAccess() || $member->hasAtLeastRole($subject->getInviteRole())),
            self::MANAGE => $member !== null && $member->hasAtLeastRole('admin'),
            self::OWNER => $member !== null && $member->getRole() === 'owner',
            self::JOIN => $member === null && $subject->isPublic() && $subject->isJoinable(),
            self::TODO_WRITE => $member !== null && $member->hasAtLeastRole($subject->getTodoWriteRole()),
            self::TODO_VIEW_ALL => $member !== null && (
                $member->hasAtLeastRole($subject->getTodoViewRole())
                || $member->hasAtLeastRole($subject->getTodoWriteRole())
            ),
            default => false,
        };
    }
}
