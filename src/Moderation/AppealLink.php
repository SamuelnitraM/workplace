<?php

namespace App\Moderation;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Signed link to the appeal page of a suspended member, who cannot log in: given on the login page
 * to the member recognised by the suspension notice cookie, valid for two hours.
 */
class AppealLink
{
    private const LIFETIME = 'PT2H';

    public function __construct(
        private readonly UriSigner $uriSigner,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function urlFor(User $member): string
    {
        return $this->uriSigner->sign(
            $this->urlGenerator->generate('app_appeal', ['id' => $member->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
            new \DateInterval(self::LIFETIME),
        );
    }

    public function isValid(Request $request): bool
    {
        return $this->uriSigner->checkRequest($request);
    }
}
