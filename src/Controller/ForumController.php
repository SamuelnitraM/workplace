<?php

namespace App\Controller;

use App\Repository\CategoryRepository;
use App\Repository\ThreadRepository;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/forum', name: 'app_forum_')]
class ForumController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(CategoryRepository $categoryRepository): Response
    {
        $categories = $categoryRepository->findBy([], ['position' => 'ASC']);

        return $this->render('forum/index.html.twig', [
            'categories' => $categories,
        ]);
    }

    #[Route('/category/{slug}', name: 'category')]
    public function category(
        string $slug,
        Request $request,
        CategoryRepository $categoryRepository,
        ThreadRepository $threadRepository,
        PaginatorInterface $paginator
    ): Response {
        $category = $categoryRepository->findOneBy(['slug' => $slug]);

        if (!$category) {
            throw $this->createNotFoundException('Catégorie introuvable');
        }

        $query = $threadRepository->createQueryBuilder('t')
            ->where('t.category = :category')
            ->setParameter('category', $category)
            ->orderBy('t.isPinned', 'DESC')
            ->addOrderBy('t.createdAt', 'DESC')
            ->getQuery();

        $threads = $paginator->paginate(
            $query,
            $request->query->getInt('page', 1),
            20
        );

        return $this->render('forum/category.html.twig', [
            'category' => $category,
            'threads' => $threads,
        ]);
    }
}