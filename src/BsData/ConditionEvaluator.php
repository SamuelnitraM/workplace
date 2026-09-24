<?php

namespace App\BsData;

/**
 * Évaluation STATIQUE des modificateurs BattleScribe (conditions, conditionGroups, modifierGroups) pour
 * savoir si une entrée est masquée et quel est son coût, sans roster réel.
 *
 * Contexte simulé : un roster « Army Roster » (partie standard, ni Croisade ni Boarding Actions) dont le
 * catalogue principal est celui de la faction, vide au départ, avec la bascule « Show Legends » activée
 * (on garde les unités Legends, signalées comme telles) et toutes les autres bascules « Show … » désactivées
 * (alliés, personnages Crucible…).
 *
 * Logique à trois états : chaque condition vaut true, false ou null (= indécidable hors d'un vrai roster,
 * ex. « si le joueur a choisi telle option d'équipement »). Un modificateur n'est appliqué que si ses
 * conditions valent true : dans le doute, on garde l'état par défaut de la donnée.
 */
final class ConditionEvaluator
{
    public const PTS_COST_TYPE_ID = '51b2-306e-1021-d207';
    /**
     * Bascules « Show … » laissées DÉSACTIVÉES : personnages Crucible, contenus narratifs, forces non alignées.
     * Toutes les autres (Show Legends, Show Khorne Daemons, Show Imperial Knights…) sont activées : ce sont
     * des filtres d'affichage ; les alliés d'une autre faction sont écartés ensuite par mot-clé de faction
     * (voir UnitExtractor).
     */
    private const DISABLED_TOGGLES_PATTERN = '/Crucible|content|Unaligned/i';
    private const SELF_SCOPES = ['self', 'parent', 'unit-self', 'root-entry', 'ancestor'];

    /** @var array<string, true> */
    private array $forceIds = [];
    private ?string $rosterForceId = null;
    /** @var array<string, true> */
    private array $enabledToggles = [];
    /** @var array<string, string> id => nom des bascules « Show … » du système de jeu */
    private array $toggleNames = [];

    public function __construct(private readonly CatalogueGraph $graph)
    {
        foreach ($graph->gameSystem()['forceEntries'] ?? [] as $force) {
            $this->forceIds[$force['id']] = true;
            if (($force['name'] ?? null) === 'Army Roster') {
                $this->rosterForceId = $force['id'];
            }
            foreach ($force['forceEntries'] ?? [] as $sub) {
                $this->forceIds[$sub['id']] = true;
            }
        }
        foreach ($graph->toggles() as $id => $name) {
            $this->toggleNames[$id] = $name;
            if (!preg_match(self::DISABLED_TOGGLES_PATTERN, $name)) {
                $this->enabledToggles[$id] = true;
            }
        }
    }

