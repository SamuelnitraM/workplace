<?php

namespace App\Moderation;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case HateSpeech = 'hate_speech';
    case Inappropriate = 'inappropriate';
    case Scam = 'scam';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Spam ou publicité',
            self::Harassment => 'Harcèlement ou insultes',
            self::HateSpeech => 'Propos haineux ou discriminatoires',
            self::Inappropriate => 'Contenu choquant ou inapproprié',
            self::Scam => 'Arnaque ou fraude',
            self::Other => 'Autre motif',
        };
    }
}
