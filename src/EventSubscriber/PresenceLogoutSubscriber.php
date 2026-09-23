<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\PresenceService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/** Retire immédiatement l'utilisateur des « connectés » quand il se déconnecte. */
class PresenceLogoutSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly PresenceService $presence)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->presence->markOffline($user);
        }
    }
}
