<?php

namespace App\Tour;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Starts the guided tours: address of a tour (its page with ?visite=<key>) and the tour requested
 * by the current page, with the tour that follows it.
 */
class TourLauncher
{
    public function __construct(
        private readonly TourCatalog $catalog,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Security $security,
    ) {
    }

    public function urlOf(Tour $tour): string
    {
        $user = $this->security->getUser();
        $parameters = array_map(
            static fn (string $value): string => $value === '@me' && $user instanceof User ? (string) $user->getUsername() : $value,
            $tour->routeParameters,
        );
        return $this->urlGenerator->generate($tour->route, $parameters + [TourCatalog::QUERY_PARAMETER => $tour->key]);
    }

    /**
     * Tour asked by the query string of the current page, for a logged-in member on the page of that tour.
     *
     * @return array{tour: Tour, next: ?array{title: string, url: string}}|null
     */
    public function requested(): ?array
    {
        $request = $this->requestStack->getMainRequest();
        $tour = $request !== null ? $this->catalog->find($request->query->getString(TourCatalog::QUERY_PARAMETER)) : null;
        if ($tour === null || !$this->security->getUser() instanceof User || $request->attributes->get('_route') !== $tour->route) {
            return null;
        }
        $next = $this->catalog->next($tour);
        return [
            'tour' => $tour,
            'next' => $next !== null ? ['title' => $next->title, 'url' => $this->urlOf($next)] : null,
        ];
    }
}
