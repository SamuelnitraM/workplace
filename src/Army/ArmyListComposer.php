<?php

namespace App\Army;

use App\Entity\ArmyList;
use App\Entity\ArmyUnit;
use App\Entity\FactionUnit;
use App\Repository\FactionDetachementRepository;
use App\Repository\FactionEnhancementRepository;
use App\Repository\FactionUnitRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Applies the army list form (creation, edition) to an ArmyList: name, detachment, battle size, description,
 * visibility and units with their size, Warlord and enhancement.
 *
 * Unit data (name, points, keywords, profiles) and enhancement costs ALWAYS come from the database:
 * the client only sends ids, quantities and choices. On error, the caller must not flush: units may be partly updated in memory.
 */
final class ArmyListComposer
{
    public const NAME_MAX_LENGTH = 255;
    public const DETACHMENT_MAX_LENGTH = 155;
    public const MAX_UNITS = 200;
    public const MAX_QUANTITY = 99;

    public function __construct(
        private readonly FactionUnitRepository $factionUnitRepository,
        private readonly FactionDetachementRepository $detachmentRepository,
        private readonly FactionEnhancementRepository $enhancementRepository,
    ) {
    }

    /**
     * @return string|null error message, or null when the form was applied
     */
    public function apply(Request $request, ArmyList $armyList): ?string
    {
        $faction = (string) $armyList->getFaction();
        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            return 'Le nom de la liste est obligatoire.';
        }
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            return sprintf('Le nom de la liste ne doit pas dépasser %d caractères.', self::NAME_MAX_LENGTH);
        }
        $detachment = trim((string) $request->request->get('detachment', ''));
        // A detachment already saved is kept even if it disappeared from BSData since
        if ($detachment !== '' && $detachment !== $armyList->getDetachment()
            && (mb_strlen($detachment) > self::DETACHMENT_MAX_LENGTH || !$this->detachmentRepository->findOneBy(['faction' => $faction, 'name' => $detachment]))) {
            return 'Détachement invalide pour cette faction.';
        }
        $battleSizeValue = (string) $request->request->get('battleSize', '');
        $battleSize = $battleSizeValue === '' ? null : BattleSize::tryFrom((int) $battleSizeValue);
        if ($battleSizeValue !== '' && $battleSize === null) {
            return 'Format de partie invalide.';
        }
        try {
            $unitsData = json_decode((string) $request->request->get('units_json', '[]'), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'La composition de la liste est illisible, merci de réessayer.';
        }
        if (!is_array($unitsData) || !array_is_list($unitsData)) {
            return 'La composition de la liste est invalide.';
        }
        if (count($unitsData) > self::MAX_UNITS) {
            return sprintf('Une liste ne peut pas contenir plus de %d entrées.', self::MAX_UNITS);
        }
        $enhancements = $this->enhancementsByName($faction, $detachment);
        $existingUnits = [];
        foreach ($armyList->getUnits() as $existing) {
            if ($existing->getId() !== null) {
                $existingUnits[$existing->getId()] = $existing;
            }
        }
        $keptUnits = [];
        $newUnits = [];
        foreach ($unitsData as $unitData) {
            if (!is_array($unitData)) {
                return 'La composition de la liste est invalide.';
            }
            $unit = $this->resolveUnit($unitData, $faction, $existingUnits, $keptUnits);
            if (is_string($unit)) {
                return $unit;
            }
            $error = $this->applyUnitChoices($unit, $unitData, $enhancements);
            if ($error !== null) {
                return $error;
            }
            if ($unit->getId() !== null) {
                $keptUnits[$unit->getId()] = $unit;
            } else {
                $newUnits[] = $unit;
            }
        }
        $armyList->setName($name);
        $armyList->setDetachment($detachment !== '' ? $detachment : null);
        $armyList->setBattleSize($battleSize);
        $description = trim((string) $request->request->get('description', ''));
        $armyList->setDescription($description !== '' ? $description : null);
        $armyList->setIsPublic($request->request->get('isPublic') === '1');
        // orphanRemoval: units removed from the collection are deleted at flush
        foreach ($existingUnits as $existingId => $existing) {
            if (!isset($keptUnits[$existingId])) {
                $armyList->removeUnit($existing);
            }
        }
        foreach ($newUnits as $unit) {
            $armyList->addUnit($unit);
        }
        $armyList->setTotalPoints($armyList->computeTotalPoints());
        return null;
    }

    /** Copy of a catalogue unit, ready to be added to a list (default size). */
    public function createUnit(FactionUnit $factionUnit): ArmyUnit
    {
        return (new ArmyUnit())
            ->setName($factionUnit->getNameFr() ?: (string) $factionUnit->getName())
            ->setPoints($factionUnit->getPoints() ?? 0)
            ->setCategory($factionUnit->getCategory())
            ->setStatsData($factionUnit->getStatsData())
            ->setQuantity(1);
    }

    /**
     * Unit sizes of a datasheet: models => points (BSData pointsOptions).
     *
     * @return array<int, int>
     */
    public static function sizeOptions(?array $statsData): array
    {
        $options = [];
        foreach ($statsData['pointsOptions'] ?? [] as $option) {
            if (isset($option['models'], $option['points']) && (int) $option['models'] > 0) {
                $options[(int) $option['models']] = (int) $option['points'];
            }
        }
        return $options;
    }

    /**
     * @param array<int, ArmyUnit> $existingUnits
     * @param array<int, ArmyUnit> $keptUnits
     */
    private function resolveUnit(array $unitData, string $faction, array $existingUnits, array $keptUnits): ArmyUnit|string
    {
        $quantity = filter_var($unitData['quantity'] ?? 1, FILTER_VALIDATE_INT);
        $quantity = max(1, min(self::MAX_QUANTITY, $quantity === false ? 1 : $quantity));
        $armyUnitId = filter_var($unitData['armyUnitId'] ?? null, FILTER_VALIDATE_INT);
        $factionUnitId = filter_var($unitData['factionUnitId'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($armyUnitId) && isset($existingUnits[$armyUnitId])) {
            if (isset($keptUnits[$armyUnitId])) {
                return 'La composition de la liste est invalide.';
            }
            $unit = $existingUnits[$armyUnitId];
            if ($unit->getStatsData() === null) {
                $match = $this->factionUnitRepository->findOneBy(['faction' => $faction, 'name' => $unit->getName()])
                    ?? $this->factionUnitRepository->findOneBy(['faction' => $faction, 'nameFr' => $unit->getName()]);
                $unit->setStatsData($match?->getStatsData());
            }
            return $unit->setQuantity($quantity);
        }
        if (is_int($factionUnitId)) {
            $factionUnit = $this->factionUnitRepository->find($factionUnitId);
            if (!$factionUnit || $factionUnit->getFaction() !== $faction) {
                return 'Une des unités sélectionnées n\'appartient pas à cette faction.';
            }
            return $this->createUnit($factionUnit)->setQuantity($quantity);
        }
        return 'Une des unités sélectionnées est introuvable.';
    }

    /**
     * Size, Warlord and enhancement of a unit.
     *
     * @param array<string, array{name: string, points: int}> $enhancements
     */
    private function applyUnitChoices(ArmyUnit $unit, array $unitData, array $enhancements): ?string
    {
        $sizes = self::sizeOptions($unit->getStatsData());
        $modelCount = filter_var($unitData['modelCount'] ?? null, FILTER_VALIDATE_INT);
        if (is_int($modelCount) && $sizes !== []) {
            if (!isset($sizes[$modelCount])) {
                return sprintf('Taille d\'unité invalide pour %s.', $unit->getName());
            }
            $unit->setModelCount($modelCount)->setPoints($sizes[$modelCount]);
        }
        $unit->setWarlord(($unitData['warlord'] ?? false) === true);
        $enhancementName = is_string($unitData['enhancement'] ?? null) ? trim($unitData['enhancement']) : '';
        if ($enhancementName === '') {
            $unit->setEnhancement(null, 0);
            return null;
        }
        // An enhancement already saved is kept when the detachment data changed since
        if ($enhancementName === $unit->getEnhancementName() && !isset($enhancements[$enhancementName])) {
            return null;
        }
        if (!isset($enhancements[$enhancementName])) {
            return sprintf('L\'amélioration « %s » n\'existe pas pour ce détachement.', $enhancementName);
        }
        $unit->setEnhancement($enhancementName, $enhancements[$enhancementName]['points']);
        return null;
    }

    /** @return array<string, array{name: string, points: int}> */
    private function enhancementsByName(string $faction, string $detachment): array
    {
        if ($detachment === '') {
            return [];
        }
        $byName = [];
        foreach ($this->enhancementRepository->findForDetachment($faction, $detachment) as $enhancement) {
            $byName[$enhancement->getName()] = ['name' => $enhancement->getName(), 'points' => $enhancement->getPoints()];
        }
        return $byName;
    }
}
