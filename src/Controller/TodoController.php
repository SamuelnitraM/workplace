<?php

namespace App\Controller;

use App\Entity\TodoNode;
use App\Repository\TodoNodeRepository;
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
    // Page principale — affiche toutes les listes de l'utilisateur
    #[Route('/', name: 'index')]
    public function index(TodoNodeRepository $todoNodeRepository): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $lists = $todoNodeRepository->findBy([
            'owner' => $user,
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
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $title = trim($request->request->get('title', ''));
        $type = $request->request->get('type', TodoNode::TYPE_LIST);
        $parentId = $request->request->get('parent_id');

        if (empty($title)) {
            $this->addFlash('error', 'Le titre ne peut pas être vide.');
            return $this->redirectToRoute('app_todo_index');
        }

        $node = new TodoNode();
        $node->setTitle($title);
        $node->setType($type);
        $node->setOwner($user);

        if ($parentId) {
            $parent = $todoNodeRepository->find($parentId);
            if ($parent && $parent->getOwner() === $user) {
                $node->setParent($parent);
            }
        }

        // Position = dernier de la liste
        $siblings = $parentId
            ? $todoNodeRepository->findBy(['parent' => $parentId], ['position' => 'DESC'])
            : $todoNodeRepository->findBy(['owner' => $user, 'parent' => null], ['position' => 'DESC']);

        $node->setPosition(count($siblings) > 0 ? $siblings[0]->getPosition() + 1 : 0);

        $em->persist($node);
        $em->flush();

        return $this->redirectToRoute('app_todo_index');
    }

    // Cocher/décocher un item
    #[Route('/toggle/{id}', name: 'toggle', methods: ['POST'])]
    public function toggle(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): JsonResponse
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $node->setIsDone(!$node->getIsDone());
        $node->setDoneAt($node->getIsDone() ? new \DateTimeImmutable() : null);
        $em->flush();

        return new JsonResponse(['isDone' => $node->getIsDone()]);
    }

    // Supprimer un noeud
    #[Route('/delete/{id}', name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request, TodoNodeRepository $todoNodeRepository, EntityManagerInterface $em): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getOwner() !== $user) {
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
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $node = $todoNodeRepository->find($id);

        if (!$node || $node->getOwner() !== $user) {
            return new JsonResponse(['error' => 'Non autorisé'], 403);
        }

        $title = trim($request->request->get('title', ''));
        if (empty($title)) {
            return new JsonResponse(['error' => 'Titre vide'], 400);
        }

        $node->setTitle($title);
        $em->flush();

        return new JsonResponse(['title' => $node->getTitle()]);
    }
}