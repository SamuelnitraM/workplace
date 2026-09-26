<?php

namespace App\Controller;

use App\Entity\GroupMember;
use App\Entity\Notification;
use App\Group\GroupInvitationSender;
use App\Repository\FriendshipRepository;
use App\Repository\GroupInvitationRepository;
use App\Repository\GroupRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Security\Voter\GroupVoter;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/group-invitation', name: 'app_group_invitation_')]
class GroupInvitationController extends AbstractController
{
    // Send an invitation from a user's profile
    #[Route('/send/{username}', name: 'send', methods: ['POST'])]
    public function send(
        string $username,
        Request $request,
        UserRepository $userRepository,
        GroupRepository $groupRepository,
        GroupInvitationSender $invitationSender,
    ): Response {
        $this->denyUnlessCsrfValid($request);

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]) ?? throw $this->createNotFoundException('Utilisateur introuvable');
        $groupId = $request->request->getInt('group_id');
        $group = $groupId ? $groupRepository->find($groupId) : null;
        $redirect = $this->redirectToRoute('app_profil_show', ['username' => $username]);

        if (!$group) {
            $this->addFlash('error', 'Groupe introuvable.');
            return $redirect;
        }
        if (!$this->isGranted(GroupVoter::INVITE, $group)) {
            $this->addFlash('error', 'Vous n\'avez pas le droit d\'inviter dans ce groupe.');
            return $redirect;
        }
        $error = $invitationSender->invite($group, $currentUser, $targetUser);
        $error !== null
            ? $this->addFlash('error', $error)
            : $this->addFlash('success', $targetUser->getUsername() . ' a été invité dans ' . $group->getName() . ' !');
        return $redirect;
    }

    /** Invitations sent from the group page: friends selected in the invitation window. */
    #[Route('/group/{slug}', name: 'send_many', methods: ['POST'])]
    public function sendMany(
        string $slug,
        Request $request,
        GroupRepository $groupRepository,
        FriendshipRepository $friendshipRepository,
        GroupInvitationSender $invitationSender,
    ): Response {
        $this->denyUnlessCsrfValid($request);
        $group = $groupRepository->findOneBy(['slug' => $slug]) ?? throw $this->createNotFoundException('Groupe introuvable');
        $this->denyAccessUnlessGranted(GroupVoter::INVITE, $group);
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $selectedIds = array_map('intval', $request->request->all('user_ids'));
        $invited = [];
        foreach ($friendshipRepository->findFriendsOf($currentUser) as $friend) {
            if (!in_array($friend->getId(), $selectedIds, true)) {
                continue;
            }
            $error = $invitationSender->invite($group, $currentUser, $friend);
            $error !== null ? $this->addFlash('error', $error) : $invited[] = $friend->getUsername();
        }
        if ($invited !== []) {
            $this->addFlash('success', sprintf('Invitation envoyée à %s.', implode(', ', $invited)));
        } elseif ($selectedIds === []) {
            $this->addFlash('warning', 'Sélectionnez au moins un ami à inviter.');
        }
        return $this->redirectToRoute('app_group_show', ['slug' => $group->getSlug()]);
    }

    // Accept an invitation
    #[Route('/accept/{id}', name: 'accept', methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        GroupInvitationRepository $groupInvitationRepository,
        EntityManagerInterface $em,
        NotificationRepository $notificationRepository,
        NotificationService $notifications,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $this->denyUnlessCsrfValid($request);
        $invitation = $groupInvitationRepository->find($id);

        // An invitation already used (accepted/declined) cannot be reused, e.g. after a removal
        if (!$invitation || $invitation->getInvitedUser() !== $currentUser || $invitation->getStatus() !== 'pending') {
            throw $this->createAccessDeniedException();
        }

        // Check that the user is not already a member
        if (!$this->isGranted(GroupVoter::MEMBER, $invitation->getUsergroup())) {
            $member = new GroupMember();
            $member->setUser($currentUser);
            $member->setUsergroup($invitation->getUsergroup());
            $member->setRole('member');
            $em->persist($member);
        }

        $invitation->setStatus('accepted');
        $em->flush();

        $group = $invitation->getUsergroup();
        $notificationRepository->markReadByGroupKey($currentUser, GroupInvitationSender::notificationKey($group));
        if ($invitation->getInvitedBy()) {
            $notifications->notify(
                $invitation->getInvitedBy(),
                Notification::TYPE_GROUP_INVITATION_ACCEPTED,
                $currentUser,
                ['group' => $group->getName(), 'groupId' => $group->getId()],
                $this->generateUrl('app_group_show', ['slug' => $group->getSlug()]),
            );
        }

        $this->addFlash('success', 'Vous avez rejoint ' . $invitation->getUsergroup()->getName() . ' !');
        return $this->redirectToRoute('app_group_show', ['slug' => $invitation->getUsergroup()->getSlug()]);
    }

    // Decline an invitation
    #[Route('/refuse/{id}', name: 'refuse', methods: ['POST'])]
    public function refuse(
        int $id,
        Request $request,
        GroupInvitationRepository $groupInvitationRepository,
        EntityManagerInterface $em,
        NotificationRepository $notificationRepository,
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $this->denyUnlessCsrfValid($request);
        $invitation = $groupInvitationRepository->find($id);

        // An invitation already used (accepted/declined) cannot be reused, e.g. after a removal
        if (!$invitation || $invitation->getInvitedUser() !== $currentUser || $invitation->getStatus() !== 'pending') {
            throw $this->createAccessDeniedException();
        }

        $invitation->setStatus('refused');
        $em->flush();
        $notificationRepository->markReadByGroupKey($currentUser, GroupInvitationSender::notificationKey($invitation->getUsergroup()));

        $this->addFlash('info', 'Invitation refusée.');
        return $this->redirectToRoute('app_group_index', ['_fragment' => 'invitations']);
    }

    /** Invitation list address targeted by some notifications: permanent redirect to the invitations of the groups page. */
    #[Route('/list', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->redirectToRoute('app_group_index', ['_fragment' => 'invitations'], Response::HTTP_MOVED_PERMANENTLY);
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid('group_invite', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }
}
