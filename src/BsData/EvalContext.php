<?php

namespace App\BsData;

/**
 * Contexte d'évaluation d'une condition BattleScribe (voir ConditionEvaluator).
 */
final class EvalContext
{
    /**
     * @param list<string> $selfIds identifiants du nœud évalué (lien + cible)
     * @param list<string> $categoryIds catégories du nœud évalué
     * @param bool $rootLevel nœud au niveau racine (roster vide : les décomptes locaux valent 0)
     * @param list<string> $unitIds identifiants de l'unité en cours (scope = id de l'unité)
     * @param array<string, int>|null $modelCounts décomptes simulés dans l'unité (clé « model » = total de figurines)
     */
    public function __construct(
        public readonly array $selfIds = [],
        public readonly array $categoryIds = [],
        public readonly bool $rootLevel = false,
        public readonly array $unitIds = [],
        public readonly ?array $modelCounts = null,
    ) {
    }

    public static function forNode(?array $link, array $entry, bool $rootLevel = false, array $unitIds = [], ?array $modelCounts = null): self
    {
        $selfIds = array_values(array_filter([$link['id'] ?? null, $link['targetId'] ?? null, $entry['id'] ?? null], 'is_string'));
        $categoryIds = [];
        foreach ([$link, $entry] as $node) {
            foreach ($node['categoryLinks'] ?? [] as $cat) {
                if (isset($cat['targetId'])) {
                    $categoryIds[] = $cat['targetId'];
                }
            }
        }

        return new self($selfIds, $categoryIds, $rootLevel, $unitIds, $modelCounts);
    }
}
