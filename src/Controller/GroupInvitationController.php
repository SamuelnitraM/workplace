<?php

namespace App\Controller;

use App\Entity\GroupInvitation;
use App\Entity\GroupMember;
use App\Repository\GroupInvitationRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
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
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $targetUser = $userRepository->findOneBy(['username' => $username]);

        if (!$targetUser) {
            throw $this->createNotFoundException('Utilisateur introuvable');
        }

        $groupId = $request->request->get('group_id');
        $group = $groupRepository->find($groupId);

        if (!$group) {
            $this->addFlash('error', 'Groupe introuvable.');
            return $this->redirectToRoute('app_profil_show', ['username' => $username]);
        }

        // Vérifier que l'inviteur est membre du groupe
        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $currentUser,
            'usergroup' => $group,
        ]);

        if (!$currentMember) {
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

        $this->addFlash('success', $targetUser->getUsername() . ' a été invité dans ' . $group->getName() . ' !');
        return $this->redirectToRoute('app_profil_show', ['username' => $username]);
    }

    // Accepter une invitation
    #[Route('/accept/{id}', name: 'accept', methods: ['POST'])]
    public function accept(
        int $id,
        GroupInvitationRepository $groupInvitationRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $invitation = $groupInvitationRepository->find($id);

        if (!$invitation || $invitation->getInvitedUser() !== $currentUser) {
            throw $this->createAccessDeniedException();
        }

        // Vérifier que l'user n'est pas déjà membre
        $alreadyMember = $groupMemberRepository->findOneBy([
            'user' => $currentUser,
            'usergroup' => $invitation->getUsergroup(),
        ]);

        if (!$alreadyMember) {
            $member = new GroupMember();
            $member->setUser($currentUser);
            $member->setUsergroup($invitation->getUsergroup());
            $member->setRole('member');
            $em->persist($member);
        }

        $invitation->setStatus('accepted');
        $em->flush();

        $this->addFlash('success', 'Vous avez rejoint ' . $invitation->getUsergroup()->getName() . ' !');
        return $this->redirectToRoute('app_group_show', ['slug' => $invitation->getUsergroup()->getSlug()]);
    }

    // Refuser une invitation
    #[Route('/refuse/{id}', name: 'refuse', methods: ['POST'])]
    public function refuse(
        int $id,
        GroupInvitationRepository $groupInvitationRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();
        $invitation = $groupInvitationRepository->find($id);

        if (!$invitation || $invitation->getInvitedUser() !== $currentUser) {
            throw $this->createAccessDeniedException();
        }

        $invitation->setStatus('refused');
        $em->flush();

        $this->addFlash('info', 'Invitation refusée.');
        return $this->redirectToRoute('app_group_invitation_list');
    }

    // Liste des invitations reçues
    #[Route('/list', name: 'list')]
    public function list(GroupInvitationRepository $groupInvitationRepository): Response
    {
        /** @var \App\Entity\User $currentUser */
        $currentUser = $this->getUser();

        $pendingInvitations = $groupInvitationRepository->findBy([
            'invitedUser' => $currentUser,
            'status' => 'pending',
        ]);

        return $this->render('group_invitation/list.html.twig', [
            'invitations' => $pendingInvitations,
        ]);
    }
}