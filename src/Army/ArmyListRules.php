<?php

namespace App\Army;

use App\Entity\ArmyList;
use App\Entity\ArmyUnit;
use App\Repository\FactionEnhancementRepository;

/**
 * Matched play rules of an official army list (ArmyList::getBattleSize() set):
 *  - total points within the battle size;
 *  - at most 3 copies of a datasheet, 6 for BATTLELINE and DEDICATED TRANSPORT units, 1 for EPIC HERO units;
 *  - one Warlord, a CHARACTER;
 *  - enhancements of the list's detachment only, each taken once, by a CHARACTER that is not an EPIC HERO;
 *  - one entry per unit (quantity 1), so that Warlord and enhancements designate a single unit.
 *
 * The same limits drive the builder (assets/controllers/army_form_controller.js) through clientConfig().
 * Errors forbid saving; warnings describe what is still missing (no Warlord yet).
 */
final class ArmyListRules
{
    public const KEYWORD_CHARACTER = 'Character';
    public const KEYWORD_EPIC_HERO = 'Epic Hero';
    public const KEYWORD_BATTLELINE = 'Battleline';
    public const KEYWORD_DEDICATED_TRANSPORT = 'Dedicated Transport';
    public const DEFAULT_COPY_LIMIT = 3;
    public const EXTENDED_COPY_LIMIT = 6;
    public const EPIC_HERO_COPY_LIMIT = 1;

    public function __construct(private readonly FactionEnhancementRepository $enhancementRepository)
    {
    }

    /** @param list<string> $keywords */
    public static function copyLimit(array $keywords): int
    {
        return match (true) {
            in_array(self::KEYWORD_EPIC_HERO, $keywords, true) => self::EPIC_HERO_COPY_LIMIT,
            in_array(self::KEYWORD_BATTLELINE, $keywords, true), in_array(self::KEYWORD_DEDICATED_TRANSPORT, $keywords, true) => self::EXTENDED_COPY_LIMIT,
            default => self::DEFAULT_COPY_LIMIT,
        };
    }

    /** @param list<string> $keywords */
    public static function canBeWarlord(array $keywords): bool
    {
        return in_array(self::KEYWORD_CHARACTER, $keywords, true);
    }

    /** @param list<string> $keywords */
    public static function canTakeEnhancement(array $keywords): bool
    {
        return in_array(self::KEYWORD_CHARACTER, $keywords, true) && !in_array(self::KEYWORD_EPIC_HERO, $keywords, true);
    }

    /**
     * Limits shared with the builder, so that both sides apply the same numbers and keywords.
     *
     * @return array<string, mixed>
     */
    public static function clientConfig(): array
    {
        return [
            'battleSizes' => array_map(static fn (BattleSize $size): array => [
                'value' => $size->value,
                'label' => $size->label() . ' (' . $size->pointsLimit() . ' pts)',
            ], BattleSize::cases()),
            'keywords' => [
                'character' => self::KEYWORD_CHARACTER,
                'epicHero' => self::KEYWORD_EPIC_HERO,
                'battleline' => self::KEYWORD_BATTLELINE,
                'dedicatedTransport' => self::KEYWORD_DEDICATED_TRANSPORT,
            ],
            'copyLimits' => [
                'default' => self::DEFAULT_COPY_LIMIT,
                'extended' => self::EXTENDED_COPY_LIMIT,
                'epicHero' => self::EPIC_HERO_COPY_LIMIT,
            ],
        ];
    }

    public function check(ArmyList $armyList): RuleReport
    {
        $report = new RuleReport();
        $battleSize = $armyList->getBattleSize();
        if ($battleSize === null) {
            return $report;
        }
        $units = $armyList->getUnits()->toArray();
        $this->checkPoints($armyList, $battleSize, $report);
        $this->checkEntries($units, $report);
        $this->checkCopies($units, $report);
        $this->checkWarlord($units, $report);
        $this->checkEnhancements($armyList, $units, $report);
        return $report;
    }

    private function checkPoints(ArmyList $armyList, BattleSize $battleSize, RuleReport $report): void
    {
        $total = $armyList->computeTotalPoints();
        if ($total > $battleSize->pointsLimit()) {
            $report->addError(sprintf(
                'La liste fait %d pts : le format %s est limité à %d pts.',
                $total,
                $battleSize->label(),
                $battleSize->pointsLimit(),
            ));
        }
    }

    /** @param list<ArmyUnit> $units */
    private function checkEntries(array $units, RuleReport $report): void
    {
        foreach ($units as $unit) {
            if ($unit->getQuantity() !== 1) {
                $report->addError(sprintf('%s : en liste officielle, chaque unité est une entrée distincte (quantité 1).', $unit->getName()));
            }
        }
    }

    /** @param list<ArmyUnit> $units */
    private function checkCopies(array $units, RuleReport $report): void
    {
        $copies = [];
        foreach ($units as $unit) {
            $name = (string) $unit->getName();
            $copies[$name] ??= ['count' => 0, 'limit' => self::copyLimit($unit->getKeywords())];
            $copies[$name]['count'] += $unit->getQuantity();
        }
        foreach ($copies as $name => ['count' => $count, 'limit' => $limit]) {
            if ($count > $limit) {
                $report->addError($limit === self::EPIC_HERO_COPY_LIMIT
                    ? sprintf('%s est un personnage épique : un seul exemplaire autorisé.', $name)
                    : sprintf('%s : %d exemplaires, %d autorisés au maximum.', $name, $count, $limit));
            }
        }
    }

    /** @param list<ArmyUnit> $units */
    private function checkWarlord(array $units, RuleReport $report): void
    {
        $warlords = array_filter($units, static fn (ArmyUnit $unit): bool => $unit->isWarlord());
        if (count($warlords) > 1) {
            $report->addError('Une seule unité peut être désignée Seigneur de guerre.');
        }
        foreach ($warlords as $warlord) {
            if (!self::canBeWarlord($warlord->getKeywords())) {
                $report->addError(sprintf('%s n\'est pas un personnage : il ne peut pas être Seigneur de guerre.', $warlord->getName()));
            }
        }
        if ($warlords === [] && $units !== []) {
            $report->addWarning('Aucun Seigneur de guerre désigné : choisis un personnage de la liste.');
        }
    }

    /** @param list<ArmyUnit> $units */
    private function checkEnhancements(ArmyList $armyList, array $units, RuleReport $report): void
    {
        $allowed = [];
        if ($armyList->getDetachment() !== null) {
            foreach ($this->enhancementRepository->findForDetachment((string) $armyList->getFaction(), $armyList->getDetachment()) as $enhancement) {
                $allowed[$enhancement->getName()] = true;
            }
        }
        $taken = [];
        foreach ($units as $unit) {
            $enhancementName = $unit->getEnhancementName();
            if ($enhancementName === null) {
                continue;
            }
            if (!isset($allowed[$enhancementName])) {
                $report->addError(sprintf('L\'amélioration « %s » n\'appartient pas au détachement de la liste.', $enhancementName));
            }
            if (!self::canTakeEnhancement($unit->getKeywords())) {
                $report->addError(sprintf('%s ne peut pas recevoir d\'amélioration (personnage non épique uniquement).', $unit->getName()));
            }
            if (isset($taken[$enhancementName])) {
                $report->addError(sprintf('L\'amélioration « %s » est prise plusieurs fois.', $enhancementName));
            }
            $taken[$enhancementName] = true;
        }
    }
}
