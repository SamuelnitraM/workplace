<?php

namespace App\Security;

use App\Entity\User;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Central anti-spam gate: every throttled submission consumes one token of its limiter.
 * Members are keyed by their id, anonymous visitors by their IP address.
 */
class SubmissionThrottle
{
    public function __construct(
        #[AutowireLocator([
            'registration' => new Autowire(service: 'limiter.registration'),
            'password_reset_request' => new Autowire(service: 'limiter.password_reset_request'),
            'verification_email' => new Autowire(service: 'limiter.verification_email'),
            'private_message' => new Autowire(service: 'limiter.private_message'),
            'forum_thread' => new Autowire(service: 'limiter.forum_thread'),
            'forum_reply' => new Autowire(service: 'limiter.forum_reply'),
            'photo_comment' => new Autowire(service: 'limiter.photo_comment'),
            'report' => new Autowire(service: 'limiter.report'),
        ])]
        private readonly ContainerInterface $limiterFactories,
    ) {
    }

    /** Consumes one attempt for a member; false when the limit is reached. */
    public function tryConsumeForUser(ThrottledAction $action, User $user): bool
    {
        return $this->tryConsume($action, 'user-' . $user->getId());
    }

    /** Consumes one attempt for the client IP of the request; false when the limit is reached. */
    public function tryConsumeForClient(ThrottledAction $action, Request $request): bool
    {
        return $this->tryConsume($action, 'ip-' . ($request->getClientIp() ?? 'unknown'));
    }

    /** Member form variant: a refused submission gets the refusal message as a form error (typed content is kept). */
    public function acceptsMemberForm(FormInterface $form, ThrottledAction $action, User $user): bool
    {
        return $this->acceptsOrFlagForm($form, $action, $this->tryConsumeForUser($action, $user));
    }

    /** Anonymous form variant, keyed by client IP. */
    public function acceptsClientForm(FormInterface $form, ThrottledAction $action, Request $request): bool
    {
        return $this->acceptsOrFlagForm($form, $action, $this->tryConsumeForClient($action, $request));
    }

    private function acceptsOrFlagForm(FormInterface $form, ThrottledAction $action, bool $accepted): bool
    {
        if (!$accepted) {
            $form->addError(new FormError($action->refusalMessage()));
        }
        return $accepted;
    }

    private function tryConsume(ThrottledAction $action, string $subjectKey): bool
    {
        /** @var RateLimiterFactoryInterface $factory */
        $factory = $this->limiterFactories->get($action->value);
        return $factory->create($subjectKey)->consume()->isAccepted();
    }
}
