<?php

namespace App\Moderation;

use Doctrine\ORM\QueryBuilder;

/**
 * Single criterion of the visibility of permanently banned members (suspension without end date).
 *
 * Hidden: news feed, leaderboard, friend, group and mention suggestions, search, gallery, public army lists, badges;
 * the profile only shows the photo, the username and the « Banni » mark.
 * Kept: forum threads and replies, group messages, tasks and assignments, with the username marked « Banni ».
 * Nothing is deleted: everything shows again as soon as the sanction is lifted. A temporary suspension hides nothing.
 */
final class BannedMembers
{
    /** DQL condition: the member behind the User alias is not banned for good. */
    public static function notBanned(string $userAlias): string
    {
        return sprintf('(%1$s.suspendedAt IS NULL OR %1$s.suspendedUntil IS NOT NULL)', $userAlias);
    }

    /** SQL condition on an alias of the user table. */
    public static function notBannedSql(string $userTableAlias): string
    {
        return sprintf('(%1$s.suspended_at IS NULL OR %1$s.suspended_until IS NOT NULL)', $userTableAlias);
    }

    public static function exclude(QueryBuilder $queryBuilder, string $userAlias): QueryBuilder
    {
        return $queryBuilder->andWhere(self::notBanned($userAlias));
    }
}
