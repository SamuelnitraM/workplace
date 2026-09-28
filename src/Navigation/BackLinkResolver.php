<?php

namespace App\Navigation;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Routing\RouterInterface;

/**
 * « Retour » link of a page, pointing to the page the visitor comes from (Referer of the same site),
 * with a label naming it; the fallback (the parent page) applies otherwise.
 * A Referer on the same route as the current page (next photo, next page of comments, form sent) is not a
 * meaningful origin: the fallback applies too. Breadcrumbs stay hierarchical and do not use this.
 * Rendered by templates/_partials/_back_link.html.twig.
 */
class BackLinkResolver
{
    /** Label of the link by route of the previous page; other pages get « Retour ». */
    private const LABELS = [
        'app_home' => 'Retour à l’accueil',
        'app_feed_page' => 'Retour au fil',
        'app_forum_index' => 'Retour au forum',
        'app_forum_category' => 'Retour à la catégorie',
        'app_thread_show' => 'Retour au sujet',
        'app_profil_show' => 'Retour au profil',
        'app_gallery_album_show' => 'Retour à l’album',
        'app_gallery_photo_show' => 'Retour à la photo',
        'app_group_index' => 'Retour aux groupes',
        'app_group_show' => 'Retour au groupe',
        'app_group_todo_index' => 'Retour aux tâches du groupe',
        'app_todo_index' => 'Retour à mes tâches',
        'app_message_index' => 'Retour aux messages',
        'app_army_index' => 'Retour aux listes d’armée',
        'app_army_explorer' => 'Retour à l’explorateur',
        'app_army_show' => 'Retour à la liste',
        'app_friendship_list' => 'Retour aux amis',
        'app_notification_index' => 'Retour aux notifications',
        'app_leaderboard' => 'Retour au classement',
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly RouterInterface $router,
    ) {
    }

    /** @return array{url: string, label: string, fromHistory: bool} fromHistory: the link leads to the previous page of the history */
    public function resolve(string $fallbackUrl, string $fallbackLabel): array
    {
        $fallback = ['url' => $fallbackUrl, 'label' => $fallbackLabel, 'fromHistory' => false];
        $request = $this->requestStack->getCurrentRequest();
        $referer = $request?->headers->get('referer');
        if ($request === null || !$referer) {
            return $fallback;
        }
        $refererParts = parse_url($referer);
        if (($refererParts['host'] ?? null) !== $request->getHost() || !isset($refererParts['path'])) {
            return $fallback;
        }
        try {
            $refererRoute = $this->router->match($refererParts['path'])['_route'] ?? null;
        } catch (RoutingException) {
            return $fallback;
        }
        if ($refererRoute === null || $refererRoute === $request->attributes->get('_route')) {
            return $fallback;
        }
        $refererUrl = $refererParts['path'] . (isset($refererParts['query']) ? '?' . $refererParts['query'] : '');
        return ['url' => $refererUrl, 'label' => self::LABELS[$refererRoute] ?? 'Retour', 'fromHistory' => true];
    }
}
