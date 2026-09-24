<?php

namespace App\Mailer;

use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Single entry point for every e-mail sent to a member.
 *
 * The sender comes from the global "From" header (config/packages/mailer.yaml). Templates extend
 * email/layout.html.twig and receive the recipient as "recipient". A delivery failure never breaks the
 * calling action: it is logged and reported through the return value.
 */
class TransactionalMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(User $recipient, string $subject, string $template, array $context = []): bool
    {
        $email = (new TemplatedEmail())
            ->to(new Address((string) $recipient->getEmail(), (string) $recipient->getUsername()))
            ->subject($subject)
            ->htmlTemplate($template)
            ->context($context + ['recipient' => $recipient, 'subject' => $subject]);
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('E-mail delivery failed : {template} to user #{userId} : {message}', [
                'template' => $template,
                'userId' => $recipient->getId(),
                'message' => $exception->getMessage(),
            ]);
            return false;
        }
        return true;
    }
}
