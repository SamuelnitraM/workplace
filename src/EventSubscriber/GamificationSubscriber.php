<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\GamificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

class GamificationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private GamificationService $gamification,
        private EntityManagerInterface $entityManager,
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

        $hour = (int) (new \DateTimeImmutable())->format('Hi');
        if ($hour >= 500 && $hour <= 630) {
            $this->gamification->recordActivity($user, 'early_bird');
        }
		if ($hour >= 200 && $hour <= 400) {
			$this->gamification->recordActivity($user, 'night_owl');
		}
		$this->gamification->recordDailyLogin($user);
        $this->gamification->syncAllBadges($user);
        $this->entityManager->flush();
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->attributes->get('_route')) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route');
        $this->gamification->recordActivity($user, 'visit:' . $route);
        $this->gamification->syncAllBadges($user);
        $this->entityManager->flush();
    }
}
