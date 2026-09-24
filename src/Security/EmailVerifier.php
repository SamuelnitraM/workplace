<?php

namespace App\Security;

use App\Entity\User;
use App\Mailer\TransactionalMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * E-mail address confirmation through a signed link.
 *
 * The link carries the member id, so it works from any browser, logged in or not. The signature
 * covers the id and the address: a link becomes invalid if the address changes.
 */
class EmailVerifier
{
    public const VERIFY_ROUTE = 'app_verify_email';

    public function __construct(
        private readonly VerifyEmailHelperInterface $verifyEmailHelper,
        private readonly TransactionalMailer $mailer,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function sendEmailConfirmation(User $user): bool
    {
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            self::VERIFY_ROUTE,
            (string) $user->getId(),
            (string) $user->getEmail(),
            ['id' => $user->getId()],
        );
        return $this->mailer->send($user, 'Confirme ton adresse e-mail', 'email/verify_email.html.twig', [
            'signedUrl' => $signatureComponents->getSignedUrl(),
            'expiresAtMessageKey' => $signatureComponents->getExpirationMessageKey(),
            'expiresAtMessageData' => $signatureComponents->getExpirationMessageData(),
        ]);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(Request $request, User $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, (string) $user->getId(), (string) $user->getEmail());
        $user->setIsVerified(true);
        $this->entityManager->flush();
    }
}
