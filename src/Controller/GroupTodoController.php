<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\TodoNode;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\TodoAssignmentRepository;
use App\Repository\TodoNodeRepository;
use App\Todo\TodoAssignmentManager;
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
            // Catégorie et tâche : rédacteurs
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

        // Position
        $siblings = $parent
            ? $todoNodeRepository->findBy(['parent' => $parent], ['position' => 'DESC'], 1)
            : $todoNodeRepository->findBy(['usergroup' => $group, 'parent' => null], ['position' => 'DESC'], 1);

        $node->setPosition(count($siblings) > 0 ? $siblings[0]->getPosition() + 1 : 0);

        $em->persist($node);
        $em->flush();

        // The page opens again on the list that received the new element (lists are collapsed by default)
        return $this->redirectToList($slug, $node);
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

        $parent = $node->getParent();
        $em->remove($node);
        $em->flush();

        $this->addFlash('success', 'Supprimé avec succès.');
        return $parent !== null ? $this->redirectToList($slug, $parent) : $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
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

    // Assignations d'une tâche (App\Todo\TodoAssignmentManager) : réponses JSON avec les fragments à jour
    // (assignés de la tâche, résumé de sa catégorie)

    /** The current member takes the task: pending request, or direct assignment in free mode. */
    #[Route('/task/{id}/request', name: 'assignment_request', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function requestAssignment(string $slug, int $id, Request $request, GroupRepository $groupRepository, TodoNodeRepository $todoNodeRepository, TodoAssignmentManager $assignments): JsonResponse
    {
        $task = $this->findGroupNode($slug, $id, $request, $groupRepository, $todoNodeRepository, TodoNodeVoter::REQUEST_ASSIGNMENT);
        if ($task instanceof JsonResponse) {
            return $task;
        }
        return $this->assignmentResponse($task, $assignments->request($task, $this->currentUser()));
    }

    /** A manager assigns a member of the group. */
    #[Route('/task/{id}/assign', name: 'assignment_assign', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function assign(string $slug, int $id, Request $request, GroupRepository $groupRepository, TodoNodeRepository $todoNodeRepository, GroupMemberRepository $groupMemberRepository, TodoAssignmentManager $assignments): JsonResponse
    {
        $task = $this->findGroupNode($slug, $id, $request, $groupRepository, $todoNodeRepository, TodoNodeVoter::ASSIGN);
        if ($task instanceof JsonResponse) {
            return $task;
        }
        $member = $this->findGroupMemberUser($task->getUsergroup(), $request->request->getInt('user_id'), $groupMemberRepository);
        if ($member === null) {
            return new JsonResponse(['error' => 'Cet utilisateur n\'est pas membre du groupe.'], Response::HTTP_BAD_REQUEST);
        }
        return $this->assignmentResponse($task, $assignments->assign($task, $member, $this->currentUser()));
    }

    /** Decision on an assignment: accept or refuse a request, remove an assignee (managers), withdraw one's own request. */
    #[Route('/assignment/{id}/{decision}', name: 'assignment_decision', methods: ['POST'], requirements: ['id' => '\d+', 'decision' => 'accept|refuse|remove|withdraw'])]
    public function decideAssignment(string $slug, int $id, string $decision, Request $request, GroupRepository $groupRepository, TodoAssignmentRepository $assignmentRepository, TodoAssignmentManager $assignments): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], Response::HTTP_FORBIDDEN);
        }
        $assignment = $assignmentRepository->find($id);
        $task = $assignment?->getNode();
        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $right = $decision === 'withdraw' ? TodoNodeVoter::WITHDRAW_REQUEST : TodoNodeVoter::ASSIGN;
        if ($assignment === null || $group === null || $task->getUsergroup() !== $group || !$this->isGranted($right, $task)) {
            return new JsonResponse(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }
        $error = null;
        match ($decision) {
            'accept' => $error = $assignments->accept($assignment, $this->currentUser()),
            'refuse' => $assignment->isPending() ? $assignments->refuse($assignment, $this->currentUser()) : $assignments->remove($assignment),
            default => $assignments->remove($assignment),
        };
        return $this->assignmentResponse($task, $error);
    }

    /** Refreshed fragments of a task after an assignment change (and the error message, if any). */
    private function assignmentResponse(TodoNode $task, ?string $error): JsonResponse
    {
        $category = $task->getParent();
        return new JsonResponse([
            'error' => $error,
            'taskId' => $task->getId(),
            'task' => $this->renderView('group_todo/_task_assignees.html.twig', ['item' => $task, 'group' => $task->getUsergroup()]),
            'categoryId' => $category?->getId(),
            'category' => $category !== null ? $this->renderView('group_todo/_category_assignees.html.twig', ['category' => $category]) : '',
        ], $error !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /** Node of the group on which the current member holds the given right, or a JSON error response. */
    private function findGroupNode(string $slug, int $id, Request $request, GroupRepository $groupRepository, TodoNodeRepository $todoNodeRepository, string $right): TodoNode|JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], Response::HTTP_FORBIDDEN);
        }
        $group = $groupRepository->findOneBy(['slug' => $slug]);
        $node = $todoNodeRepository->find($id);
        if ($group === null || $node === null || $node->getUsergroup() !== $group || !$this->isGranted($right, $node)) {
            return new JsonResponse(['error' => 'Non autorisé'], Response::HTTP_FORBIDDEN);
        }
        return $node;
    }

    /** Redirection to the to-do page, opened on the root list of the node. */
    private function redirectToList(string $slug, TodoNode $node): Response
    {
        return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug, '_fragment' => 'list-' . $node->getRootList()->getId()]);
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();
        return $user;
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

        // Tâche (type item) du groupe : rédacteurs, ou membre assigné à la tâche
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
