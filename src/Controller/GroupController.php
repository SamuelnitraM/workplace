<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Repository\GroupRepository;
use App\Repository\GroupMemberRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\SluggerInterface;

#[Route('/groups', name: 'app_group_')]
class GroupController extends AbstractController
{
    // Liste des groupes publics
    #[Route('/', name: 'index')]
    public function index(GroupRepository $groupRepository): Response
    {
        $myGroups = [];
        $myGroupIds = [];

        if ($this->getUser()) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $myGroups = $groupRepository->findGroupsByMember($user);
            $myGroupIds = array_map(fn($g) => $g->getId(), $myGroups);
        }

        $publicGroups = $groupRepository->findPublicGroupsNotMember($myGroupIds);

        return $this->render('group/index.html.twig', [
            'publicGroups' => $publicGroups,
            'myGroups' => $myGroups,
        ]);
    }

    // Créer un groupe
    #[Route('/new', name: 'new')]
    #[IsGranted('ROLE_USER')]
    public function new(
        Request $request,
        EntityManagerInterface $em,
        SluggerInterface $slugger
    ): Response {
        if ($request->isMethod('POST')) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();

            $group = new Group();
            $group->setName($request->request->get('name'));
            $group->setDescription($request->request->get('description'));
            $group->setSlug(strtolower($slugger->slug($request->request->get('name'))) . '-' . uniqid());
            $group->setIsPublic($request->request->get('isPublic') === '1');
            $group->setIsJoinable($request->request->get('isJoinable') === '1');
            $group->setCreator($user);

            // Le créateur devient automatiquement owner
            $member = new GroupMember();
            $member->setUser($user);
            $member->setUsergroup($group);
            $member->setRole('owner');

            $em->persist($group);
            $em->persist($member);
            $em->flush();

            $this->addFlash('success', 'Groupe créé avec succès !');
            return $this->redirectToRoute('app_group_show', ['slug' => $group->getSlug()]);
        }

        return $this->render('group/new.html.twig');
    }

    // Page du groupe
    #[Route('/{slug}', name: 'show')]
    public function show(
        string $slug,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository
    ): Response {
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        // Vérifier si le groupe est privé
        if (!$group->isPublic() && !$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $currentMember = null;
        if ($this->getUser()) {
            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $currentMember = $groupMemberRepository->findOneBy([
                'user' => $user,
                'usergroup' => $group,
            ]);

            // Groupe privé : seuls les membres peuvent voir
            if (!$group->isPublic() && !$currentMember) {
                $this->addFlash('error', 'Ce groupe est privé.');
                return $this->redirectToRoute('app_group_index');
            }
        }

        $messages = $group->getMessages()->toArray();
        usort($messages, fn($a, $b) => $a->getCreatedAt() <=> $b->getCreatedAt());

        return $this->render('group/show.html.twig', [
            'group' => $group,
            'currentMember' => $currentMember,
            'messages' => $messages,
        ]);
    }

    // Rejoindre un groupe
    #[Route('/{slug}/join', name: 'join', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function join(
        string $slug,
        Request $request,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        // Vérifier si déjà membre
        $existing = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if ($existing) {
            $this->addFlash('error', 'Vous êtes déjà membre de ce groupe.');
            return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
        }

        if (!$group->isJoinable()) {
            $this->addFlash('error', 'Ce groupe n\'accepte pas de nouvelles demandes.');
            return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
        }

        $member = new GroupMember();
        $member->setUser($user);
        $member->setUsergroup($group);
        $member->setRole('member');

        $em->persist($member);
        $em->flush();

        $this->addFlash('success', 'Vous avez rejoint le groupe !');
        return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
    }

    // Quitter un groupe
    #[Route('/{slug}/leave', name: 'leave', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function leave(
        string $slug,
        Request $request,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        $member = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$member) {
            $this->addFlash('error', 'Vous n\'êtes pas membre de ce groupe.');
            return $this->redirectToRoute('app_group_index');
        }

        if ($member->getRole() === 'owner') {
            $this->addFlash('error', 'Le owner ne peut pas quitter le groupe. Supprimez-le ou transférez la propriété.');
            return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
        }

        $em->remove($member);
        $em->flush();

        $this->addFlash('success', 'Vous avez quitté le groupe.');
        return $this->redirectToRoute('app_group_index');
    }

    // Envoyer un message dans le chat
    #[Route('/{slug}/message', name: 'message', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function message(
        string $slug,
        Request $request,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        $member = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$member) {
            throw $this->createAccessDeniedException();
        }

        $content = trim($request->request->get('content', ''));
        if (empty($content)) {
            return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
        }

        $message = new \App\Entity\GroupMessage();
        $message->setContent($content);
        $message->setAuthor($user);
        $message->setUsergroup($group);

        $em->persist($message);
        $em->flush();

        return $this->redirectToRoute('app_group_show', ['slug' => $slug]);
    }

    #[Route('/{slug}/edit', name: 'edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(
        string $slug,
        Request $request,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$currentMember || !in_array($currentMember->getRole(), ['owner', 'admin'])) {
            throw $this->createAccessDeniedException();
        }

        // Formulaire informations générales
        if ($request->isMethod('POST') && $request->request->get('action') === 'update_info') {
            $group->setName($request->request->get('name'));
            $group->setDescription($request->request->get('description'));
            $group->setIsPublic($request->request->get('isPublic') === '1');
            $group->setIsJoinable($request->request->get('isJoinable') === '1');

            $em->flush();

            $this->addFlash('success', 'Paramètres mis à jour !');
            return $this->redirectToRoute('app_group_edit', ['slug' => $group->getSlug()]);
        }

        // Formulaire modification de rôle (owner uniquement)
        if ($request->isMethod('POST') && $request->request->get('action') === 'update_role'
            && $currentMember->getRole() === 'owner') {
            $memberId = $request->request->get('member_id');
            $newRole = $request->request->get('role');
            $targetMember = $groupMemberRepository->find($memberId);

            if ($targetMember && $targetMember->getUsergroup() === $group
                && $targetMember->getRole() !== 'owner'
                && in_array($newRole, ['admin', 'member'])) {
                $targetMember->setRole($newRole);
                $em->flush();
                $this->addFlash('success', 'Rôle mis à jour !');
            }

            return $this->redirectToRoute('app_group_edit', ['slug' => $group->getSlug()]);
        }

        return $this->render('group/edit.html.twig', [
            'group' => $group,
            'currentMember' => $currentMember,
        ]);
    }

    // Exclure un membre
    #[Route('/{slug}/kick/{memberId}', name: 'kick', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function kick(
        string $slug,
        int $memberId,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$currentMember || $currentMember->getRole() !== 'owner') {
            throw $this->createAccessDeniedException();
        }

        $targetMember = $groupMemberRepository->find($memberId);
        if ($targetMember && $targetMember->getUsergroup() === $group
            && $targetMember->getRole() !== 'owner') {
            $em->remove($targetMember);
            $em->flush();
            $this->addFlash('success', 'Membre exclu du groupe.');
        }

        return $this->redirectToRoute('app_group_edit', ['slug' => $slug]);
    }

    // Supprimer le groupe
    #[Route('/{slug}/delete', name: 'delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(
        string $slug,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$currentMember || $currentMember->getRole() !== 'owner') {
            throw $this->createAccessDeniedException();
        }

        $em->remove($group);
        $em->flush();

        $this->addFlash('success', 'Groupe supprimé.');
        return $this->redirectToRoute('app_group_index');
    }
}