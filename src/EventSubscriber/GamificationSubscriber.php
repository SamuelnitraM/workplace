<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Gamification\BadgeCatalog;
use App\Service\GamificationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Gamification liée aux requêtes :
 *  - connexion quotidienne (+ Avant-garde) à la connexion (formulaire, remember-me, inscription)
 *    et à chaque page si la session date d'un jour précédent ;
 *  - suivi générique des visites (activité « visit:<route> », + « visit:own_profile » sur son profil),
 *    qui déclenche les vérifications Curieux / Juriste ;
 *  - filet de sécurité : évaluation complète des badges au plus toutes les 5 minutes par session.
 *
 * Toutes les écritures sont idempotentes et sans exception de contrainte d'unicité
 * (voir GamificationService) : aucune gestion d'EntityManager fermé n'est nécessaire ici.
 */
class GamificationSubscriber implements EventSubscriberInterface
{
    /** Intervalle minimal (en secondes) entre deux évaluations complètes des badges pour une même session. */
    private const SYNC_INTERVAL = 300;
    private const SESSION_SYNC_KEY = '_gamification_last_sync';
    /** Visites déjà enregistrées pendant la session (évite une requête SQL par page). */
    private const SESSION_VISITS_KEY = '_gamification_visits';

    /** Routes techniques (AJAX, polling) qui ne doivent pas déclencher la gamification. */
    private const IGNORED_ROUTES = ['app_heartbeat', 'app_search_api', 'app_gamification_activity', 'app_logout', 'app_notification_recent'];
    private const IGNORED_ROUTE_PREFIXES = ['_', 'app_message_ajax_', 'admin'];

    public function __construct(
        private GamificationService $gamification,
        private TokenStorageInterface $tokenStorage,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            RequestEvent::class => 'onRequest',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->gamification->recordDailyLogin($user);
        $this->gamification->syncAllBadges($user);

        $request = $event->getRequest();
        if ($request->hasSession()) {
            $request->getSession()->set(self::SESSION_SYNC_KEY, time());
        }
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Toute requête authentifiée compte (session ouverte la veille, remember-me, heartbeat…) ;
        // sans requête SQL si la journée est déjà comptée.
        $this->gamification->recordDailyLogin($user);

        if (!$this->isTrackable($request)) {
            return;
        }

        $session = $request->hasSession() ? $request->getSession() : null;
        $route = (string) $request->attributes->get('_route');
        $keys = ['visit:' . $route];
        if ($route === 'app_profil_show' && $request->attributes->get('username') === $user->getUsername()) {
            $keys[] = BadgeCatalog::OWN_PROFILE_ACTIVITY;
        }
        $known = (array) ($session?->get(self::SESSION_VISITS_KEY, []) ?? []);
        $newVisit = false;
        foreach ($keys as $key) {
            if (!isset($known[$key])) {
                $this->gamification->recordVisit($user, $key);
                $known[$key] = $newVisit = true;
            }
        }
        if ($newVisit) {
            $session?->set(self::SESSION_VISITS_KEY, $known);
        }

        $lastSync = (int) ($session?->get(self::SESSION_SYNC_KEY, 0) ?? 0);
        if (time() - $lastSync >= self::SYNC_INTERVAL) {
            $this->gamification->syncAllBadges($user);
            $session?->set(self::SESSION_SYNC_KEY, time());
        }
    }

    private function isTrackable(Request $request): bool
    {
        $route = (string) $request->attributes->get('_route', '');
        if ($route === '' || !$request->isMethod('GET') || $request->isXmlHttpRequest() || $request->getRequestFormat() !== 'html') {
            return false;
        }
        if (in_array($route, self::IGNORED_ROUTES, true)) {
            return false;
        }
        foreach (self::IGNORED_ROUTE_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return false;
            }
        }
        // Requêtes fetch() qui attendent du JSON
        $accept = (string) $request->headers->get('Accept', '');

        return !(str_contains($accept, 'application/json') && !str_contains($accept, 'text/html'));
    }
}