    /**
     * Une entrée (et le lien qui y mène) est-elle masquée dans le contexte simulé ?
     *
     * @param array|null $link entryLink éventuel
     * @param array $entry entrée ou groupe ciblé
     * @param EvalContext $ctx
     */
    public function isHidden(?array $link, array $entry, EvalContext $ctx): bool
    {
        foreach (array_filter([$link, $entry]) as $node) {
            $hidden = (bool) ($node['hidden'] ?? false);
            foreach ($this->flattenModifiers($node) as [$modifier, $conditionSets]) {
                if (($modifier['field'] ?? null) !== 'hidden' || ($modifier['type'] ?? null) !== 'set') {
                    continue;
                }
                if ($this->allTrue($conditionSets, $ctx) === true) {
                    $hidden = filter_var($modifier['value'] ?? false, FILTER_VALIDATE_BOOLEAN);
                }
            }
            if ($hidden) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le masquage d'une entrée dépend-il de la bascule « Show Legends » ? (= unité Legends)
     */
    public function dependsOnToggle(?array $link, array $entry, string $toggleName): bool
    {
        $ids = array_keys(array_filter($this->toggleNames, fn(string $n) => $n === $toggleName));
        if (!$ids) {
            return false;
        }
        foreach (array_filter([$link, $entry]) as $node) {
            foreach ($this->flattenModifiers($node) as [$modifier, $conditionSets]) {
                if (($modifier['field'] ?? null) !== 'hidden') {
                    continue;
                }
                foreach ($conditionSets as $set) {
                    if ($this->mentionsChild($set, $ids)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Coût (pts) d'un nœud : coût de base + modificateurs applicables dans le contexte.
     */
    public function points(?array $link, array $entry, EvalContext $ctx): float
    {
        $value = $this->baseCost($entry);
        if ($link !== null) {
            $linkCost = $this->baseCost($link);
            if ($linkCost !== 0.0) {
                $value = $linkCost;
            }
        }

        foreach (array_filter([$entry, $link]) as $node) {
            foreach ($this->flattenModifiers($node) as [$modifier, $conditionSets]) {
                if (($modifier['field'] ?? null) !== self::PTS_COST_TYPE_ID || isset($modifier['repeats'])) {
                    continue;
                }
                if ($this->allTrue($conditionSets, $ctx) !== true) {
                    continue;
                }
                $v = (float) ($modifier['value'] ?? 0);
                $value = match ($modifier['type'] ?? null) {
                    'set' => $v,
                    'increment' => $value + $v,
                    'decrement' => $value - $v,
                    'multiply' => $value * $v,
                    default => $value,
                };
            }
        }

        return max(0.0, $value);
    }

    /**
     * Conditions de nombre de figurines portant sur le coût (pts) : [childId, seuil minimal inclus].
     * Ex. « set 145 si plus de 10 figurines » → ['model', 11].
     *
     * @return list<array{childId: string, min: int}>
     */
    public function pointThresholds(array $entry): array
    {
        $thresholds = [];
        foreach ($this->flattenModifiers($entry) as [$modifier, $conditionSets]) {
            if (($modifier['field'] ?? null) !== self::PTS_COST_TYPE_ID) {
                continue;
            }
            foreach ($conditionSets as $set) {
                foreach ($this->iterateConditions($set) as $c) {
                    if (($c['field'] ?? null) !== 'selections') {
                        continue;
                    }
                    $v = (int) ($c['value'] ?? 0);
                    $min = match ($c['type'] ?? null) {
                        'atLeast', 'equalTo' => $v,
                        'greaterThan' => $v + 1,
                        default => null,
                    };
                    if ($min !== null && $min > 0) {
                        $thresholds[] = ['childId' => (string) $c['childId'], 'min' => $min];
                    }
                }
            }
        }

        return $thresholds;
    }

    /**
     * Applique les modificateurs statiques (sans condition, ou conditions vraies) de type set/append
     * sur le champ « name » d'un lien (ex. « Deadly Demise » + append « D3 »).
     */
    public function resolvedName(array $node, string $default, EvalContext $ctx): string
    {
        $name = $default;
        foreach ($this->flattenModifiers($node) as [$modifier, $conditionSets]) {
            if (($modifier['field'] ?? null) !== 'name' || $this->allTrue($conditionSets, $ctx) !== true) {
                continue;
            }
            $v = (string) ($modifier['value'] ?? '');
            $name = match ($modifier['type'] ?? null) {
                'set' => $v,
                'append' => trim($name . ' ' . $v),
                'prepend' => trim($v . ' ' . $name),
                default => $name,
            };
        }

        return $name;
    }

    private function baseCost(array $node): float
    {
        foreach ($node['costs'] ?? [] as $cost) {
            if (($cost['typeId'] ?? null) === self::PTS_COST_TYPE_ID || ($cost['name'] ?? null) === 'pts') {
                return (float) ($cost['value'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * Aplatit modifiers + modifierGroups (imbriqués) : chaque modificateur avec la liste des ensembles
     * de conditions (groupe parent compris) qui doivent TOUS être vrais.
     *
     * @return list<array{0: array, 1: list<array>}>
     */
    private function flattenModifiers(array $node, array $inherited = []): array
    {
        $out = [];
        foreach ($node['modifiers'] ?? [] as $modifier) {
            $out[] = [$modifier, array_merge($inherited, [$modifier])];
        }
        foreach ($node['modifierGroups'] ?? [] as $group) {
            $out = array_merge($out, $this->flattenModifiers($group, array_merge($inherited, [$group])));
        }

        return $out;
    }

    /** @param list<array> $conditionSets */
    private function allTrue(array $conditionSets, EvalContext $ctx): ?bool
    {
        $result = true;
        foreach ($conditionSets as $set) {
            $r = $this->evaluateSet($set, 'and', $ctx);
            if ($r === false) {
                return false;
            }
            if ($r === null) {
                $result = null;
            }
        }

        return $result;
    }

    /**
     * Évalue un porteur de conditions (modificateur, modifierGroup ou conditionGroup).
     */
    private function evaluateSet(array $holder, string $operator, EvalContext $ctx): ?bool
    {
        if (!empty($holder['localConditionGroups']) || !empty($holder['repeats'])) {
            return null;
        }

        $results = [];
        foreach ($holder['conditions'] ?? [] as $condition) {
            $results[] = $this->evaluateCondition($condition, $ctx);
        }
        foreach ($holder['conditionGroups'] ?? [] as $group) {
            $results[] = $this->evaluateSet($group, ($group['type'] ?? 'and') === 'or' ? 'or' : 'and', $ctx);
        }

        if ($results === []) {
            return true;
        }

        if ($operator === 'or') {
            if (in_array(true, $results, true)) {
                return true;
            }

            return in_array(null, $results, true) ? null : false;
        }

        if (in_array(false, $results, true)) {
            return false;
        }

        return in_array(null, $results, true) ? null : true;
    }

    private function evaluateCondition(array $c, EvalContext $ctx): ?bool
    {
        $type = $c['type'] ?? null;
        $childId = (string) ($c['childId'] ?? '');
        $scope = (string) ($c['scope'] ?? '');
        $field = (string) ($c['field'] ?? '');

        if ($type === 'instanceOf' || $type === 'notInstanceOf') {
            $is = $this->instanceOf($childId, $scope, $ctx);

            return $is === null ? null : ($type === 'instanceOf' ? $is : !$is);
        }

        $count = $this->count($childId, $scope, $field, $ctx);
        if ($count === null || !empty($c['percentValue'])) {
            return null;
        }
        $value = (float) ($c['value'] ?? 0);

        return match ($type) {
            'atLeast' => $count >= $value,
            'atMost' => $count <= $value,
            'greaterThan' => $count > $value,
            'lessThan' => $count < $value,
            'equalTo' => $count == $value,
            'notEqualTo' => $count != $value,
            default => null,
        };
    }

    private function instanceOf(string $childId, string $scope, EvalContext $ctx): ?bool
    {
        if ($scope === 'primary-catalogue') {
            return $childId === $this->graph->primaryCatalogueId();
        }
        if ($scope === 'force' || $scope === 'roster') {
            if (isset($this->forceIds[$childId])) {
                return $childId === $this->rosterForceId;
            }
            if ($this->graph->isCatalogueId($childId)) {
                return $childId === $this->graph->primaryCatalogueId();
            }

            return null;
        }
        if ($scope === 'self') {
            return in_array($childId, $ctx->selfIds, true) || in_array($childId, $ctx->categoryIds, true);
        }
        if ($scope === 'parent' && $ctx->rootLevel) {
            return false; // parent = la force
        }

        return null;
    }

    private function count(string $childId, string $scope, string $field, EvalContext $ctx): ?float
    {
        if ($field === 'forces') {
            if (($scope === 'roster' || $scope === 'force') && isset($this->forceIds[$childId])) {
                return $childId === $this->rosterForceId ? 1.0 : 0.0;
            }

            return null;
        }
        if ($field !== 'selections') {
            return null; // limites de coût, etc.
        }

        if (isset($this->enabledToggles[$childId])) {
            return 1.0;
        }
        if (in_array($scope, ['roster', 'force', 'primary-catalogue'], true)) {
            return 0.0; // roster vide : aucune autre sélection
        }

        $unitScope = in_array($scope, self::SELF_SCOPES, true) || in_array($scope, $ctx->unitIds, true);
        if ($unitScope) {
            if ($ctx->modelCounts !== null && ($childId === 'model' || isset($ctx->modelCounts[$childId]))) {
                return (float) ($childId === 'model' ? ($ctx->modelCounts['model'] ?? 0) : $ctx->modelCounts[$childId]);
            }
            if ($ctx->rootLevel) {
                return 0.0;
            }
        }

        return null;
    }

    /** @param list<string> $ids */
    private function mentionsChild(array $holder, array $ids): bool
    {
        foreach ($this->iterateConditions($holder) as $c) {
            if (in_array($c['childId'] ?? null, $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return \Generator<array> toutes les conditions d'un porteur, groupes imbriqués compris */
    private function iterateConditions(array $holder): \Generator
    {
        foreach ($holder['conditions'] ?? [] as $c) {
            yield $c;
        }
        foreach ($holder['conditionGroups'] ?? [] as $g) {
            yield from $this->iterateConditions($g);
        }
    }
}
