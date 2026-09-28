<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Moderation\SuspensionNoticeCookie;
use App\Security\SuspendedAccountException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Suspended members:
 * - a member suspended while logged in is logged out at the next request (reloaded from the database at each request)
 *   and sent to the login page;
 * - that forced logout, a refused login and a refused remember-me cookie all leave the suspension notice cookie
 *   (App\Moderation\SuspensionNoticeCookie), read by the login page; a successful login removes it.
 */
class SuspensionSubscriber implements EventSubscriberInterface
{
    private const SUSPENDED_MEMBER_ATTRIBUTE = '_suspended_member';

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SuspensionNoticeCookie $noticeCookie,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // After the firewall (priority 8), which loads the authenticated member
            KernelEvents::REQUEST => ['logOutSuspendedMember', 7],
            KernelEvents::RESPONSE => 'writeNoticeCookie',
            LoginFailureEvent::class => 'rememberRefusedMember',
            LoginSuccessEvent::class => 'forgetRefusedMember',
        ];
    }

    public function logOutSuspendedMember(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || !$user->isSuspended()) {
            return;
        }
        $this->security->logout(false);
        $this->markSuspended($event->getRequest(), $user);
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }

    public function rememberRefusedMember(LoginFailureEvent $event): void
    {
        $exception = $event->getException();
        if ($exception instanceof SuspendedAccountException) {
            $this->markSuspended($event->getRequest(), $exception->getMember());
        }
    }

    public function forgetRefusedMember(LoginSuccessEvent $event): void
    {
        $event->getRequest()->attributes->set(self::SUSPENDED_MEMBER_ATTRIBUTE, false);
    }

    public function writeNoticeCookie(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $member = $event->getRequest()->attributes->get(self::SUSPENDED_MEMBER_ATTRIBUTE);
        if ($member instanceof User) {
            $event->getResponse()->headers->setCookie($this->noticeCookie->create($member));
        } elseif ($member === false && $this->noticeCookie->isPresent($event->getRequest())) {
            $event->getResponse()->headers->setCookie($this->noticeCookie->clear());
        }
    }

    private function markSuspended(Request $request, User $member): void
    {
        $request->attributes->set(self::SUSPENDED_MEMBER_ATTRIBUTE, $member);
    }
}
