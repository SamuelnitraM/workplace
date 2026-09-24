<?php

namespace App\BsData;

use App\Army\UnitCategory;

/**
 * Extraction des unités jouables (et des détachements) d'une faction à partir de son graphe de catalogues.
 *
 * Unités jouables = entrées racines du catalogue principal + entrées racines des catalogues importés
 * (importRootEntries=true, transitivement), de type unit/model, visibles dans une partie standard
 * (voir ConditionEvaluator), hors exclusions explicites (EXCLUDED_NAME_PATTERNS / EXCLUDED_CATEGORIES).
 * Les unités Legends sont GARDÉES et signalées (statsData.legends = true).
 *
 * statsData (forme historique conservée, étendue de façon compatible) :
 *  - keywords: list<string>            mots-clés hors faction (sert au regroupement UnitCategory)
 *  - factionKeywords: list<string>     mots-clés de faction (« Faction: X » → « X »)
 *  - models: list<{name, stats: {M,T,Sv,W,LD,OC,InSv}, weapons: list<{name, weaponType, Range, A, BS|WS, S, AP, D, Keywords}>}>
 *  - roles: list<{name, weapons, abilities}>   figurines spéciales sans profil propre (ex. porte-étendard)
 *  - abilities: list<{name, description}>      capacités de l'unité (et de son équipement)
 *  - coreAbilities / factionAbilities: list<string>   règles référencées par nom (Deep Strike, Oath of Moment…)
 *  - invulnerableSave: ?string, damaged: ?{name, description}, leader: ?string, transport: ?string
 *  - legends: bool
 *  - pointsOptions: list<{models: int, points: int}>  (taille minimale d'abord, puis tailles supérieures)
 */
final class UnitExtractor
{
    /** Noms d'entrées racines exclues : contenus hors partie standard. */
    public const EXCLUDED_NAME_PATTERNS = [
        '/\[Crucible\]/i' => 'Crucible of Battle (personnage narratif)',
        '/\[Crusade\]/i' => 'Croisade',
        '/\bBoarding Actions?\b/i' => 'Boarding Actions',
        '/\[Kill Team\]/i' => 'Kill Team',
        '/\bApocalypse\b/i' => 'Apocalypse',
        '/\(Unbound Adversaries\)/i' => 'Unbound Adversaries (Croisade)',
    ];

    /** Catégories BSData excluant une entrée racine. */
    public const EXCLUDED_CATEGORIES = [
        'Crucible' => 'Crucible of Battle (personnage narratif)',
        'Unbound Adversaries' => 'Unbound Adversaries (Croisade)',
    ];

    /** Branches ignorées à l'intérieur d'une unité (Croisade, améliorations, seigneur de guerre…). */
    private const SKIPPED_BRANCH_PATTERNS = [
        '/^Crusade\b/i', '/Enhancement/i', '/^Warlord$/i', '/^Battle (Honours|Scars|Tallies)$/i',
        '/^Weapon (Modifications|Upgrades)$/i', '/^Experience Points$/i', '/^Legendary Veterans$/i', '/Crusade Relic/i',
        '/^Order of Battle$/i', '/^Totemic Presence upgrade$/i', '/^Tank Ace/i',
    ];

    /** Catégories techniques qui ne sont pas des mots-clés de jeu. */
    private const NON_KEYWORD_CATEGORIES = ['Configuration', 'Unit', 'Reference', 'Order of Battle', '3DP Detachment', 'Crucible'];

    private const WEAPON_TYPES = ['Ranged Weapons', 'Melee Weapons'];
    private const MAX_DEPTH = 40;

    private ConditionEvaluator $eval;

    // --- État de l'unité en cours d'extraction ---
    private array $models = [];
    private array $roles = [];
    /** @var array<string, array{name: string, description: string}> */
    private array $abilities = [];
    private array $coreRules = [];
    private array $factionRules = [];
    private array $pendingWeapons = [];
    private ?string $transport = null;
    /** @var list<string> */
    private array $unitIds = [];
    /** @var array<string, int> figurines minimales par identifiant de nœud (lien, entrée, groupe) */
    private array $minCountOf = [];

    /**
     * @param list<string> $allowedFactionKeywords mots-clés de faction de l'armée (sans « Faction: »),
     *                                             vide = pas de filtrage des alliés
     */
    public function __construct(
        private readonly CatalogueGraph $graph,
        private readonly array $allowedFactionKeywords = [],
    ) {
        $this->eval = new ConditionEvaluator($graph);
    }

    /**
     * Entrées racines de type unit/model visibles en partie standard, entrées exclues (avec raison) et
     * catalogues « de la même faction » (ayant fourni au moins une unité portant un mot-clé de l'armée).
     *
     * @return array{0: list<array>, 1: list<array{name: string, reason: string}>, 2: array<string, true>}
     */
    private function candidates(): array
    {
        $candidates = [];
        $excluded = [];
        $sameFactionFiles = [$this->graph->mainFile() => true];
        foreach ($this->graph->rootEntries() as ['node' => $node, 'file' => $file]) {
            [$link, $entry] = $this->resolveRoot($node);
            if ($entry === null || !in_array($entry['type'] ?? null, ['unit', 'model'], true)) {
                continue;
            }

            $rawName = (string) ($link['name'] ?? $entry['name'] ?? '');
            $reason = $this->exclusionReason($link, $entry, $rawName);
            if ($reason !== null) {
                $excluded[] = ['name' => $rawName, 'reason' => $reason];
                continue;
            }

            $factions = $this->factionKeywordsOf($link, $entry);
            $matches = $this->allowedFactionKeywords === [] || array_intersect($factions, $this->allowedFactionKeywords) !== [];
            if ($matches && $factions !== []) {
                $sameFactionFiles[$file] = true;
            }
            $candidates[] = compact('link', 'entry', 'rawName', 'file', 'factions', 'matches');
        }

        return [$candidates, $excluded, $sameFactionFiles];
    }

