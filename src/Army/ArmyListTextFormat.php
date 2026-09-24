<?php

namespace App\Army;

use App\Entity\ArmyList;
use App\Entity\ArmyUnit;
use App\Entity\FactionUnit;
use App\Entity\User;
use App\Repository\FactionDetachementRepository;
use App\Repository\FactionEnhancementRepository;
use App\Repository\FactionUnitRepository;
use App\Service\BsDataFetcher;

/**
 * Text format of an army list, the one of the official Warhammer 40,000 application:
 *
 *     Ma liste (1995 Points)
 *
 *     Space Marines
 *     Strike Force (2000 Points)
 *     Gladius Task Force
 *
 *     CHARACTERS
 *
 *     Captain (100 Points)
 *       • Warlord
 *       • Enhancements: Artificer Armour
 *
 * Unit points include the enhancement. The import reads the same layout: faction, battle size and detachment
 * lines are recognised anywhere in the header, each "Name (N Points)" line is a unit whose size is deduced
 * from its points.
 */
final class ArmyListTextFormat
{
    private const SECTIONS = [
        'CHARACTERS' => [ArmyListRules::KEYWORD_EPIC_HERO, ArmyListRules::KEYWORD_CHARACTER],
        'BATTLELINE' => [ArmyListRules::KEYWORD_BATTLELINE],
        'DEDICATED TRANSPORTS' => [ArmyListRules::KEYWORD_DEDICATED_TRANSPORT],
    ];
    private const OTHER_SECTION = 'OTHER DATASHEETS';
    private const UNIT_LINE = '/^(?<name>.+?)\s*\((?<points>\d+)\s*(?:points?|pts)\)\s*$/iu';
    private const MAX_TEXT_LENGTH = 20000;

    public function __construct(
        private readonly FactionUnitRepository $factionUnitRepository,
        private readonly FactionDetachementRepository $detachmentRepository,
        private readonly FactionEnhancementRepository $enhancementRepository,
        private readonly ArmyListComposer $composer,
    ) {
    }

    public function export(ArmyList $armyList): string
    {
        $lines = [sprintf('%s (%d Points)', $armyList->getName(), $armyList->computeTotalPoints()), '', (string) $armyList->getFaction()];
        if ($armyList->getBattleSize() !== null) {
            $lines[] = sprintf('%s (%d Points)', $armyList->getBattleSize()->englishName(), $armyList->getBattleSize()->pointsLimit());
        }
        if ($armyList->getDetachment() !== null) {
            $lines[] = $armyList->getDetachment();
        }
        $sections = [];
        foreach ($armyList->getUnits() as $unit) {
            $sections[$this->sectionOf($unit)][] = $unit;
        }
        foreach ([...array_keys(self::SECTIONS), self::OTHER_SECTION] as $section) {
            if (empty($sections[$section])) {
                continue;
            }
            array_push($lines, '', $section);
            foreach ($sections[$section] as $unit) {
                // A quantity greater than 1 (free list) is written as separate units, like the official application
                for ($copy = 0; $copy < $unit->getQuantity(); ++$copy) {
                    array_push($lines, '', sprintf('%s (%d Points)', $unit->getName(), $unit->getPoints() + $unit->getEnhancementPoints()));
                    if ($unit->isWarlord() && $copy === 0) {
                        $lines[] = '  • Warlord';
                    }
                    if ($unit->getModelCount() !== null) {
                        $lines[] = sprintf('  • %d models', $unit->getModelCount());
                    }
                    if ($unit->getEnhancementName() !== null && $copy === 0) {
                        $lines[] = '  • Enhancements: ' . $unit->getEnhancementName();
                    }
                }
            }
        }
        array_push($lines, '', 'Exported with SprueHub');
        return implode("\n", $lines) . "\n";
    }

