<?php

namespace App\Moderation;

use App\Entity\User;

/** Text explaining a suspension to the member (login page, e-mail, forced logout). */
final class SuspensionNotice
{
    public static function describe(User $member): string
    {
        $until = $member->getSuspendedUntil();
        $text = $until === null
            ? 'Ton compte a été suspendu définitivement par la modération.'
            : sprintf(
                'Ton compte est suspendu jusqu\'au %s.',
                $until->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y à H:i'),
            );
        $reason = trim((string) $member->getSuspensionReason());
        return $reason !== '' ? $text . ' Motif : ' . $reason : $text;
    }
}