    /** @return list<string> */
    private function factionKeywordsOf(?array $link, array $entry): array
    {
        $factions = [];
        foreach ($this->categoryNames($link, $entry) as $name) {
            if (str_starts_with($name, 'Faction:')) {
                $factions[] = trim(substr($name, 8));
            }
        }

        return $factions;
    }

    /**
     * @return array{
     *     units: list<array{bsdataId: string, name: string, category: ?string, points: int, legends: bool, sourceFile: string, statsData: array}>,
     *     excluded: list<array{name: string, reason: string}>
     * }
     */
    public function extract(): array
    {
        $units = [];
        $seenIds = [];
        $seenNames = [];

        // 1. Candidats visibles en partie standard
        [$candidates, $excluded, $sameFactionFiles] = $this->candidates();

        // 2. Alliés : une unité importée d'un autre catalogue doit porter un mot-clé de faction de l'armée
        //    (ex. Agents of the Imperium, Imperial Knights, Chaos Knights, démons alliés, Unaligned Forces).
        //    Les unités liées explicitement par le catalogue principal sont gardées (ex. Harlequins des Drukhari),
        //    comme celles sans mot-clé de faction venant d'un catalogue de la même faction (ex. Drop Pod des chapitres).
        foreach ($candidates as ['link' => $link, 'entry' => $entry, 'rawName' => $rawName, 'file' => $file, 'factions' => $factions, 'matches' => $matches]) {
            $isMain = $file === $this->graph->mainFile();
            if (!$isMain && !$matches && !($factions === [] && isset($sameFactionFiles[$file]))) {
                $excluded[] = ['name' => $rawName, 'reason' => 'allié / autre faction' . ($factions ? ' (' . implode(', ', $factions) . ')' : '')];
                continue;
            }

            $id = (string) $entry['id'];
            $legends = str_contains($rawName, '[Legends]') || $this->eval->dependsOnToggle($link, $entry, 'Show Legends');
            $name = trim(str_replace('[Legends]', '', $rawName));
            $nameKey = mb_strtolower($name);
            if (isset($seenIds[$id]) || isset($seenNames[mb_strtolower($rawName)])) {
                continue; // doublon (même entrée importée deux fois, ou même nom)
            }
            if (isset($seenNames[$nameKey])) {
                // Homonyme Legends / non-Legends : on garde le suffixe pour distinguer
                $name = $rawName;
                $nameKey = mb_strtolower($name);
                if (isset($seenNames[$nameKey])) {
                    continue;
                }
            }
            $seenIds[$id] = true;
            $seenNames[$nameKey] = true;
            $seenNames[mb_strtolower($rawName)] = true;

            $statsData = $this->extractUnitDetails($link, $entry);
            $statsData['legends'] = $legends;

            $units[] = [
                'bsdataId' => $id,
                'name' => mb_substr($name, 0, 155),
                'category' => $this->pickCategory(array_merge($statsData['keywords'], array_map(fn($k) => 'Faction: ' . $k, $statsData['factionKeywords']))),
                'points' => $statsData['pointsOptions'][0]['points'] ?? 0,
                'legends' => $legends,
                'sourceFile' => $this->graph->sourceOf($id) ?? $file,
                'statsData' => $statsData,
            ];
        }

        return ['units' => $units, 'excluded' => $excluded];
    }

    /**
     * Détachements proposés : entrées de l'amélioration racine « Detachment » (catalogue principal ou importé).
     *
     * @return list<array{bsdataId: string, name: string}>
     */
    public function extractDetachments(): array
    {
        // Seuls les catalogues de la même faction (pas les détachements d'alliés, ex. Agents of the Imperium)
        $sameFactionFiles = $this->candidates()[2];
        $detachments = [];
        foreach ($this->graph->rootEntries() as ['node' => $node, 'file' => $file]) {
            if (!isset($sameFactionFiles[$file])) {
                continue;
            }
            [$link, $entry] = $this->resolveRoot($node);
            if ($entry === null || ($link['name'] ?? $entry['name'] ?? null) !== 'Detachment') {
                continue;
            }
            $this->collectDetachments($entry, $link, $detachments, [], 0);
        }

        $out = [];
        $names = [];
        foreach ($detachments as $id => $name) {
            if (!isset($names[$name])) {
                $names[$name] = true;
                $out[] = ['bsdataId' => $id, 'name' => mb_substr($name, 0, 155)];
            }
        }

        return $out;
    }

