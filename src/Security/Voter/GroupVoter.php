<?php

namespace App\Security\Voter;

use App\Entity\Group;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Droits sur un groupe. Hiérarchie des rôles : member < admin < owner (GroupMember::ROLE_LEVELS).
 *
 * @extends Voter<string, Group>
 */
final class GroupVoter extends Voter
{
    /** Voir la page du groupe : groupe public, ou membre d'un groupe privé. */
    public const VIEW = 'GROUP_VIEW';
    /** Être membre (quel que soit le rôle) : chat, todo, quitter... */
    public const MEMBER = 'GROUP_MEMBER';
    /** Admin ou owner : paramètres, droits des channels (la todo dépend de TODO_WRITE). */
    public const MANAGE = 'GROUP_MANAGE';
    /** Owner uniquement : supprimer le groupe, exclure, changer les rôles, créer/supprimer un channel. */
    public const OWNER = 'GROUP_OWNER';
    /** Inviter quelqu'un : membre avec au moins Group::inviteRole, ou tout membre d'un groupe en accès libre. */
    public const INVITE = 'GROUP_INVITE';
    /** Rejoindre librement : connecté, pas encore membre, groupe public ET ouvert aux demandes. */
    public const JOIN = 'GROUP_JOIN';
    /**
     * « Rédacteur » de la todo du groupe : membre avec au moins le rôle Group::todoWriteRole.
     * Crée listes/catégories/tâches, renomme, assigne, supprime, fait progresser toute tâche.
     */
    public const TODO_WRITE = 'TODO_WRITE';
    /**
     * Voir TOUTE la todo du groupe (lecture seule si pas rédacteur) : membre avec au moins le rôle
     * Group::todoViewRole, ou rédacteur. Sinon, un membre ne voit que ce qui lui est assigné.
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
