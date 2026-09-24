<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Moderation\SuspensionNotice;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ends the session of a member suspended while logged in: the member is reloaded from the database
 * at each request, so the suspension applies from the very next page.
 */
class SuspendedUserSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (priority 8), which loads the authenticated member
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isSuspended()) {
            return;
        }
        $notice = SuspensionNotice::describe($user);
        $this->security->logout(false);
        $request = $event->getRequest();
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('error', $notice);
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }
}
