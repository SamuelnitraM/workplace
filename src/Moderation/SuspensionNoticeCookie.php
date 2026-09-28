<?php

namespace App\Moderation;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Remembers in the browser which suspended member was refused, so the login page shows the reason of the
 * suspension (and the appeal link) at every visit while it lasts, whatever led there: forced logout,
 * refused login, refused remember-me cookie. The cookie holds the member id signed with the application secret.
 */
class SuspensionNoticeCookie
{
    public const NAME = 'hf_suspension';
    private const PERMANENT_LIFETIME = '+1 year';

    public function __construct(
        private readonly UserRepository $userRepository,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
    ) {
    }

    public function create(User $member): Cookie
    {
        $expiresAt = $member->getSuspendedUntil() ?? new \DateTimeImmutable(self::PERMANENT_LIFETIME);
        $memberId = (string) $member->getId();
        return Cookie::create(self::NAME, $memberId . '.' . $this->sign($memberId), $expiresAt, '/', null, null, true, false, Cookie::SAMESITE_LAX);
    }

    public function clear(): Cookie
    {
        return Cookie::create(self::NAME, '', 1, '/', null, null, true, false, Cookie::SAMESITE_LAX);
    }

    public function isPresent(Request $request): bool
    {
        return $request->cookies->has(self::NAME);
    }

    /** Member named by a valid cookie, as long as the member is still suspended. */
    public function memberFrom(Request $request): ?User
    {
        [$memberId, $signature] = array_pad(explode('.', $request->cookies->getString(self::NAME), 2), 2, '');
        if ($memberId === '' || !hash_equals($this->sign($memberId), $signature)) {
            return null;
        }
        $member = $this->userRepository->find((int) $memberId);
        return $member !== null && $member->isSuspended() ? $member : null;
    }

    private function sign(string $memberId): string
    {
        return hash_hmac('sha256', self::NAME . '|' . $memberId, $this->secret);
    }
}
