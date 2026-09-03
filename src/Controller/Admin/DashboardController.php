<?php

namespace App\Controller\Admin;

use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'stats' => [
                ['label' => 'Utilisateurs', 'value' => $this->count(\App\Entity\User::class), 'icon' => 'fa-users', 'tone' => 'indigo', 'url' => $this->crudUrl(UserCrudController::class, 'index')],
                ['label' => 'Sujets', 'value' => $this->count(\App\Entity\Thread::class), 'icon' => 'fa-comments', 'tone' => 'blue', 'url' => $this->crudUrl(ThreadCrudController::class, 'index')],
                ['label' => 'Réponses', 'value' => $this->count(\App\Entity\Post::class), 'icon' => 'fa-message', 'tone' => 'emerald', 'url' => $this->crudUrl(PostCrudController::class, 'index')],
                ['label' => 'Catégories', 'value' => $this->count(\App\Entity\Category::class), 'icon' => 'fa-folder', 'tone' => 'amber', 'url' => $this->crudUrl(CategoryCrudController::class, 'index')],
                ['label' => 'Todo Nodes', 'value' => $this->count(\App\Entity\TodoNode::class), 'icon' => 'fa-list-check', 'tone' => 'violet', 'url' => $this->crudUrl(TodoNodeCrudController::class, 'index')],
                ['label' => 'Amitiés', 'value' => $this->count(\App\Entity\Friendship::class), 'icon' => 'fa-heart', 'tone' => 'rose', 'url' => $this->crudUrl(FriendshipCrudController::class, 'index')],
            ],
            'quickActions' => [
                ['label' => 'Nouvel utilisateur', 'url' => $this->crudUrl(UserCrudController::class, 'new'), 'icon' => 'fa-user-plus'],
                ['label' => 'Nouveau sujet', 'url' => $this->crudUrl(ThreadCrudController::class, 'new'), 'icon' => 'fa-comment-medical'],
                ['label' => 'Nouvelle catégorie', 'url' => $this->crudUrl(CategoryCrudController::class, 'new'), 'icon' => 'fa-folder-plus'],
            ],
            'recentUsers' => $this->doctrine->getRepository(\App\Entity\User::class)->findBy([], ['createdAt' => 'DESC'], 5),
            'recentThreads' => $this->doctrine->getRepository(\App\Entity\Thread::class)->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }

    private function count(string $entity): int
    {
        /** @var \Doctrine\ORM\EntityRepository $repository */
        $repository = $this->doctrine->getRepository($entity);

        return $repository->count([]);
    }

    private function crudUrl(string $controller, string $action): string
    {
        return $this->adminUrlGenerator
            ->setController($controller)
            ->setAction($action)
            ->generateUrl();
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('⚔️ HighlightForge Admin')
            ->setFaviconPath('favicon.ico')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::section('Site');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-users');
        yield MenuItem::linkTo(FriendshipCrudController::class, 'Amitiés', 'fa fa-heart');
        yield MenuItem::section('Forum');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fa fa-folder');
        yield MenuItem::linkTo(ThreadCrudController::class, 'Sujets', 'fa fa-comments');
        yield MenuItem::linkTo(PostCrudController::class, 'Posts', 'fa fa-message');
        yield MenuItem::section('Organisation');
        yield MenuItem::linkTo(TodoNodeCrudController::class, 'Todo Nodes', 'fa fa-list-check');
        yield MenuItem::section('Accès rapides');
        yield MenuItem::linkToUrl('Voir le forum', 'fa fa-arrow-up-right-from-square', '/forum');
        yield MenuItem::linkToUrl('Voir les groupes', 'fa fa-users-rectangle', '/groups');
        yield MenuItem::linkToUrl('Retour au site', 'fa fa-arrow-left', '/');
    }
}