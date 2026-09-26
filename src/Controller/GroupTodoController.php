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
    // Main to-do page of the group
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

        // Check that the user is a member
        $this->denyAccessUnlessGranted(GroupVoter::MEMBER, $group);

        // Writers and readers (roles >= group settings) see everything; other members only see the lists
        // where a category or a task is assigned to them (the template then filters via TODO_VIEW)
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

    // Create a node (list, category or item)
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

        // Group member at least; the specific right (list / child of the parent) is checked below
        $this->denyAccessUnlessGranted(GroupVoter::MEMBER, $group);

        $title = trim((string) $request->request->get('title', ''));
        $type = (string) $request->request->get('type', TodoNode::TYPE_LIST);
        $parentId = $request->request->get('parent_id');

        if (empty($title)) {
            $this->addFlash('error', 'Le titre ne peut pas être vide.');
            return $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
        }

        // Expected hierarchy: list > category > task
        // array_key_exists rather than ??: the list type intentionally has a null expected parent
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
            // Category and task: writers
            $this->denyAccessUnlessGranted(TodoNodeVoter::CREATE_CHILD, $parent);
        } else {
            // List creation: writers only
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

        // The page reopens on the list that received the created element (lists are collapsed by default)
        return $this->redirectToList($slug, $node);
    }

    // Delete a node
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

        // To-do writers only
        $this->denyAccessUnlessGranted(TodoNodeVoter::DELETE, $node);

        $parent = $node->getParent();
        $em->remove($node);
        $em->flush();

        $this->addFlash('success', 'Supprimé avec succès.');
        return $parent !== null ? $this->redirectToList($slug, $parent) : $this->redirectToRoute('app_group_todo_index', ['slug' => $slug]);
    }

    // Rename a node
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

        // To-do writers only
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

    // Task assignments (App\Todo\TodoAssignmentManager): JSON responses with the up-to-date fragments
    // (task assignees, summary of its category)

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

    // Increase a task's progress
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

    // Decrease a task's progress
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

    // Validate/complete a task (set to 100%)
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

    // Task (item type) of the group that the current user can progress,
    // or a JSON error response (invalid CSRF / not authorized)
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

        // Task (item type) of the group: writers, or member assigned to the task
        if (!$group || !$node || $node->getUsergroup() !== $group || !$this->isGranted(TodoNodeVoter::PROGRESS, $node)) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        return $node;
    }

    // User who is a member of the group matching the given id, otherwise null
    private function findGroupMemberUser(Group $group, int $userId, GroupMemberRepository $groupMemberRepository): ?User
    {
        $member = $groupMemberRepository->findOneBy([
            'user' => $userId,
            'usergroup' => $group,
        ]);

        return $member?->getUser();
    }

    // CSRF token sent via the _token field (forms) or the X-CSRF-Token header (fetch)
    private function isTodoCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');

        return $this->isCsrfTokenValid('todo', (string) $token);
    }
}
