<?php

namespace App\Moderation;

/** Decision taken by a moderator on a report. */
enum ReportResolution: string
{
    case Hidden = 'hidden';
    case Deleted = 'deleted';
    case Warned = 'warned';
    case Suspended = 'suspended';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Contenu masqué',
            self::Deleted => 'Contenu supprimé',
            self::Warned => 'Auteur averti',
            self::Suspended => 'Auteur suspendu',
            self::Dismissed => 'Classé sans suite',
        };
    }
}
