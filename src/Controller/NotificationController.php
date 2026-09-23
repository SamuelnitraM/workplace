<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/notifications', name: 'app_notification_')]
class NotificationController extends AbstractController
{
    private const PER_PAGE = 20;
    private const RECENT_LIMIT = 15;

    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly NotificationService $notifications,
    ) {
    }

    // Liste complète paginée
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user, Request $request, PaginatorInterface $paginator): Response
    {
        $pagination = $paginator->paginate(
            $this->repository->createListQuery($user),
            max(1, $request->query->getInt('page', 1)),
            self::PER_PAGE,
            [PaginatorInterface::SORT_FIELD_PARAMETER_NAME => null]
        );

        return $this->render('notification/index.html.twig', [
            'notifications' => $pagination,
            'unreadCount' => $this->repository->countUnread($user),
        ]);
    }

    // Dernières notifications (menu déroulant de la cloche)
    #[Route('/recent', name: 'recent', methods: ['GET'])]
    public function recent(#[CurrentUser] User $user): JsonResponse
    {
        return new JsonResponse([
            'notifications' => array_map(
                $this->notifications->serialize(...),
                $this->repository->findRecent($user, self::RECENT_LIMIT)
            ),
            'unreadCount' => $this->repository->countUnread($user),
        ]);
    }

    // Marquer une notification comme lue, puis suivre son lien (fonctionne sans JavaScript)
    #[Route('/{id}/read', name: 'read', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function read(int $id, #[CurrentUser] User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyUnlessCsrfValid($request);

        $notification = $this->repository->find($id);
        // On ne révèle pas l'existence des notifications des autres utilisateurs
        if (!$notification || $notification->getRecipient()->getId() !== $user->getId()) {
            throw $this->createNotFoundException('Notification introuvable');
        }

        $notification->markAsRead();
        $em->flush();

        if ($this->wantsJson($request)) {
            return new JsonResponse(['ok' => true, 'unreadCount' => $this->repository->countUnread($user)]);
        }

        $url = $notification->getUrl();

        return $this->isSafeLocalUrl($url)
            ? $this->redirect($url)
            : $this->redirectToRoute('app_notification_index');
    }

    #[Route('/read-all', name: 'read_all', methods: ['POST'])]
    public function readAll(#[CurrentUser] User $user, Request $request): Response
    {
        $this->denyUnlessCsrfValid($request);
        $this->repository->markAllRead($user);

        if ($this->wantsJson($request)) {
            return new JsonResponse(['ok' => true, 'unreadCount' => 0]);
        }

        $this->addFlash('success', 'Toutes les notifications ont été marquées comme lues.');

        return $this->redirectToRoute('app_notification_index');
    }

    private function denyUnlessCsrfValid(Request $request): void
    {
        $token = $request->headers->get('X-CSRF-Token') ?? $request->request->getString('_token');
        if (!$this->isCsrfTokenValid(NotificationService::CSRF_TOKEN_ID, $token)) {
            throw $this->createAccessDeniedException('Jeton CSRF invalide.');
        }
    }

    private function wantsJson(Request $request): bool
    {
        return $request->isXmlHttpRequest() || in_array('application/json', $request->getAcceptableContentTypes(), true);
    }

    /** Chemin relatif interne uniquement (pas de redirection ouverte vers un autre domaine). */
    private function isSafeLocalUrl(string $url): bool
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//') && !str_contains($url, '\\');
    }
}