    /**
     * Explication d'exclusion d'une entrée racine (ou null si elle est jouable).
     */
    private function exclusionReason(?array $link, array $entry, string $name): ?string
    {
        foreach (self::EXCLUDED_NAME_PATTERNS as $pattern => $reason) {
            if (preg_match($pattern, $name)) {
                return $reason;
            }
        }
        foreach ($this->categoryNames($link, $entry) as $category) {
            if (isset(self::EXCLUDED_CATEGORIES[$category])) {
                return self::EXCLUDED_CATEGORIES[$category];
            }
        }
        if ($this->eval->isHidden($link, $entry, EvalContext::forNode($link, $entry, true))) {
            return 'masquée en partie standard' . $this->hiddenHint($link, $entry);
        }

        return null;
    }

    /** Précise la raison d'un masquage (bascule d'alliés, Boarding Actions, détachement…) pour l'audit. */
    private function hiddenHint(?array $link, array $entry): string
    {
        $json = json_encode([$link['modifiers'] ?? [], $link['modifierGroups'] ?? [], $entry['modifiers'] ?? [], $entry['modifierGroups'] ?? []]);
        if (!is_string($json) || !preg_match_all('/"childId":"([^"]+)"/', $json, $m)) {
            return '';
        }
        $names = [];
        foreach (array_unique($m[1]) as $childId) {
            $n = $this->graph->nameOf($childId);
            if ($n !== null && $n !== 'Show Legends') {
                $names[] = $n;
            }
        }

        return $names ? ' (' . implode(', ', array_slice($names, 0, 3)) . ')' : '';
    }

    /** @return array{0: ?array, 1: ?array} [lien, entrée ciblée] */
    private function resolveRoot(array $node): array
    {
        if (isset($node['targetId'])) {
            if (($node['type'] ?? null) !== 'selectionEntry') {
                return [$node, null];
            }

            return [$node, $this->graph->get($node['targetId'])];
        }

        return [null, $node];
    }

    // =====================================================================================
    // Détails d'une unité
    // =====================================================================================

    private function extractUnitDetails(?array $link, array $entry): array
    {
        $this->models = [];
        $this->roles = [];
        $this->abilities = [];
        $this->coreRules = [];
        $this->factionRules = [];
        $this->pendingWeapons = [];
        $this->transport = null;
        $this->minCountOf = [];
        $this->unitIds = array_values(array_filter([$link['id'] ?? null, $entry['id'] ?? null], 'is_string'));

        $this->walk($entry, $link, null, null, [$entry['id'] => true], 0, true);

        // Armes rencontrées hors de toute figurine (équipement au niveau de l'unité)
        if ($this->pendingWeapons && $this->models) {
            $attached = false;
            foreach ($this->models as $i => $model) {
                if ($model['weapons'] === []) {
                    $this->models[$i]['weapons'] = $this->pendingWeapons;
                    $attached = true;
                }
            }
            if (!$attached) {
                foreach ($this->pendingWeapons as $weapon) {
                    $this->addWeaponTo($this->models[0]['weapons'], $weapon);
                }
            }
        }

        $sortWeapons = function (array &$weapons): void {
            usort($weapons, fn($a, $b) => ($a['weaponType'] === 'Melee Weapons') <=> ($b['weaponType'] === 'Melee Weapons'));
        };
        foreach ($this->models as &$model) {
            $sortWeapons($model['weapons']);
        }
        unset($model);
        foreach ($this->roles as &$role) {
            $sortWeapons($role['weapons']);
        }
        unset($role);

        $abilities = array_values($this->abilities);
        [$keywords, $factionKeywords] = $this->keywords($link, $entry);
        $models = array_map(function (array $model) {
            unset($model['_base']);

            return $model;
        }, $this->models);

        return [
            'keywords' => $keywords,
            'factionKeywords' => $factionKeywords,
            'models' => $models,
            'roles' => $this->roles,
            'abilities' => $abilities,
            'coreAbilities' => array_values(array_unique($this->coreRules)),
            'factionAbilities' => array_values(array_unique($this->factionRules)),
            'invulnerableSave' => $this->invulnerableSave($abilities),
            'damaged' => $this->findAbility($abilities, '/^Damaged\b/i'),
            'leader' => $this->findAbility($abilities, '/^Leader$/i')['description'] ?? null,
            'transport' => $this->transport,
            'legends' => false,
            'pointsOptions' => $this->pointsOptions($link, $entry),
        ];
    }

