<?php

namespace App\Army;

use App\Entity\ArmyList;

/** List built by ArmyListTextFormat::import() (not persisted) and the lines that were not recognised. */
final class ImportResult
{
    /**
     * @param list<string> $unmatchedLines
     */
    public function __construct(
        public readonly ArmyList $armyList,
        public readonly array $unmatchedLines,
    ) {
    }
}
