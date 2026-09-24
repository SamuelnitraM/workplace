<?php

namespace App\Army;

/**
 * Battle sizes of an official (matched play) army list: the value is the points limit.
 * A list without battle size is a free list, built without any rule check.
 */
enum BattleSize: int
{
    case Incursion = 1000;
    case StrikeForce = 2000;
    case Onslaught = 3000;

    public function label(): string
    {
        return match ($this) {
            self::Incursion => 'Incursion',
            self::StrikeForce => 'Force de frappe',
            self::Onslaught => 'Assaut',
        };
    }

    /** Name used by the official application and the text export. */
    public function englishName(): string
    {
        return match ($this) {
            self::Incursion => 'Incursion',
            self::StrikeForce => 'Strike Force',
            self::Onslaught => 'Onslaught',
        };
    }

    public function pointsLimit(): int
    {
        return $this->value;
    }
}