    /**
     * Parcours récursif d'un nœud (entrée ou groupe) : figurines, armes, capacités, règles.
     * $visited protège contre les cycles sur le CHEMIN courant (passé par valeur) : une même cible
     * partagée peut être visitée depuis plusieurs branches.
     */
    private function walk(array $node, ?array $link, ?int $modelIdx, ?int $roleIdx, array $visited, int $depth, bool $isRoot = false): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }
        $name = (string) ($link['name'] ?? $node['name'] ?? '');
        if (!$isRoot) {
            if ($this->isSkippedBranch($name) || $this->eval->isHidden($link, $node, EvalContext::forNode($link, $node, false, $this->unitIds))) {
                return;
            }
        }

        [$profiles, $rules] = $this->collectInfo(array_filter([$link, $node]), $visited);

        // --- Figurine : profil « Unit » propre (ou lié), sinon rôle spécial / variante d'armement ---
        $unitProfiles = array_values(array_filter($profiles, fn($p) => ($p['typeName'] ?? null) === 'Unit'));
        if ($unitProfiles) {
            foreach ($unitProfiles as $profile) {
                $modelIdx = $this->addModel($profile);
            }
            $roleIdx = null;
        } elseif (($node['type'] ?? null) === 'model' && !$isRoot && $modelIdx !== null) {
            if (!$this->isDefaultRoleVariant($name, $this->models[$modelIdx]['name'])) {
                $roleIdx = $this->roleIndex($name);
            }
        }

        $hasWeapons = false;
        foreach ($profiles as $profile) {
            $typeName = $profile['typeName'] ?? null;
            $chars = $this->characteristics($profile);
            if (in_array($typeName, self::WEAPON_TYPES, true)) {
                $hasWeapons = true;
                $weapon = array_merge(['name' => (string) $profile['name'], 'weaponType' => $typeName], $chars);
                if ($roleIdx !== null) {
                    $this->addWeaponTo($this->roles[$roleIdx]['weapons'], $weapon);
                } elseif ($modelIdx !== null) {
                    $this->addWeaponTo($this->models[$modelIdx]['weapons'], $weapon);
                } else {
                    $this->addWeaponTo($this->pendingWeapons, $weapon);
                }
            } elseif ($typeName === 'Abilities' || $typeName === 'Psychic Abilities') {
                $description = $chars['Description'] ?? $chars['Descriptions'] ?? '';
                $this->addAbility($roleIdx, (string) $profile['name'], $description);
            } elseif ($typeName === 'Transport') {
                $this->transport ??= $this->cleanRuleText($chars['Capacity'] ?? '');
            }
        }

        // Règles référencées (Deep Strike, Oath of Moment…) : ignorées sur les armes (Rapid Fire, Blast…)
        if (!$hasWeapons) {
            foreach ($rules as $rule) {
                if ($rule['inline']) {
                    $this->addAbility($roleIdx, $rule['name'], $rule['description']);
                } elseif ($rule['core']) {
                    $this->coreRules[] = $rule['name'];
                } else {
                    $this->factionRules[] = $rule['name'];
                }
            }
        }

        foreach (array_filter([$link, $node]) as $part) {
            foreach ($part['selectionEntries'] ?? [] as $child) {
                $this->walk($child, null, $modelIdx, $roleIdx, $visited, $depth + 1);
            }
            foreach ($part['selectionEntryGroups'] ?? [] as $child) {
                $this->walk($child, null, $modelIdx, $roleIdx, $visited, $depth + 1);
            }
            foreach ($part['entryLinks'] ?? [] as $childLink) {
                $targetId = $childLink['targetId'] ?? null;
                $target = $this->graph->get($targetId);
                if ($target === null || isset($visited[$targetId])) {
                    continue;
                }
                $this->walk($target, $childLink, $modelIdx, $roleIdx, $visited + [$targetId => true], $depth + 1);
            }
        }
    }

    /**
     * Profils et règles d'un nœud : profils/règles embarqués, infoLinks (profil, règle, infoGroup) et infoGroups.
     *
     * @param list<array> $parts
     * @return array{0: list<array>, 1: list<array{name: string, description: string, core: bool, inline: bool}>}
     */
    private function collectInfo(array $parts, array $visited, int $depth = 0): array
    {
        $profiles = [];
        $rules = [];
        if ($depth > 8) {
            return [$profiles, $rules];
        }

        foreach ($parts as $part) {
            foreach ($part['profiles'] ?? [] as $profile) {
                if (!($profile['hidden'] ?? false)) {
                    $profiles[] = $profile;
                }
            }
            foreach ($part['rules'] ?? [] as $rule) {
                if (!($rule['hidden'] ?? false)) {
                    $rules[] = ['name' => (string) $rule['name'], 'description' => $this->cleanRuleText((string) ($rule['description'] ?? '')), 'core' => false, 'inline' => true];
                }
            }
            foreach ($part['infoLinks'] ?? [] as $infoLink) {
                $targetId = $infoLink['targetId'] ?? null;
                $target = $this->graph->get($targetId);
                if ($target === null || isset($visited['info:' . $targetId])) {
                    continue;
                }
                if ($this->eval->isHidden($infoLink, $target, EvalContext::forNode($infoLink, $target, false, $this->unitIds))) {
                    continue;
                }
                $ctx = EvalContext::forNode($infoLink, $target, false, $this->unitIds);
                $name = $this->eval->resolvedName($infoLink, (string) ($target['name'] ?? $infoLink['name'] ?? ''), $ctx);
                switch ($infoLink['type'] ?? null) {
                    case 'profile':
                        $profiles[] = ['name' => $name] + $target;
                        break;
                    case 'rule':
                        $rules[] = ['name' => $name, 'description' => $this->cleanRuleText((string) ($target['description'] ?? '')), 'core' => $this->graph->isGameSystemItem($targetId), 'inline' => false];
                        break;
                    case 'infoGroup':
                        [$p, $r] = $this->collectInfo([$target], $visited + ['info:' . $targetId => true], $depth + 1);
                        array_push($profiles, ...$p);
                        array_push($rules, ...$r);
                        break;
                }
            }
            foreach ($part['infoGroups'] ?? [] as $group) {
                if ($group['hidden'] ?? false) {
                    continue;
                }
                [$p, $r] = $this->collectInfo([$group], $visited, $depth + 1);
                array_push($profiles, ...$p);
                array_push($rules, ...$r);
            }
        }

        return [$profiles, $rules];
    }

    private function addModel(array $profile): int
    {
        $name = (string) ($profile['name'] ?? '');
        $stats = $this->characteristics($profile);
        $normalized = $stats;
        ksort($normalized);

        $sameName = 0;
        foreach ($this->models as $i => $model) {
            $existing = $model['stats'];
            ksort($existing);
            if ($model['_base'] === $name) {
                if ($existing === $normalized) {
                    return $i;
                }
                $sameName++;
            }
        }

        // Noms uniques (clés d'affichage côté JS)
        $this->models[] = [
            'name' => $sameName ? sprintf('%s (%d)', $name, $sameName + 1) : $name,
            'stats' => $stats,
            'weapons' => [],
            '_base' => $name,
        ];

        return count($this->models) - 1;
    }

    private function roleIndex(string $name): int
    {
        foreach ($this->roles as $i => $role) {
            if ($role['name'] === $name) {
                return $i;
            }
        }
        $this->roles[] = ['name' => $name, 'weapons' => [], 'abilities' => []];

        return count($this->roles) - 1;
    }

    private function addWeaponTo(array &$weapons, array $weapon): void
    {
        foreach ($weapons as $w) {
            if ($w['name'] === $weapon['name']) {
                return;
            }
        }
        $weapons[] = $weapon;
    }

    private function addAbility(?int $roleIdx, string $name, string $description): void
    {
        $ability = ['name' => $name, 'description' => $this->cleanRuleText($description)];
        if ($roleIdx !== null) {
            foreach ($this->roles[$roleIdx]['abilities'] as $a) {
                if ($a['name'] === $name) {
                    return;
                }
            }
            $this->roles[$roleIdx]['abilities'][] = $ability;

            return;
        }
        $this->abilities[$name] ??= $ability;
    }

    /** @return array<string, string> */
    private function characteristics(array $profile): array
    {
        $chars = [];
        foreach ($profile['characteristics'] ?? [] as $c) {
            $chars[(string) $c['name']] = trim((string) ($c['$text'] ?? ''));
        }

        return $chars;
    }

    private function isSkippedBranch(string $name): bool
    {
        foreach (self::SKIPPED_BRANCH_PATTERNS as $pattern) {
            if (preg_match($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    private function isDefaultRoleVariant(string $entryName, string $baseModelName): bool
    {
        $normalizedBase = rtrim($baseModelName, 's');

        return $normalizedBase === '' || str_starts_with($entryName, $normalizedBase);
    }

    private function cleanRuleText(string $text): string
    {
        // Retire les marqueurs de mise en forme BattleScribe (**gras**, ^^mots-clés^^)
        return trim(str_replace(['^^', '**'], '', $text));
    }

    /** @return list<string> */
    private function categoryNames(?array $link, array $entry): array
    {
        $names = [];
        foreach ([$entry, $link] as $node) {
            foreach ($node['categoryLinks'] ?? [] as $cat) {
                $name = $cat['name'] ?? $this->graph->nameOf((string) ($cat['targetId'] ?? ''));
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @return array{0: list<string>, 1: list<string>} [mots-clés, mots-clés de faction] */
    private function keywords(?array $link, array $entry): array
    {
        $keywords = [];
        $factions = [];
        foreach ($this->categoryNames($link, $entry) as $name) {
            if (str_starts_with($name, 'Faction:')) {
                $factions[] = trim(substr($name, 8));
            } elseif (!in_array($name, self::NON_KEYWORD_CATEGORIES, true) && !str_starts_with($name, 'Allies:')) {
                $keywords[] = $name;
            }
        }

        return [$keywords, $factions];
    }

    /**
     * Catégorie enregistrée = mot-clé déterminant selon UnitCategory::KEYWORD_PRIORITY
     * (liste unique partagée avec le regroupement des listes d'armée).
     *
     * @param list<string> $names
     */
    private function pickCategory(array $names): ?string
    {
        $primary = UnitCategory::primaryKeyword($names);
        if ($primary !== null) {
            return $primary;
        }
        foreach ($names as $name) {
            if (!str_starts_with($name, 'Faction:')) {
                return mb_substr($name, 0, 50);
            }
        }

        return null;
    }

    private function invulnerableSave(array $abilities): ?string
    {
        foreach ($this->models as $model) {
            $inv = $model['stats']['InSv'] ?? '';
            if ($inv !== '' && $inv !== '-') {
                return $inv;
            }
        }
        foreach ($abilities as $ability) {
            if (preg_match('/invulnerable save/i', $ability['name'])
                && preg_match('/(\d\+)/', $ability['name'] . ' ' . $ability['description'], $m)) {
                return $m[1];
            }
        }

        return null;
    }

    private function findAbility(array $abilities, string $pattern): ?array
    {
        foreach ($abilities as $ability) {
            if (preg_match($pattern, $ability['name'])) {
                return $ability;
            }
        }

        return null;
    }

    // =====================================================================================
    // Points et taille d'unité
    // =====================================================================================

    /**
     * Options de points : taille minimale (configuration par défaut, équipement par défaut compris, comme
     * l'affiche BattleScribe à l'ajout de l'unité), puis tailles supérieures quand le coût dépend du nombre
     * de figurines (modificateurs « set pts si plus de N figurines »).
     *
     * @return list<array{models: int, points: int}>
     */
    private function pointsOptions(?array $link, array $entry): array
    {
        $this->minCountOf = [];
        [$minModels, $defaultPts] = $this->minInfo($entry, $link, [$entry['id'] => true], 0);
        $minModels = max(1, $minModels);
        $maxModels = max($minModels, $this->maxInfo($entry, $link, [$entry['id'] => true], 0));

        $sizes = [$minModels];
        foreach (array_merge($this->eval->pointThresholds($entry), $link ? $this->eval->pointThresholds($link) : []) as $t) {
            if ($t['childId'] === 'model') {
                $size = $t['min'];
            } elseif (isset($this->minCountOf[$t['childId']])) {
                $size = $t['min'] + ($minModels - $this->minCountOf[$t['childId']]);
            } else {
                continue;
            }
            if ($size > $minModels && $size <= $maxModels) {
                $sizes[] = $size;
            }
        }
        $sizes = array_values(array_unique($sizes));
        sort($sizes);

        $points = [];
        foreach ($sizes as $size) {
            $counts = ['model' => $size];
            foreach ($this->minCountOf as $id => $count) {
                $counts[$id] = max(0, $size - ($minModels - $count));
            }
            $ctx = EvalContext::forNode($link, $entry, false, $this->unitIds, $counts);
            $points[$size] = (int) round($this->eval->points($link, $entry, $ctx) + $defaultPts);
        }

        // Tranches de coût identique, étiquetées par leur taille maximale (ex. 5 → 75 pts, 10 → 150 pts)
        $options = [];
        $sizesList = array_keys($points);
        foreach ($sizesList as $i => $size) {
            $upper = isset($sizesList[$i + 1]) ? $sizesList[$i + 1] - 1 : $maxModels;
            if ($options && end($options)['points'] === $points[$size]) {
                $options[count($options) - 1]['models'] = $upper;
                continue;
            }
            $options[] = ['models' => $upper, 'points' => $points[$size]];
        }
        if (count($options) === 1) {
            $options[0]['models'] = $minModels;
        }

        return $options;
    }

    /**
     * Configuration minimale par défaut : [figurines, pts des sélections par défaut sous ce nœud].
     * Renseigne $minCountOf (figurines minimales par identifiant) pour simuler les tailles supérieures.
     *
     * @return array{0: int, 1: float}
     */
    private function minInfo(array $node, ?array $link, array $visited, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [0, 0.0];
        }
        $models = ($node['type'] ?? null) === 'model' ? 1 : 0;
        $pts = 0.0;
        $children = $this->countableChildren($node, $link, $visited);

        $sumMin = 0;
        $infos = [];
        foreach ($children as $i => $child) {
            [$m, $p] = $this->minInfo($child['node'], $child['link'], $child['visited'], $depth + 1);
            $own = $child['isGroup'] ? 0.0 : $this->eval->points($child['link'], $child['node'], EvalContext::forNode($child['link'], $child['node'], false, $this->unitIds));
            $infos[$i] = [$m, $p + $own];
            $n = $child['isGroup'] ? 1 : $child['min'];
            $models += $n * $m;
            $pts += $n * ($p + $own);
            $sumMin += $child['isGroup'] ? 0 : $child['min'];
            $this->recordMinCount($child, $n * $m);
        }

        // Groupe avec minimum de sélections : complété avec l'entrée par défaut (ou la première)
        $groupMin = $this->constraint($link, $node, 'min');
        if (!isset($node['type']) && $groupMin > $sumMin && $children) {
            $defaultIdx = array_key_first($children);
            $defaultId = $node['defaultSelectionEntryId'] ?? $link['defaultSelectionEntryId'] ?? null;
            foreach ($children as $i => $child) {
                if ($defaultId !== null && in_array($defaultId, $child['ids'], true)) {
                    $defaultIdx = $i;
                    break;
                }
            }
            $extra = $groupMin - $sumMin;
            $models += $extra * $infos[$defaultIdx][0];
            $pts += $extra * $infos[$defaultIdx][1];
            $current = $this->minCountOf[$children[$defaultIdx]['ids'][0]] ?? 0;
            $this->recordMinCount($children[$defaultIdx], $current + $extra * $infos[$defaultIdx][0]);
        }

        return [$models, $pts];
    }

    /** Nombre maximal de figurines sous ce nœud (contraintes max, groupes « au plus N »). */
    private function maxInfo(array $node, ?array $link, array $visited, int $depth): int
    {
        if ($depth > self::MAX_DEPTH) {
            return 0;
        }
        $models = ($node['type'] ?? null) === 'model' ? 1 : 0;
        $options = [];
        foreach ($this->countableChildren($node, $link, $visited) as $child) {
            $per = $this->maxInfo($child['node'], $child['link'], $child['visited'], $depth + 1);
            if ($per === 0) {
                continue;
            }
            $max = $child['isGroup'] ? 1 : ($child['max'] > 0 ? $child['max'] : max(1, $child['min']));
            $options[] = [$per, $max, $child['isGroup']];
        }

        $groupMax = !isset($node['type']) ? $this->constraint($link, $node, 'max') : 0;
        if ($groupMax > 0) {
            // Les figurines d'un sous-groupe comptent comme autant de sélections du groupe parent
            usort($options, fn($a, $b) => $b[0] <=> $a[0]);
            $left = $groupMax;
            foreach ($options as [$per, $max, $isGroup]) {
                if ($isGroup) {
                    $take = min($left, $per);
                    $models += $take;
                    $left -= $take;
                } else {
                    $take = min($left, $max);
                    $models += $take * $per;
                    $left -= $take;
                }
                if ($left <= 0) {
                    break;
                }
            }
        } else {
            foreach ($options as [$per, $max]) {
                $models += $per * $max;
            }
        }

        return $models;
    }

    /** @return list<array{node: array, link: ?array, isGroup: bool, min: int, max: int, ids: list<string>, visited: array}> */
    private function countableChildren(array $node, ?array $link, array $visited): array
    {
        $children = [];
        $add = function (array $child, ?array $childLink, bool $isGroup) use (&$children, $visited) {
            $name = (string) ($childLink['name'] ?? $child['name'] ?? '');
            if ($this->isSkippedBranch($name)
                || $this->eval->isHidden($childLink, $child, EvalContext::forNode($childLink, $child, false, $this->unitIds))) {
                return;
            }
            $children[] = [
                'node' => $child,
                'link' => $childLink,
                'isGroup' => $isGroup,
                'min' => $isGroup ? 0 : max(0, $this->constraint($childLink, $child, 'min')),
                'max' => $isGroup ? 0 : $this->constraint($childLink, $child, 'max'),
                'ids' => array_values(array_filter([$childLink['id'] ?? null, $child['id'] ?? null], 'is_string')),
                'visited' => $visited + [$child['id'] ?? '' => true],
            ];
        };

        foreach (array_filter([$link, $node]) as $part) {
            foreach ($part['selectionEntries'] ?? [] as $child) {
                $add($child, null, false);
            }
            foreach ($part['selectionEntryGroups'] ?? [] as $child) {
                $add($child, null, true);
            }
            foreach ($part['entryLinks'] ?? [] as $childLink) {
                $targetId = $childLink['targetId'] ?? null;
                $target = $this->graph->get($targetId);
                if ($target !== null && !isset($visited[$targetId])) {
                    $add($target, $childLink, ($childLink['type'] ?? null) === 'selectionEntryGroup');
                }
            }
        }

        return $children;
    }

    private function recordMinCount(array $child, int $count): void
    {
        foreach ($child['ids'] as $id) {
            $this->minCountOf[$id] = $count;
        }
    }

    /**
     * Valeur d'une contrainte de sélections (min/max) portée par le lien ou le nœud, portée « parent ».
     * Renvoie 0 si absente (-1 = illimité est conservé).
     */
    private function constraint(?array $link, array $node, string $type): int
    {
        foreach (array_filter([$link, $node]) as $part) {
            foreach ($part['constraints'] ?? [] as $c) {
                if (($c['type'] ?? null) === $type && ($c['field'] ?? null) === 'selections'
                    && in_array($c['scope'] ?? 'parent', ['parent'], true)) {
                    return (int) ($c['value'] ?? 0);
                }
            }
        }

        return 0;
    }

    /**
     * Enhancements of every detachment. Two layouts exist in BSData:
     *  - "<Detachment> Enhancements" groups, shared or nested in a shared "Enhancements…" group;
     *  - a shared "Enhancements…" group lists every enhancement, each hidden unless its detachment is selected.
     * The detachment is given by name in the first case, by BSData id (detachmentIds) in the second.
     *
     * @return list<array{bsdataId: string, name: string, detachment: ?string, detachmentIds: list<string>, points: int, description: ?string}>
     */
    public function extractEnhancements(): array
    {
        $enhancements = [];
        // Root groups: "Enhancements", "Enhancements - Upgrades", "Enhancement Upgrades"… and "<Detachment> Enhancements"
        foreach ($this->graph->sharedGroups() as ['node' => $root]) {
            $name = (string) ($root['name'] ?? '');
            if (str_ends_with($name, ' Enhancements')) {
                $this->collectEnhancements($root, trim(substr($name, 0, -strlen(' Enhancements'))), $enhancements, [$root['id'] ?? '' => true]);
            } elseif (str_starts_with($name, 'Enhancement')) {
                $this->collectEnhancements($root, null, $enhancements, [$root['id'] ?? '' => true]);
            }
        }
        return array_values($enhancements);
    }

    /**
     * @param array<string, array> $enhancements unique key => enhancement (filled)
     * @param array<string, true> $visited group ids already explored
     */
    private function collectEnhancements(array $group, ?string $detachment, array &$enhancements, array $visited): void
    {
        $entries = $group['selectionEntries'] ?? [];
        $children = $group['selectionEntryGroups'] ?? [];
        foreach ($group['entryLinks'] ?? [] as $link) {
            $target = $this->graph->get($link['targetId'] ?? null);
            if ($target === null) {
                continue;
            }
            $linked = ['name' => $link['name'] ?? $target['name'] ?? '', 'modifiers' => array_merge($link['modifiers'] ?? [], $target['modifiers'] ?? [])] + $target;
            match ($link['type'] ?? null) {
                'selectionEntryGroup' => $children[] = $linked,
                'selectionEntry' => $entries[] = $linked,
                default => null,
            };
        }
        foreach ($entries as $entry) {
            if (!isset($entry['id'], $entry['name']) || ($entry['type'] ?? 'upgrade') !== 'upgrade') {
                continue;
            }
            $detachmentIds = $detachment === null ? $this->detachmentConditionIds($entry) : [];
            if ($detachment === null && $detachmentIds === []) {
                continue;
            }
            $enhancements[($detachment ?? implode(',', $detachmentIds)) . '|' . $entry['id']] = [
                'bsdataId' => (string) $entry['id'],
                'name' => (string) $entry['name'],
                'detachment' => $detachment,
                'detachmentIds' => $detachmentIds,
                'points' => $this->enhancementPoints($entry),
                'description' => $this->enhancementDescription($entry),
            ];
        }
        foreach ($children as $child) {
            $childId = (string) ($child['id'] ?? '');
            if ($childId === '' || isset($visited[$childId])) {
                continue;
            }
            $name = (string) ($child['name'] ?? '');
            $childDetachment = str_ends_with($name, ' Enhancements') ? trim(substr($name, 0, -strlen(' Enhancements'))) : $detachment;
            $this->collectEnhancements($child, $childDetachment, $enhancements, $visited + [$childId => true]);
        }
    }

    /**
     * Ids of the selections an entry depends on: "hidden" modifiers whose condition is
     * "fewer than 1" or "exactly 0" selection of <id> (the detachment); the caller keeps the ids of known detachments.
     *
     * @return list<string>
     */
    private function detachmentConditionIds(array $entry): array
    {
        $ids = [];
        foreach ($entry['modifiers'] ?? [] as $modifier) {
            if (($modifier['field'] ?? null) !== 'hidden' || ($modifier['value'] ?? null) !== true) {
                continue;
            }
            foreach ($this->flattenConditions($modifier) as $condition) {
                $type = $condition['type'] ?? null;
                $value = (int) ($condition['value'] ?? -1);
                $missingSelection = ($type === 'lessThan' && $value === 1) || ($type === 'equalTo' && $value === 0);
                if (($condition['field'] ?? null) === 'selections' && $missingSelection && isset($condition['childId'])) {
                    $ids[] = (string) $condition['childId'];
                }
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Conditions of a modifier, nested condition groups included.
     *
     * @return list<array>
     */
    private function flattenConditions(array $node): array
    {
        $conditions = $node['conditions'] ?? [];
        foreach ($node['conditionGroups'] ?? [] as $conditionGroup) {
            $conditions = array_merge($conditions, $this->flattenConditions($conditionGroup));
        }
        return $conditions;
    }

    private function enhancementPoints(array $entry): int
    {
        foreach ($entry['costs'] ?? [] as $cost) {
            if (($cost['name'] ?? null) === 'pts') {
                return (int) ($cost['value'] ?? 0);
            }
        }
        return 0;
    }

    /** Text of the "Abilities" profile, without the BattleScribe markup (**bold**, ^^keyword^^). */
    private function enhancementDescription(array $entry): ?string
    {
        foreach ($entry['profiles'] ?? [] as $profile) {
            foreach ($profile['characteristics'] ?? [] as $characteristic) {
                $text = trim(str_replace(['**', '^^', "\u{00a0}"], ['', '', ' '], (string) ($characteristic['$text'] ?? '')));
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string, string> $detachments id => nom (rempli)
     */
    private function collectDetachments(array $node, ?array $link, array &$detachments, array $visited, int $depth): void
    {
        if ($depth > 6) {
            return;
        }
        foreach (array_filter([$link, $node]) as $part) {
            $groups = $part['selectionEntryGroups'] ?? [];
            foreach ($part['entryLinks'] ?? [] as $l) {
                $target = $this->graph->get($l['targetId'] ?? null);
                if ($target === null || isset($visited[$target['id']])) {
                    continue;
                }
                if (($l['type'] ?? null) === 'selectionEntryGroup') {
                    $groups[] = ['_link' => $l] + $target;
                } elseif ($depth > 0 && !$this->eval->isHidden($l, $target, EvalContext::forNode($l, $target, true))) {
                    $detachments[$target['id']] ??= (string) ($l['name'] ?? $target['name']);
                }
            }
            if ($depth > 0) {
                foreach ($part['selectionEntries'] ?? [] as $entry) {
                    if (!$this->eval->isHidden(null, $entry, EvalContext::forNode(null, $entry, true))) {
                        $detachments[$entry['id']] ??= (string) $entry['name'];
                    }
                }
            }
            foreach ($groups as $group) {
                $groupLink = $group['_link'] ?? null;
                unset($group['_link']);
                if ($this->eval->isHidden($groupLink, $group, EvalContext::forNode($groupLink, $group, true))) {
                    continue;
                }
                $this->collectDetachments($group, null, $detachments, $visited + [$group['id'] => true], $depth + 1);
            }
        }
    }
}
