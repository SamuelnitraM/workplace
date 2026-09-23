<?php

namespace App\Controller;

use App\Entity\GroupInvitation;
use App\Entity\GroupMember;
use App\Entity\Notification;
use App\Entity\User;
use App\Repository\GroupInvitationRepository;
use App\Repository\GroupMemberRepository;
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
    // Envoyer une invitation depuis le profil d'un user
    #[Route('/send/{username}', name: 'send', methods: ['POST'])]
    public function send(
        string $username,
        Request $request,
        UserRepository $userRepository,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        GroupInvitationRepository $groupInvitationRepository,
        EntityManagerInterface $em,
        NotificationService $notifications,
    ): Response {
        $this->denyUnlessCsrfValid($request);

        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        $groupId = $request->request->getInt('group_id');
        $group = $groupId ? $groupRepository->find($groupId) : null;

        if (!$group) {
            $this->addFlash('error', 'Groupe introuvable.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        // Vérifier que l'inviteur est membre du groupe
        if (!$this->isGranted(GroupVoter::INVITE, $group)) {
            $this->addFlash('error', 'Vous devez être membre du groupe pour inviter.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        // Vérifier que le user n'est pas déjà membre
        $alreadyMember = $groupMemberRepository->findOneBy([
            'user' => $targetUser,
            'usergroup' => $group,
        ]);

        if ($alreadyMember) {
            $this->addFlash('error', $targetUser->getUsername() . ' est déjà membre de ce groupe.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        // Vérifier qu'une invitation n'existe pas déjà
        $existing = $groupInvitationRepository->findOneBy([
            'invitedUser' => $targetUser,
            'usergroup' => $group,
            'status' => 'pending',
        ]);

        if ($existing) {
            $this->addFlash('error', 'Une invitation est déjà en attente pour ce groupe.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        $invitation = new GroupInvitation();
        $invitation->setInvitedBy($currentUser);
        $invitation->setInvitedUser($targetUser);
        $invitation->setUsergroup($group);

        $em->persist($invitation);
        $em->flush();

        $notifications->notify(
            $targetUser,
            Notification::TYPE_GROUP_INVITATION,
            $currentUser,
            ['group' => $group->getName(), 'groupId' => $group->getId()],
            $this->generateUrl('app_group_invitation_list'),
            self::invitationKey($group->getId()),
        );

        $this->addFlash('success', $targetUser->getUsername() . ' a été invité dans ' . $group->getName() . ' !');
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Accepter une invitation
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

        // Une invitation déjà utilisée (acceptée/refusée) ne peut pas resservir, par ex. après une exclusion
        if (!$invitation || $invitation->getInvitedUser() !== $currentUser || $invitation->getStatus() !== 'pending') {
            throw $this->createAccessDeniedException();
        }

        // Vérifier que l'user n'est pas déjà membre
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
        $notificationRepository->markReadByGroupKey($currentUser, self::invitationKey($group->getId()));
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

    // Refuser une invitation
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

        // Une invitation déjà utilisée (acceptée/refusée) ne peut pas resservir, par ex. après une exclusion
        if (!$invitation || $invitation->getInvitedUser() !== $currentUser || $invitation->getStatus() !== 'pending') {
            throw $this->createAccessDeniedException();
        }

        $invitation->setStatus('refused');
        $em->flush();
        $notificationRepository->markReadByGroupKey($currentUser, self::invitationKey($invitation->getUsergroup()->getId()));

        $this->addFlash('info', 'Invitation refusée.');
        return $this->redirectToRoute('app_group_invitation_list');
    }

    // Liste des invitations reçues
    #[Route('/list', name: 'list')]
    public function list(GroupInvitationRepository $groupInvitationRepository, NotificationRepository $notificationRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        // Les invitations en attente sont affichées sur cette page : leurs notifications sont lues
        $notificationRepository->markReadByTypes($currentUser, [Notification::TYPE_GROUP_INVITATION]);

        $pendingInvitations = $groupInvitationRepository->findBy([
            'invitedUser' => $currentUser,
            'status' => 'pending',
        ]);

        return $this->render('group_invitation/list.html.twig', [
            'invitations' => $pendingInvitations,
        ]);
    }
    private function denyUnlessCsrfValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid('group_invite', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    /** Clé d'agrégation d'une invitation (une seule notification par groupe). */
    private static function invitationKey(int $groupId): string
    {
        return 'group_invitation:' . $groupId;
    }
}
