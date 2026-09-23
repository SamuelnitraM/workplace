<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\TodoNode;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\TodoNodeRepository;
use App\Security\Voter\GroupMembershipResolver;
use App\Security\Voter\GroupVoter;
use App\Security\Voter\TodoNodeVoter;
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
        GroupMembershipResolver $membership,
        TodoNodeRepository $todoNodeRepository
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        // Vérifier que l'user est membre
        $this->denyAccessUnlessGranted(GroupVoter::MEMBER, $group);

        // Rédacteurs et lecteurs (rôles >= réglages du groupe) voient tout ; les autres membres seulement les listes
        // où une catégorie ou une tâche leur est assignée (le template filtre ensuite via TODO_VIEW)
        $lists = $todoNodeRepository->findGroupLists(
            $group,
            $this->isGranted(GroupVoter::TODO_VIEW_ALL, $group) ? null : $user
        );

        return $this->render('group_todo/index.html.twig', [
            'group' => $group,
            'lists' => $lists,
            'currentMember' => $membership->getMember($user, $group),
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
        EntityManagerInterface $em
    ): Response {
        if (!$this->isTodoCsrfValid($request)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $group = $groupRepository->findOneBy(['slug' => $slug]);

        if (!$group) {
            throw $this->createNotFoundException('Groupe introuvable');
        }

        // Membre du groupe au minimum ; le droit précis (liste / enfant du parent) est vérifié plus bas
        $this->denyAccessUnlessGranted(GroupVoter::MEMBER, $group);

        $title = trim((string) $request->request->get('title', ''));
        $type = (string) $request->request->get('type', TodoNode::TYPE_LIST);
        $parentId = $request->request->get('parent_id');
        $assignedToId = $request->request->get('assigned_to');

        if (empty($title)) {
            $this->addFlash('error', 'Le titre ne peut pas être vide.');
            return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
        }

        // Hiérarchie attendue : liste > catégorie > tâche
        // array_key_exists et non ?? : le type liste a volontairement un parent attendu null
        $expectedParentType = array_key_exists($type, TodoNode::EXPECTED_PARENT_TYPES) ? TodoNode::EXPECTED_PARENT_TYPES[$type] : false;
        if ($expectedParentType === false) {
            $this->addFlash('error', 'Type d\'élément invalide.');
            return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
        }

        $parent = null;
        if ($expectedParentType !== null) {
            $parent = $parentId ? $todoNodeRepository->find((int) $parentId) : null;
            if (!$parent || $parent->getUsergroup() !== $group || $parent->getType() !== $expectedParentType) {
                $this->addFlash('error', 'Élément parent invalide.');
                return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
            }
            // Catégorie : rédacteurs ; tâche : rédacteurs ou membre assigné à la catégorie
            $this->denyAccessUnlessGranted(TodoNodeVoter::CREATE_CHILD, $parent);
        } else {
            // Nouvelle liste : rédacteurs uniquement
            $this->denyAccessUnlessGranted(GroupVoter::TODO_WRITE, $group);
        }

        $node = new TodoNode();
        $node->setTitle(mb_substr($title, 0, 150));
        $node->setType($type);
        $node->setOwner($user);
        $node->setUsergroup($group);
        $node->setParent($parent);

        // Assigner à un membre du groupe (rédacteurs uniquement ; une tâche créée par un membre
        // assigné à la catégorie reste non assignée : l'assignation de la catégorie lui donne déjà les droits)
        if ($assignedToId) {
            $this->denyAccessUnlessGranted(TodoNodeVoter::ASSIGN, $node);
            $assignedTo = $this->findGroupMemberUser($group, (int) $assignedToId, $groupMemberRepository);
            if (!$assignedTo) {
                $this->addFlash('error', 'Cet utilisateur n\'est pas membre du groupe.');
                return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
            }
            $node->setAssignedTo($assignedTo);
        }

        // Position
        $siblings = $parent
            ? $todoNodeRepository->findBy(['parent' => $parent], ['position' => 'DESC'], 1)
            : $todoNodeRepository->findBy(['usergroup' => $group, 'parent' => null], ['position' => 'DESC'], 1);

        $node->setPosition(count($siblings) > 0 ? $siblings[0]->getPosition() + 1 : 0);

        $em->persist($node);
        $em->flush();

        return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
    }

    // Supprimer un noeud
    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function delete(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): Response {
        if (!$this->isTodoCsrfValid($request)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        if (!$group || !$node || $node->getUsergroup() !== $group) {
            throw $this->createAccessDeniedException();
        }

        // Rédacteurs de la todo uniquement
        $this->denyAccessUnlessGranted(TodoNodeVoter::DELETE, $node);

        $em->remove($node);
        $em->flush();

        $this->addFlash('success', 'Supprimé avec succès.');
        return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
    }

    // Renommer un noeud
    #[Route('/rename/{id}', name: 'rename', methods: ['POST'])]
    public function rename(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        if (!$group || !$node || $node->getUsergroup() !== $group) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        // Rédacteurs de la todo uniquement
        if (!$this->isGranted(TodoNodeVoter::EDIT, $node)) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $title = trim((string) $request->request->get('title', ''));
        if (empty($title)) {
            return new JsonResponse(['error' => 'Titre vide'], 400);
        }

        $node->setTitle(mb_substr($title, 0, 150));
        $em->flush();

        return new JsonResponse(['title' => $node->getTitle()]);
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
        EntityManagerInterface $em
    ): JsonResponse {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        if (!$group || !$node || $node->getUsergroup() !== $group) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        // Rédacteurs de la todo uniquement
        if (!$this->isGranted(TodoNodeVoter::ASSIGN, $node)) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $assignedToId = $request->request->get('assigned_to');
        if ($assignedToId) {
            $assignedTo = $this->findGroupMemberUser($group, (int) $assignedToId, $groupMemberRepository);
            if (!$assignedTo) {
                return new JsonResponse(['error' => 'Cet utilisateur n\'est pas membre du groupe'], 400);
            }
            $node->setAssignedTo($assignedTo);
        } else {
            $node->setAssignedTo(null);
        }

        $em->flush();

        return new JsonResponse([
            'assignedTo' => $node->getAssignedTo()?->getUsername() ?? null
        ]);
    }

    // Augmenter la progression d'une tâche
    #[Route('/progress/up/{id}', name: 'progress_up', methods: ['POST'])]
    public function progressUp(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $node = $this->findEditableItem($slug, $id, $request, $groupRepository, $todoNodeRepository);
        if ($node instanceof JsonResponse) {
            return $node;
        }

        $currentProgress = $node->getProgress() ?? 0;
        $newProgress = min(100, $currentProgress + 25);
        $node->setProgress($newProgress);

        if ($newProgress === 100) {
            $node->setIsDone(true);
            $node->setDoneAt(new \DateTimeImmutable());
        }

        $em->flush();

        return new JsonResponse([
            'progress' => $newProgress,
            'isDone' => $node->isDone(),
            'type' => 'increment'
        ]);
    }

    // Réduire la progression d'une tâche
    #[Route('/progress/down/{id}', name: 'progress_down', methods: ['POST'])]
    public function progressDown(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $node = $this->findEditableItem($slug, $id, $request, $groupRepository, $todoNodeRepository);
        if ($node instanceof JsonResponse) {
            return $node;
        }

        $currentProgress = $node->getProgress() ?? 0;
        $newProgress = max(0, $currentProgress - 25);
        $node->setProgress($newProgress);

        if ($newProgress < 100) {
            $node->setIsDone(false);
            $node->setDoneAt(null);
        }

        $em->flush();

        return new JsonResponse([
            'progress' => $newProgress,
            'isDone' => $node->isDone(),
            'type' => 'decrement'
        ]);
    }

    // Valider/compléter une tâche (passer à 100%)
    #[Route('/progress/validate/{id}', name: 'progress_validate', methods: ['POST'])]
    public function progressValidate(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        TodoNodeRepository $todoNodeRepository,
        EntityManagerInterface $em
    ): JsonResponse {
        $node = $this->findEditableItem($slug, $id, $request, $groupRepository, $todoNodeRepository);
        if ($node instanceof JsonResponse) {
            return $node;
        }

        $node->setProgress(100);
        $node->setIsDone(true);
        $node->setDoneAt(new \DateTimeImmutable());

        $em->flush();

        return new JsonResponse([
            'progress' => 100,
            'isDone' => true,
            'type' => 'validate'
        ]);
    }

    // Tâche (type item) du groupe que l'utilisateur courant peut faire progresser,
    // ou une réponse JSON d'erreur (CSRF invalide / non autorisé)
    private function findEditableItem(
        string $slug,
        int $id,
        Request $request,
        GroupRepository $groupRepository,
        TodoNodeRepository $todoNodeRepository
    ): TodoNode|JsonResponse {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);

        // Tâche (type item) du groupe : rédacteurs, ou membre assigné à la tâche ou à sa catégorie
        if (!$group || !$node || $node->getUsergroup() !== $group || !$this->isGranted(TodoNodeVoter::PROGRESS, $node)) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        return $node;
    }

    // Utilisateur membre du groupe correspondant à l'id donné, sinon null
    private function findGroupMemberUser(Group $group, int $userId, GroupMemberRepository $groupMemberRepository): ?User
    {
        $member = $groupMemberRepository->findOneBy([
            'user' => $userId,
            'usergroup' => $group,
        ]);

        return $member?->getUser();
    }

    // Jeton CSRF envoyé via le champ _token (formulaires) ou l'en-tête X-CSRF-Token (fetch)
    private function isTodoCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');

        return $this->isCsrfTokenValid('todo', (string) $token);
    }
}
