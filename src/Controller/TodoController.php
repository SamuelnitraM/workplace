<?php

namespace App\Controller;

use App\Entity\TodoNode;
use App\Repository\TodoNodeRepository;
use App\Security\Voter\TodoNodeVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/todo', name: 'app_todo_')]
class TodoController extends AbstractController
{
    // Main page: displays all of the user's personal lists
    #[Route('/', name: 'index')]
    public function index(TodoNodeRepository $todoNodeRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $lists = $todoNodeRepository->findBy([
            'owner' => $user,
            'usergroup' => null,
            'type' => TodoNode::TYPE_LIST,
            'parent' => null,
        ], ['position' => 'ASC']);

        return $this->render('todo/index.html.twig', [
            'lists' => $lists,
        ]);
    }

    // Create a node (list, category or item)
    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request, EntityManagerInterface $em, TodoNodeRepository $todoNodeRepository): Response
    {
        if (!$this->isTodoCsrfValid($request)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $title = trim((string) $request->request->get('title', ''));
        $type = (string) $request->request->get('type', TodoNode::TYPE_LIST);
        $parentId = $request->request->get('parent_id');

        if (empty($title)) {
            $this->addFlash('error', 'Le titre ne peut pas être vide.');
            return $this->redirectToRoute('app_todo_index');
        }

        // Expected hierarchy: list > category > task
        // array_key_exists rather than ??: the list type intentionally has a null expected parent
        $expectedParentType = array_key_exists($type, TodoNode::EXPECTED_PARENT_TYPES) ? TodoNode::EXPECTED_PARENT_TYPES[$type] : false;
        if ($expectedParentType === false) {
            $this->addFlash('error', 'Type d\'élément invalide.');
            return $this->redirectToRoute('app_todo_index');
        }

        $parent = null;
        if ($expectedParentType !== null) {
            $parent = $parentId ? $this->findPersonalNode((int) $parentId, TodoNodeVoter::EDIT, $todoNodeRepository) : null;
            if (!$parent || $parent->getType() !== $expectedParentType) {
                $this->addFlash('error', 'Élément parent invalide.');
                return $this->redirectToRoute('app_todo_index');
            }
        }

        $node = new TodoNode();
        $node->setTitle(mb_substr($title, 0, 150));
        $node->setType($type);
        $node->setOwner($user);
        $node->setParent($parent);

        // Position = last of the list
        $siblings = $parent
            ? $todoNodeRepository->findBy(['parent' => $parent], ['position' => 'DESC'], 1)
            : $todoNodeRepository->findBy(['owner' => $user, 'usergroup' => null, 'parent' => null], ['position' => 'DESC'], 1);

        $node->setPosition(count($siblings) > 0 ? $siblings[0]->getPosition() + 1 : 0);

        $em->persist($node);
        $em->flush();

        // The page reopens on the list that received the created element (lists are collapsed by default)
        return $this->redirectToRoute('app_todo_index', ['_fragment' => 'list-' . $node->getRootList()->getId()]);
    }

    // Delete a node
    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): Response
    {
        if (!$this->isTodoCsrfValid($request)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }

        $node = $this->findPersonalNode($id, TodoNodeVoter::DELETE, $todoNodeRepository);

        if (!$node) {
            throw $this->createAccessDeniedException();
        }

        $parent = $node->getParent();
        $em->remove($node);
        $em->flush();

        $this->addFlash('success', 'Supprimé avec succès.');
        return $this->redirectToRoute('app_todo_index', $parent !== null ? ['_fragment' => 'list-' . $parent->getRootList()->getId()] : []);
    }

    // Rename a node
    #[Route('/rename/{id}', name: 'rename', methods: ['POST'])]
    public function rename(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        $node = $this->findPersonalNode($id, TodoNodeVoter::EDIT, $todoNodeRepository);

        if (!$node) {
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

    // Increase a task's progress
    #[Route('/progress/up/{id}', name: 'progress_up', methods: ['POST'])]
    public function progressUp(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        // Only a task (item type), checked by the voter
        $node = $this->findPersonalNode($id, TodoNodeVoter::PROGRESS, $todoNodeRepository);

        if (!$node) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $currentProgress = $node->getProgress() ?? 0;
        $newProgress = min(100, $currentProgress + 25);
        $node->setProgress($newProgress);

        // If progress reaches 100%, check the task automatically
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
    public function progressDown(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        // Only a task (item type), checked by the voter
        $node = $this->findPersonalNode($id, TodoNodeVoter::PROGRESS, $todoNodeRepository);

        if (!$node) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $currentProgress = $node->getProgress() ?? 0;
        $newProgress = max(0, $currentProgress - 25);
        $node->setProgress($newProgress);

        // If progress < 100%, uncheck the task
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
    public function progressValidate(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        // Only a task (item type), checked by the voter
        $node = $this->findPersonalNode($id, TodoNodeVoter::PROGRESS, $todoNodeRepository);

        if (!$node) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
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

    // Personal node (outside any group) on which the current user holds the requested right (TodoNodeVoter), otherwise null
    private function findPersonalNode(int $id, string $attribute, TodoNodeRepository $todoNodeRepository): ?TodoNode
    {
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getUsergroup() !== null || !$this->isGranted($attribute, $node)) {
            return null;
        }

        return $node;
    }

    // CSRF token sent via the _token field (forms) or the X-CSRF-Token header (fetch)
    private function isTodoCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');

        return $this->isCsrfTokenValid('todo', (string) $token);
    }
}
