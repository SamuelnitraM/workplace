<?php

namespace App\Army;

/**
 * Audience counters of an army list, each stored in its own column of army_list.
 * Actions of the list's owner are never counted.
 */
enum ArmyListCounter: string
{
    case View = 'view';
    case Export = 'export';
    case Duplication = 'duplication';

    /** ArmyList property holding the counter. */
    public function property(): string
    {
        return match ($this) {
            self::View => 'viewCount',
            self::Export => 'exportCount',
            self::Duplication => 'duplicationCount',
        };
    }

    /** Views and exports count once per visitor session; every duplication counts. */
    public function isCountedOncePerSession(): bool
    {
        return $this !== self::Duplication;
    }
}
