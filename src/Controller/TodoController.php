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
    // Page principale — affiche toutes les listes personnelles de l'utilisateur
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

    // Créer un noeud (liste, catégorie ou item)
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

        // Hiérarchie attendue : liste > catégorie > tâche
        // array_key_exists et non ?? : le type liste a volontairement un parent attendu null
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

        // Position = dernier de la liste
        $siblings = $parent
            ? $todoNodeRepository->findBy(['parent' => $parent], ['position' => 'DESC'], 1)
            : $todoNodeRepository->findBy(['owner' => $user, 'usergroup' => null, 'parent' => null], ['position' => 'DESC'], 1);

        $node->setPosition(count($siblings) > 0 ? $siblings[0]->getPosition() + 1 : 0);

        $em->persist($node);
        $em->flush();

        return $this->redirectToRoute('app_todo_index');
    }

    // Supprimer un noeud
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

        $em->remove($node);
        $em->flush();

        $this->addFlash('success', 'Supprimé avec succès.');
        return $this->redirectToRoute('app_todo_index');
    }

    // Renommer un noeud
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

    // Augmenter la progression d'une tâche
    #[Route('/progress/up/{id}', name: 'progress_up', methods: ['POST'])]
    public function progressUp(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        // Uniquement une tâche (type item), vérifié par le voter
        $node = $this->findPersonalNode($id, TodoNodeVoter::PROGRESS, $todoNodeRepository);

        if (!$node) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $currentProgress = $node->getProgress() ?? 0;
        $newProgress = min(100, $currentProgress + 25);
        $node->setProgress($newProgress);

        // Si progression à 100%, cocher la tâche automatiquement
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
    public function progressDown(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        // Uniquement une tâche (type item), vérifié par le voter
        $node = $this->findPersonalNode($id, TodoNodeVoter::PROGRESS, $todoNodeRepository);

        if (!$node) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $currentProgress = $node->getProgress() ?? 0;
        $newProgress = max(0, $currentProgress - 25);
        $node->setProgress($newProgress);

        // Si progression < 100%, décocher la tâche
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
    public function progressValidate(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        if (!$this->isTodoCsrfValid($request)) {
            return new JsonResponse(['error' => 'Jeton CSRF invalide'], 403);
        }

        // Uniquement une tâche (type item), vérifié par le voter
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

    // Noeud personnel (hors groupe) sur lequel l'utilisateur courant a le droit demandé (TodoNodeVoter), sinon null
    private function findPersonalNode(int $id, string $attribute, TodoNodeRepository $todoNodeRepository): ?TodoNode
    {
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getUsergroup() !== null || !$this->isGranted($attribute, $node)) {
            return null;
        }

        return $node;
    }

    // Jeton CSRF envoyé via le champ _token (formulaires) ou l'en-tête X-CSRF-Token (fetch)
    private function isTodoCsrfValid(Request $request): bool
    {
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');

        return $this->isCsrfTokenValid('todo', (string) $token);
    }
}
