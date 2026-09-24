<?php

namespace App\Security;

/**
 * Actions protected against spam. Each case value is the name of a limiter declared in
 * config/packages/rate_limiter.yaml.
 */
enum ThrottledAction: string
{
    case Registration = 'registration';
    case PasswordResetRequest = 'password_reset_request';
    case VerificationEmail = 'verification_email';
    case PrivateMessage = 'private_message';
    case ForumThread = 'forum_thread';
    case ForumReply = 'forum_reply';
    case PhotoComment = 'photo_comment';
    case Report = 'report';

    /** Message shown to the member when the limit is reached. */
    public function refusalMessage(): string
    {
        return match ($this) {
            self::Registration => 'Trop d\'inscriptions depuis cette connexion. Réessaie plus tard.',
            self::PasswordResetRequest => 'Trop de demandes de réinitialisation. Réessaie plus tard.',
            self::VerificationEmail => 'Trop d\'e-mails de confirmation demandés. Vérifie tes courriers indésirables ou réessaie plus tard.',
            self::PrivateMessage => 'Tu envoies des messages trop vite. Patiente quelques instants.',
            self::ForumThread => 'Tu as créé beaucoup de sujets récemment. Réessaie plus tard.',
            self::ForumReply => 'Tu réponds trop vite. Patiente quelques instants avant de publier.',
            self::PhotoComment => 'Tu commentes trop vite. Patiente quelques instants.',
            self::Report => 'Tu as envoyé beaucoup de signalements récemment. Réessaie plus tard.',
        };
    }
}
