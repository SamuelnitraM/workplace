<?php

namespace App\Moderation;

/** Decision taken by a moderator on a report. */
enum ReportResolution: string
{
    case Hidden = 'hidden';
    case Deleted = 'deleted';
    case Warned = 'warned';
    /** Temporary suspension of the author. */
    case Suspended = 'suspended';
    /** Permanent ban of the author. */
    case Banned = 'banned';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Contenu masqué',
            self::Deleted => 'Contenu supprimé',
            self::Warned => 'Auteur averti',
            self::Suspended => 'Auteur suspendu temporairement',
            self::Banned => 'Auteur banni définitivement',
            self::Dismissed => 'Classé sans suite',
        };
    }

    /**
     * Severity of the sanction, as the colour code of the moderation pages (CSS class sanction-<severity>):
     * ban (red), suspension (orange), content (yellow: content removed or hidden without ban), none otherwise.
     */
    public function severity(): string
    {
        return match ($this) {
            self::Banned => 'ban',
            self::Suspended => 'suspension',
            self::Deleted, self::Hidden => 'content',
            self::Warned, self::Dismissed => 'none',
        };
    }

    /** Resolution recorded for a suspension of the given length. */
    public static function forSuspension(SuspensionDuration $duration): self
    {
        return $duration === SuspensionDuration::Permanent ? self::Banned : self::Suspended;
    }
}
