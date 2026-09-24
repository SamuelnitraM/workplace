<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\GroupInvitationRepository;
use App\Repository\NotificationRepository;
use App\Service\GalleryAlbumManager;
use App\Service\NotificationRenderer;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFilter;
use Twig\TwigFunction;

class AppExtension extends AbstractExtension implements GlobalsInterface
{
    private ?int $unreadNotifications = null;

    public function __construct(
        private GroupInvitationRepository $groupInvitationRepository,
        private NotificationRepository $notificationRepository,
        private NotificationRenderer $notificationRenderer,
        private Security $security
    ) {}

    public function getGlobals(): array
    {
        $pendingInvitationsCount = 0;

        $user = $this->security->getUser();
        if ($user) {
            $pendingInvitationsCount = $this->groupInvitationRepository->count([
                'invitedUser' => $user,
                'status' => 'pending',
            ]);
        }

        return [
            'pendingInvitationsCount' => $pendingInvitationsCount,
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_unread_count', $this->unreadNotificationCount(...)),
            new TwigFunction('notification_text', $this->notificationRenderer->text(...)),
            new TwigFunction('notification_icon', $this->notificationRenderer->icon(...)),
            new TwigFunction('album_cover', GalleryAlbumManager::coverOf(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('time_ago', $this->timeAgo(...)),
        ];
    }

    /** Nombre de notifications non lues de l'utilisateur connecté (une requête par rendu). */
    public function unreadNotificationCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->unreadNotifications ??= $this->notificationRepository->countUnread($user);
    }

    /** Date relative en français : « à l'instant », « il y a 5 min », « il y a 3 h », « il y a 2 j ». */
    public function timeAgo(\DateTimeInterface $date): string
    {
        $seconds = max(0, time() - $date->getTimestamp());

        return match (true) {
            $seconds < 60 => 'à l\'instant',
            $seconds < 3600 => sprintf('il y a %d min', intdiv($seconds, 60)),
            $seconds < 86400 => sprintf('il y a %d h', intdiv($seconds, 3600)),
            $seconds < 7 * 86400 => sprintf('il y a %d j', intdiv($seconds, 86400)),
            default => 'le ' . $date->format('d/m/Y'),
        };
    }
}
