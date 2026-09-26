<?php

namespace App\Controller\Admin;

use App\Moderation\ModerationStatistics;
use App\Repository\ReportRepository;
use App\Service\AdminStatsService;
use App\Statistics\DailyActivityHistory;
use Doctrine\Persistence\ManagerRegistry;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
#[IsGranted('ROLE_MODERATOR')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly AdminStatsService $adminStatsService,
        private readonly ReportRepository $reportRepository,
        private readonly DailyActivityHistory $dailyActivityHistory,
        private readonly ModerationStatistics $moderationStatistics,
    ) {
    }

    public function index(): Response
    {
        // Moderators only reach the moderation pages: their home is the report queue
        if (!$this->isGranted('ROLE_ADMIN')) {
            return $this->redirect($this->crudUrl(ReportCrudController::class, 'index'));
        }
        $now = new \DateTimeImmutable();
        $moderationStats = $this->moderationStatistics->summary($now);
        $moderationStats['averageHandling'] = ModerationStatistics::formatDuration($moderationStats['averageHandling']);
        $moderationStats['recentAverageHandling'] = ModerationStatistics::formatDuration($moderationStats['recentAverageHandling']);
        return $this->render('admin/dashboard.html.twig', [
            'charts' => $this->dailyActivityHistory->charts($now),
            'moderationStats' => $moderationStats,
            'recentDays' => ModerationStatistics::RECENT_DAYS,
            'stats' => [
                ['label' => 'Signalements en attente', 'value' => $this->reportRepository->countPending(), 'icon' => 'fa-flag', 'tone' => 'rose', 'url' => $this->crudUrl(ReportCrudController::class, 'index')],
                ['label' => 'Utilisateurs', 'value' => $this->count(\App\Entity\User::class), 'icon' => 'fa-users', 'tone' => 'indigo', 'url' => $this->crudUrl(UserCrudController::class, 'index')],
                ['label' => 'Sujets', 'value' => $this->count(\App\Entity\Thread::class), 'icon' => 'fa-comments', 'tone' => 'blue', 'url' => $this->crudUrl(ThreadCrudController::class, 'index')],
                ['label' => 'Réponses', 'value' => $this->count(\App\Entity\Post::class), 'icon' => 'fa-message', 'tone' => 'emerald', 'url' => $this->crudUrl(PostCrudController::class, 'index')],
                ['label' => 'Catégories', 'value' => $this->count(\App\Entity\Category::class), 'icon' => 'fa-folder', 'tone' => 'amber', 'url' => $this->crudUrl(CategoryCrudController::class, 'index')],
                ['label' => 'Tâches', 'value' => $this->count(\App\Entity\TodoNode::class), 'icon' => 'fa-list-check', 'tone' => 'violet', 'url' => $this->crudUrl(TodoNodeCrudController::class, 'index')],
                ['label' => 'Amitiés', 'value' => $this->count(\App\Entity\Friendship::class), 'icon' => 'fa-heart', 'tone' => 'rose', 'url' => $this->crudUrl(FriendshipCrudController::class, 'index')],
            ],
            // No creation shortcut for users, threads or badges: creation is disabled in those CRUDs (badges are defined in App\Gamification\BadgeCatalog)
            'quickActions' => [
                ['label' => 'Nouvelle catégorie', 'url' => $this->crudUrl(CategoryCrudController::class, 'new'), 'icon' => 'fa-folder-plus'],
                ['label' => 'Badges (lecture seule)', 'url' => $this->crudUrl(BadgeCrudController::class, 'index'), 'icon' => 'fa-award'],
                ['label' => 'Voir le forum', 'url' => $this->generateUrl('app_forum_index'), 'icon' => 'fa-arrow-up-right-from-square'],
            ],
            'activity' => $this->adminStatsService->getDashboardStats(),
            'usersIndexUrl' => $this->crudUrl(UserCrudController::class, 'index'),
            'threadsIndexUrl' => $this->crudUrl(ThreadCrudController::class, 'index'),
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
            ->setTitle('SprueHub Admin')
            ->setFaviconPath('favicon.ico')
            ->renderContentMaximized();
    }

    public function configureAssets(): Assets
    {
        return parent::configureAssets()->addCssFile('styles/admin.css');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home')->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Modération');
        $pendingReports = $this->reportRepository->countPending();
        $reportsMenuItem = MenuItem::linkTo(ReportCrudController::class, 'Signalements', 'fa fa-flag');
        yield $pendingReports > 0 ? $reportsMenuItem->setBadge($pendingReports, 'danger') : $reportsMenuItem;
        yield MenuItem::section('Site')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fa fa-users')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(FriendshipCrudController::class, 'Amitiés', 'fa fa-heart')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(BadgeCrudController::class, 'Badges', 'fa fa-award')->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Forum')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Catégories', 'fa fa-folder')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(ThreadCrudController::class, 'Sujets', 'fa fa-comments')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(PostCrudController::class, 'Réponses', 'fa fa-message')->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Organisation')->setPermission('ROLE_ADMIN');
        yield MenuItem::linkTo(TodoNodeCrudController::class, 'Tâches', 'fa fa-list-check')->setPermission('ROLE_ADMIN');
        yield MenuItem::section('Accès rapides');
        yield MenuItem::linkToRoute('Voir le forum', 'fa fa-arrow-up-right-from-square', 'app_forum_index');
        yield MenuItem::linkToRoute('Voir les groupes', 'fa fa-users-rectangle', 'app_group_index');
        yield MenuItem::linkToRoute('Retour au site', 'fa fa-arrow-left', 'app_home');
    }
}