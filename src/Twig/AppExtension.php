<?php

namespace App\Twig;

use App\Entity\User;
use App\Navigation\BackLinkResolver;
use App\Repository\NotificationRepository;
use App\Service\GalleryAlbumManager;
use App\Service\NotificationRenderer;
use App\Text\MentionResolver;
use App\Tour\TourLauncher;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Twig helpers of the site; the unread notification count is kept for one request (reset between requests). */
class AppExtension extends AbstractExtension implements ResetInterface
{
    /** Icons drawn by JavaScript besides the notification icons (likes, password rules, chat, pinned messages, messenger, emoji picker). */
    private const SCRIPT_ICONS = ['heart', 'check', 'dot', 'x', 'pin', 'flag', 'send', 'chevron-down', 'chevron-up', 'helmet'];

    /** Cookie holding the theme chosen by the visitor (assets/controllers/theme_controller.js). */
    public const THEME_COOKIE = 'hf_theme';
    private const THEMES = ['light', 'dark', 'auto'];

    private ?int $unreadNotifications = null;

    public function __construct(
        private NotificationRepository $notificationRepository,
        private NotificationRenderer $notificationRenderer,
        private Security $security,
        private MentionResolver $mentionResolver,
        private TourLauncher $tourLauncher,
        private RequestStack $requestStack,
        private BackLinkResolver $backLinkResolver,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('notification_unread_count', $this->unreadNotificationCount(...)),
            new TwigFunction('notification_text', $this->notificationRenderer->text(...)),
            new TwigFunction('notification_icon', $this->notificationRenderer->icon(...)),
            new TwigFunction('notification_tone', $this->notificationRenderer->tone(...)),
            new TwigFunction('sprite_icons', $this->spriteIcons(...)),
            new TwigFunction('theme_preference', $this->themePreference(...)),
            new TwigFunction('album_cover', GalleryAlbumManager::coverOf(...)),
            new TwigFunction('requested_tour', $this->tourLauncher->requested(...)),
            new TwigFunction('back_link', $this->backLinkResolver->resolve(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('time_ago', $this->timeAgo(...)),
            // Plain text (private messages, chat) escaped, with the @pseudo of existing members as profile links
            new TwigFilter('mention_links', $this->mentionResolver->linkify(...), ['is_safe' => ['html']]),
        ];
    }

    public function reset(): void
    {
        $this->unreadNotifications = null;
    }

    /** Unread notifications of the signed-in member (one query per request). */
    public function unreadNotificationCount(): int
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return 0;
        }

        return $this->unreadNotifications ??= $this->notificationRepository->countUnread($user);
    }

    /** Relative date in French: « à l'instant », « il y a 5 min », « il y a 3 h », « il y a 2 j ». */
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

    /**
     * Icons of the SVG sprite of the page (_partials/_icon_sprite.html.twig), used by JavaScript through <use href="#icon-NAME">.
     *
     * @return list<string>
     */
    public function spriteIcons(): array
    {
        return array_values(array_unique([...NotificationRenderer::iconNames(), ...self::SCRIPT_ICONS]));
    }

    /** Theme chosen by the visitor: « light », « dark » or « auto » (preference of the system, by default). */
    public function themePreference(): string
    {
        $theme = $this->requestStack->getMainRequest()?->cookies->getString(self::THEME_COOKIE) ?? '';
        return in_array($theme, self::THEMES, true) ? $theme : 'auto';
    }
}
