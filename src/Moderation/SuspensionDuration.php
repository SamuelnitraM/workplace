<?php

namespace App\Moderation;

/** Suspension lengths offered to moderators. */
enum SuspensionDuration: string
{
    case OneDay = 'P1D';
    case ThreeDays = 'P3D';
    case OneWeek = 'P7D';
    case OneMonth = 'P1M';
    case Permanent = 'permanent';

    public function label(): string
    {
        return match ($this) {
            self::OneDay => '24 heures',
            self::ThreeDays => '3 jours',
            self::OneWeek => '7 jours',
            self::OneMonth => '1 mois',
            self::Permanent => 'Définitive',
        };
    }

    /** End date of the suspension, NULL for a permanent one. */
    public function endsAt(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        return $this === self::Permanent ? null : $from->add(new \DateInterval($this->value));
    }
}
