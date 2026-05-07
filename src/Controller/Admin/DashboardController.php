<?php

namespace App\Controller\Admin;


use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
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

/*         yield MenuItem::section('Todo');
        yield MenuItem::linkTo(TodoNodeCrudController::class, 'Todo Nodes', 'fa fa-list'); */

        yield MenuItem::section('');
        yield MenuItem::linkToUrl('Retour au site', 'fa fa-arrow-left', '/');
}
}