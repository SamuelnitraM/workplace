<?php

namespace App\Controller;

use App\Entity\TodoNode;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\TodoNodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/groups/{slug}/todo', name: 'app_group_todo_')]
class GroupTodoController extends AbstractController
{
    // Page principale todo du groupe
    #[Route('/', name: 'index')]
    public function index(
        string $slug,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        TodoNodeRepository $todoNodeRepository
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        // Vérifier que l'user est membre
        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$currentMember) {
            throw $this->createAccessDeniedException();
        }

        // Owner/Admin voient tout, les membres voient uniquement leurs tâches
        if (in_array($currentMember->getRole(), ['owner', 'admin'])) {
            $lists = $todoNodeRepository->findBy([
                'usergroup' => $group,
                'type' => TodoNode::TYPE_LIST,
                'parent' => null,
            ], ['position' => 'ASC']);
        } else {
            $lists = $todoNodeRepository->findGroupListsForMember($group, $user);
        }

        return $this->render('group_todo/index.html.twig', [
            'group' => $group,
            'lists' => $lists,
            'currentMember' => $currentMember,
            'members' => $group->getMembers(),
            'slug' => $slug,
        ]);
    }

    // Créer un noeud (liste, catégorie ou item)
    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(
        string $slug,
        Request $request,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        TodoNodeRepository $todoNodeRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        // Seuls owner et admin peuvent créer
        if (!$currentMember || !in_array($currentMember->getRole(), ['owner', 'admin'])) {
            throw $this->createAccessDeniedException();
        }

        $title = trim($request->request->get('title', ''));
        $type = $request->request->get('type', TodoNode::TYPE_LIST);
        $parentId = $request->request->get('parent_id');
        $assignedToId = $request->request->get('assigned_to');

        if (empty($title)) {
            $this->addFlash('error', 'Le titre ne peut pas être vide.');
            return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
        }

        $node = new TodoNode();
        $node->setTitle($title);
        $node->setType($type);
        $node->setOwner($user);
        $node->setUsergroup($group);

        if ($parentId) {
            $parent = $todoNodeRepository->find($parentId);
            if ($parent && $parent->getUsergroup() === $group) {
                $node->setParent($parent);
            }
        }

        // Assigner à un membre
        if ($assignedToId) {
            $assignedTo = $userRepository->find($assignedToId);
            if ($assignedTo) {
                $node->setAssignedTo($assignedTo);
            }
        }

        // Position
        $siblings = $parentId
            ? $todoNodeRepository->findBy(['parent' => $parentId], ['position' => 'DESC'])
            : $todoNodeRepository->findBy(['usergroup' => $group, 'parent' => null], ['position' => 'DESC']);

        $node->setPosition(count($siblings) > 0 ? $siblings[0]->getPosition() + 1 : 0);

        $em->persist($node);
        $em->flush();

        return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
    }

    // Cocher/décocher un item
    #[Route('/toggle/{id}', name: 'toggle', methods: ['POST'])]
    public function toggle(
        string $slug,
        int $id,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getUsergroup() !== $group) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$currentMember) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        // Les membres ne peuvent cocher que leurs tâches assignées
        if ($currentMember->getRole() === 'member' && $node->getAssignedTo() !== $user) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $node->setIsDone(!$node->getIsDone());
        $node->setDoneAt($node->getIsDone() ? new \DateTimeImmutable() : null);
        $em->flush();

        return new JsonResponse(['isDone' => $node->getIsDone()]);
    }

    // Supprimer un noeud
    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function delete(
        string $slug,
        int $id,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getUsergroup() !== $group) {
            throw $this->createAccessDeniedException();
        }

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        // Seuls owner et admin peuvent supprimer
        if (!$currentMember || !in_array($currentMember->getRole(), ['owner', 'admin'])) {
            throw $this->createAccessDeniedException();
        }

        $em->remove($node);
        $em->flush();

        $this->addFlash('success', 'Supprimé avec succès.');
        return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
    }

    // Assigner un item à un membre
    #[Route('/assign/{id}', name: 'assign', methods: ['POST'])]
    public function assign(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        GroupMemberRepository $groupMemberRepository,
        TodoNodeRepository $todoNodeRepository,
        UserRepository $userRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getUsergroup() !== $group) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $currentMember = $groupMemberRepository->findOneBy([
            'user' => $user,
            'usergroup' => $group,
        ]);

        if (!$currentMember || !in_array($currentMember->getRole(), ['owner', 'admin'])) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $assignedToId = $request->request->get('assigned_to');
        if ($assignedToId) {
            $assignedTo = $userRepository->find($assignedToId);
            $node->setAssignedTo($assignedTo ?: null);
        } else {
            $node->setAssignedTo(null);
        }

        $em->flush();

        return new JsonResponse([
            'assignedTo' => $node->getAssignedTo()?->getUsername() ?? null
        ]);
    }
}