    /**
     * Builds a new private list from a text. $faction forces the faction; otherwise it is read from the text.
     *
     * @return ImportResult|string the list (not persisted), or an error message
     */
    public function import(string $text, ?string $faction, User $owner): ImportResult|string
    {
        if (trim($text) === '') {
            return 'Colle le texte de ta liste.';
        }
        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            return 'Le texte est trop long pour une liste d\'armée.';
        }
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? ''), preg_split('/\R/u', $text) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
        $faction ??= $this->detectFaction($lines);
        if ($faction === null || !array_key_exists($faction, BsDataFetcher::FACTION_FILES)) {
            return 'Faction introuvable dans le texte : choisis-la dans la liste.';
        }
        $catalogue = $this->catalogueOf($faction);
        $detachments = [];
        foreach ($this->detachmentRepository->findBy(['faction' => $faction]) as $detachment) {
            $detachments[mb_strtolower($detachment->getName())] = $detachment->getName();
        }
        $armyList = (new ArmyList())->setFaction($faction)->setOwner($owner)->setIsPublic(false);
        $listName = null;
        $enhancementCosts = [];
        $currentUnit = null;
        $listedPoints = [];
        $unmatched = [];
        foreach ($lines as $index => $line) {
            $lowerLine = mb_strtolower($line);
            $bullet = preg_match('/^[•◦\-*]\s*(?<content>.+)$/u', $line, $bulletMatch) === 1 ? trim($bulletMatch['content']) : null;
            if ($bullet !== null) {
                $currentUnit = $this->applyBullet($bullet, $currentUnit, $enhancementCosts);
                continue;
            }
            if (isset($detachments[$lowerLine])) {
                $armyList->setDetachment($detachments[$lowerLine]);
                $enhancementCosts = $this->enhancementCosts($faction, $detachments[$lowerLine]);
                continue;
            }
            if (mb_strtolower($faction) === $lowerLine || in_array($line, ['CHARACTERS', 'BATTLELINE', 'DEDICATED TRANSPORTS', 'OTHER DATASHEETS', 'ALLIED UNITS'], true) || str_starts_with($lowerLine, 'exported with')) {
                continue;
            }
            if (preg_match(self::UNIT_LINE, $line, $unitMatch) !== 1) {
                $unmatched[] = $line;
                continue;
            }
            $battleSize = $this->battleSizeNamed($unitMatch['name']);
            if ($battleSize !== null) {
                $armyList->setBattleSize($battleSize);
                continue;
            }
            $factionUnit = $catalogue[mb_strtolower($this->cleanUnitName($unitMatch['name']))] ?? null;
            if ($factionUnit === null) {
                // The first line is the name of the list
                if ($index === 0 && $listName === null) {
                    $listName = $unitMatch['name'];
                } else {
                    $unmatched[] = $line;
                }
                $currentUnit = null;
                continue;
            }
            $currentUnit = $this->composer->createUnit($factionUnit);
            $listedPoints[spl_object_id($currentUnit)] = (int) $unitMatch['points'];
            $armyList->addUnit($currentUnit);
        }
        foreach ($armyList->getUnits() as $unit) {
            $this->deduceSize($unit, $listedPoints[spl_object_id($unit)] ?? null);
        }
        $armyList->setName(mb_substr($listName ?? 'Liste importée', 0, ArmyListComposer::NAME_MAX_LENGTH));
        $armyList->setTotalPoints($armyList->computeTotalPoints());
        if ($armyList->getUnits()->isEmpty()) {
            return 'Aucune unité de la faction ' . $faction . ' n\'a été reconnue dans le texte.';
        }
        return new ImportResult($armyList, $unmatched);
    }

    private function sectionOf(ArmyUnit $unit): string
    {
        foreach (self::SECTIONS as $section => $keywords) {
            foreach ($keywords as $keyword) {
                if ($unit->hasKeyword($keyword)) {
                    return $section;
                }
            }
        }
        return self::OTHER_SECTION;
    }

    /**
     * Warlord, enhancement or model count line of the current unit.
     *
     * @param array<string, array{name: string, points: int}> $enhancementCosts
     */
    private function applyBullet(string $bullet, ?ArmyUnit $unit, array $enhancementCosts): ?ArmyUnit
    {
        if ($unit === null) {
            return null;
        }
        if (mb_strtolower($bullet) === 'warlord') {
            $unit->setWarlord(true);
        } elseif (preg_match('/^enhancements?\s*:\s*(?<name>.+?)(?:\s*\(\+?\d+\s*(?:points?|pts)\))?$/iu', $bullet, $match) === 1) {
            $enhancement = $enhancementCosts[mb_strtolower($match['name'])] ?? null;
            if ($enhancement !== null) {
                $unit->setEnhancement($enhancement['name'], $enhancement['points']);
            }
        } elseif (preg_match('/^(?<count>\d+)\s*models?$/iu', $bullet, $match) === 1) {
            $unit->setModelCount((int) $match['count']);
        }
        return $unit;
    }

    /** Unit size whose cost matches the listed points (enhancement excluded), the default size otherwise. */
    private function deduceSize(ArmyUnit $unit, ?int $listedPoints): void
    {
        $sizes = ArmyListComposer::sizeOptions($unit->getStatsData());
        $modelCount = $unit->getModelCount();
        if ($modelCount !== null && isset($sizes[$modelCount])) {
            $unit->setPoints($sizes[$modelCount]);
            return;
        }
        $unit->setModelCount(null);
        if ($listedPoints === null) {
            return;
        }
        $modelCount = array_search($listedPoints - $unit->getEnhancementPoints(), $sizes, true);
        if ($modelCount !== false) {
            $unit->setModelCount($modelCount)->setPoints($sizes[$modelCount]);
        }
    }

    /** @param list<string> $lines */
    private function detectFaction(array $lines): ?string
    {
        $factions = [];
        foreach (array_keys(BsDataFetcher::FACTION_FILES) as $faction) {
            $factions[mb_strtolower($faction)] = $faction;
        }
        foreach ($lines as $line) {
            if (isset($factions[mb_strtolower($line)])) {
                return $factions[mb_strtolower($line)];
            }
        }
        return null;
    }

    private function battleSizeNamed(string $name): ?BattleSize
    {
        foreach (BattleSize::cases() as $battleSize) {
            if (mb_strtolower($name) === mb_strtolower($battleSize->englishName()) || mb_strtolower($name) === mb_strtolower($battleSize->label())) {
                return $battleSize;
            }
        }
        return null;
    }

    /** "Captain [Legends]" and "Captain" designate the same datasheet. */
    private function cleanUnitName(string $name): string
    {
        return trim((string) preg_replace('/\s*\[legends\]\s*/iu', ' ', $name));
    }

    /** @return array<string, FactionUnit> lower-case English and French names => catalogue unit */
    private function catalogueOf(string $faction): array
    {
        $catalogue = [];
        foreach ($this->factionUnitRepository->findBy(['faction' => $faction]) as $factionUnit) {
            if ($factionUnit->getNameFr() !== null) {
                $catalogue[mb_strtolower($factionUnit->getNameFr())] ??= $factionUnit;
            }
            $catalogue[mb_strtolower((string) $factionUnit->getName())] = $factionUnit;
        }
        return $catalogue;
    }

    /** @return array<string, array{name: string, points: int}> */
    private function enhancementCosts(string $faction, string $detachment): array
    {
        $costs = [];
        foreach ($this->enhancementRepository->findForDetachment($faction, $detachment) as $enhancement) {
            $costs[mb_strtolower($enhancement->getName())] = ['name' => $enhancement->getName(), 'points' => $enhancement->getPoints()];
        }
        return $costs;
    }
}
