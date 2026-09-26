<?php

namespace App\Group;

use App\Entity\Group;
use App\Entity\GroupInvitation;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\GroupInvitationRepository;
use App\Security\Voter\GroupMembershipResolver;
use App\Service\MemberBlocker;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Invitations to join a group (right to invite checked beforehand with GroupVoter::INVITE): the invited member
 * must not be a member already, nor have a pending invitation, nor be blocked either way with the inviter.
 * The invited member is notified; the notification leads to the invitations column of the groups page.
 */
final class GroupInvitationSender
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly GroupInvitationRepository $invitationRepository,
        private readonly GroupMembershipResolver $membership,
        private readonly MemberBlocker $memberBlocker,
        private readonly NotificationService $notifications,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** Invites a member; returns null on success, otherwise the reason in French. */
    public function invite(Group $group, User $inviter, User $invited): ?string
    {
        if ($this->memberBlocker->isBlockedEitherWay($inviter, $invited)) {
            return sprintf('Impossible d\'inviter %s.', $invited->getUsername());
        }
        if ($this->membership->getMember($invited, $group) !== null) {
            return sprintf('%s est déjà membre de ce groupe.', $invited->getUsername());
        }
        if ($this->invitationRepository->findOneBy(['invitedUser' => $invited, 'usergroup' => $group, 'status' => 'pending']) !== null) {
            return sprintf('Une invitation est déjà en attente pour %s.', $invited->getUsername());
        }
        $invitation = (new GroupInvitation())->setInvitedBy($inviter)->setInvitedUser($invited)->setUsergroup($group);
        $this->entityManager->persist($invitation);
        $this->entityManager->flush();
        $this->notifications->notify(
            $invited,
            Notification::TYPE_GROUP_INVITATION,
            $inviter,
            ['group' => $group->getName(), 'groupId' => $group->getId()],
            $this->urlGenerator->generate('app_group_index', ['_fragment' => 'invitations']),
            self::notificationKey($group),
        );
        return null;
    }

    /** Aggregation key of the invitation notifications of a group (one notification per group). */
    public static function notificationKey(Group $group): string
    {
        return 'group_invitation:' . $group->getId();
    }
}
