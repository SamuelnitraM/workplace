<?php

namespace App\Account;

use App\Entity\ArmyList;
use App\Entity\Friendship;
use App\Entity\GalleryPhoto;
use App\Entity\Group;
use App\Entity\GroupInvitation;
use App\Entity\GroupMember;
use App\Entity\GroupMessage;
use App\Entity\Post;
use App\Entity\PrivateConversation;
use App\Entity\Thread;
use App\Entity\TodoNode;
use App\Entity\User;
use App\Mailer\TransactionalMailer;
use App\Profile\ProfileImage;
use App\Repository\UserRepository;
use App\Service\GalleryPhotoUploader;
use App\Service\ProfileImageUploader;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletion of a member account, the same for every case: the member in the settings, an administrator,
 * the purge of inactive accounts (app:accounts:purge-inactive).
 *
 * - Anonymised: forum threads and replies, group messages and the tasks the member created in groups,
 *   moved to the « Membre supprimé » account (UserRepository::deletedMemberAccount()), so discussions stay readable.
 * - Handed over: a group the member owns goes to its oldest admin, else to its oldest member; without other member it is deleted.
 * - Deleted: profile and images, gallery, army lists, private conversations, friendships, invitations, memberships,
 *   personal tasks; the database removes the rest (badges, notifications, likes, votes, appeals… ON DELETE CASCADE).
 * The member receives a confirmation e-mail before the deletion.
 */
class AccountDeleter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $userRepository,
        private readonly GalleryPhotoUploader $galleryPhotoUploader,
        private readonly ProfileImageUploader $profileImageUploader,
        private readonly TransactionalMailer $mailer,
    ) {
    }

    /** @param string $reason reason given in the confirmation e-mail (member request, administrator, inactivity) */
    public function delete(User $member, string $reason): void
    {
        if ($member->isDeletedMemberAccount()) {
            throw new \LogicException('The « Membre supprimé » account cannot be deleted.');
        }
        $this->mailer->send($member, 'Ton compte SprueHub est supprimé', 'email/account_deleted.html.twig', ['reason' => $reason]);
        $replacement = $this->userRepository->deletedMemberAccount();
        $this->anonymiseContributions($member, $replacement);
        $this->handOverGroups($member, $replacement);
        $this->removePersonalContent($member);
        $profileImageFiles = array_map(static fn (ProfileImage $kind): array => [$kind, $kind->filenameOf($member)], ProfileImage::cases());
        $this->em->remove($member);
        $this->em->flush();
        foreach ($profileImageFiles as [$kind, $filename]) {
            $this->profileImageUploader->deleteFile($kind, $filename);
        }
    }

    private function anonymiseContributions(User $member, User $replacement): void
    {
        foreach ([Thread::class => 'author', Post::class => 'author', GroupMessage::class => 'author'] as $entity => $field) {
            $this->em->createQueryBuilder()->update($entity, 'contribution')
                ->set('contribution.' . $field, ':replacement')
                ->where('contribution.' . $field . ' = :member')
                ->setParameter('replacement', $replacement)
                ->setParameter('member', $member)
                ->getQuery()->execute();
        }
        $this->em->createQueryBuilder()->update(TodoNode::class, 'node')
            ->set('node.owner', ':replacement')
            ->where('node.owner = :member')
            ->andWhere('node.usergroup IS NOT NULL')
            ->setParameter('replacement', $replacement)
            ->setParameter('member', $member)
            ->getQuery()->execute();
    }

    private function handOverGroups(User $member, User $replacement): void
    {
        foreach ($this->em->getRepository(GroupMember::class)->findBy(['user' => $member, 'role' => 'owner']) as $ownership) {
            $group = $ownership->getUsergroup();
            $successor = $this->successorIn($group, $member);
            if ($successor === null) {
                $this->em->remove($group);
                continue;
            }
            $successor->setRole('owner');
            $group->setCreator($successor->getUser());
        }
        $this->em->createQueryBuilder()->update(Group::class, 'grp')
            ->set('grp.creator', ':replacement')
            ->where('grp.creator = :member')
            ->setParameter('replacement', $replacement)
            ->setParameter('member', $member)
            ->getQuery()->execute();
        $this->em->flush();
    }

    /** Oldest admin of the group, else its oldest member, other than the leaving owner. */
    private function successorIn(Group $group, User $leavingOwner): ?GroupMember
    {
        $candidates = array_filter($group->getMembers()->toArray(), static fn (GroupMember $member): bool => $member->getUser() !== $leavingOwner);
        usort($candidates, static fn (GroupMember $left, GroupMember $right): int => [$left->getRole() !== 'admin', $left->getJoinedAt()] <=> [$right->getRole() !== 'admin', $right->getJoinedAt()]);
        return $candidates[0] ?? null;
    }

    private function removePersonalContent(User $member): void
    {
        foreach ($this->em->getRepository(GalleryPhoto::class)->findBy(['owner' => $member]) as $photo) {
            $this->galleryPhotoUploader->delete($photo);
        }
        foreach ($this->em->getRepository(ArmyList::class)->findBy(['owner' => $member]) as $armyList) {
            $this->em->remove($armyList);
        }
        foreach ([['participant1' => $member], ['participant2' => $member]] as $criteria) {
            foreach ($this->em->getRepository(PrivateConversation::class)->findBy($criteria) as $conversation) {
                $this->em->remove($conversation);
            }
        }
        foreach ([['requester' => $member], ['receiver' => $member]] as $criteria) {
            foreach ($this->em->getRepository(Friendship::class)->findBy($criteria) as $friendship) {
                $this->em->remove($friendship);
            }
        }
        foreach ([['invitedUser' => $member], ['invitedBy' => $member]] as $criteria) {
            foreach ($this->em->getRepository(GroupInvitation::class)->findBy($criteria) as $invitation) {
                $this->em->remove($invitation);
            }
        }
        foreach ($this->em->getRepository(GroupMember::class)->findBy(['user' => $member]) as $membership) {
            $this->em->remove($membership);
        }
        foreach ($this->em->getRepository(TodoNode::class)->findBy(['owner' => $member, 'usergroup' => null, 'parent' => null]) as $personalList) {
            $this->em->remove($personalList);
        }
        $this->em->flush();
    }
}
