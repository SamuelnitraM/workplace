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

    /** Short explanation shown under the label in the report form. */
    public function description(): string
    {
        return match ($this) {
            self::Spam => 'Publicité, liens répétés, messages sans rapport avec la discussion.',
            self::Harassment => 'Attaques personnelles, menaces, acharnement contre un membre.',
            self::HateSpeech => 'Propos visant une origine, une religion, un genre, une orientation…',
            self::Inappropriate => 'Violence, nudité ou contenu choquant sans avertissement.',
            self::Scam => 'Fausse vente, demande d\'argent ou tentative d\'escroquerie.',
            self::Other => 'Un autre problème : précise-le dans le champ ci-dessous.',
        };
    }

    /** Icon name of templates/_partials/_icon.html.twig. */
    public function icon(): string
    {
        return match ($this) {
            self::Spam => 'mail',
            self::Harassment => 'alert-triangle',
            self::HateSpeech => 'ban',
            self::Inappropriate => 'eye-off',
            self::Scam => 'shield',
            self::Other => 'help-circle',
        };
    }
}
