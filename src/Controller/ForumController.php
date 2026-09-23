<?php

namespace App\Controller;

use App\Entity\Category;
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
        // 2 requêtes : l'arbre complet (fetch join des enfants) + les compteurs groupés,
        // plus 1 requête groupée « dernière activité » seulement s'il existe une racine de regroupement.
        $tree = $categoryRepository->findAllAsTree();
        $roots = $categoryRepository->rootsOf($tree);
        $threadCounts = $categoryRepository->countThreadsByCategory();

        $hasGrouping = [] !== array_filter($roots, static fn (Category $c): bool => $c->isGrouping());

        return $this->render('forum/index.html.twig', [
            'categories' => $roots,
            'threadCounts' => $threadCounts,
            'threadTotals' => $categoryRepository->aggregateThreadCounts($tree, $threadCounts),
            'lastActivities' => $hasGrouping
                ? $categoryRepository->aggregateLastActivity($tree, $categoryRepository->lastActivityByCategory())
                : [],
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
        // L'arbre complet est chargé en une requête : fil d'Ariane et sous-catégories
        // sont ensuite résolus sans requête supplémentaire.
        $tree = $categoryRepository->findAllAsTree();
        $category = null;
        foreach ($tree as $candidate) {
            if ($candidate->getSlug() === $slug) {
                $category = $candidate;
                break;
            }
        }

        if (!$category) {
            throw $this->createNotFoundException('Catégorie introuvable');
        }

        $threadCounts = $categoryRepository->countThreadsByCategory();

        // Catégorie de regroupement sans sujet hérité : aucune requête de sujets.
        $threads = null;
        if (!$category->isGrouping() || ($threadCounts[$category->getId()] ?? 0) > 0) {
            $query = $threadRepository->createQueryBuilder('t')
                ->addSelect('a', 'tb')
                ->innerJoin('t.author', 'a')
                ->leftJoin('a.titleBadge', 'tb') // titre des auteurs (pas de N+1)
                ->where('t.category = :category')
                ->setParameter('category', $category)
                ->orderBy('t.isPinned', 'DESC')
                ->addOrderBy('t.createdAt', 'DESC')
                ->addOrderBy('t.id', 'DESC')
                ->getQuery();

            // Le tri par paramètre d'URL est désactivé (évite une 500 sur ?sort= invalide).
            $threads = $paginator->paginate(
                $query,
                max(1, $request->query->getInt('page', 1)),
                20,
                [PaginatorInterface::SORT_FIELD_PARAMETER_NAME => null]
            );
        }

        return $this->render('forum/category.html.twig', [
            'category' => $category,
            'threads' => $threads,
            'threadCounts' => $threadCounts,
            'threadTotals' => $categoryRepository->aggregateThreadCounts($tree, $threadCounts),
            'lastActivities' => $category->isGrouping()
                ? $categoryRepository->aggregateLastActivity($tree, $categoryRepository->lastActivityByCategory())
                : [],
        ]);
    }
}
