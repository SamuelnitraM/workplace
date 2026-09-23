<?php

namespace App\Service;

use App\Army\UnitCategory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class BsDataFetcher
{
    private const REPO = 'BSData/wh40k-11e';
    private const BRANCH = 'main';

    /**
     * Factions proposées dans le <select faction> du formulaire => nom du fichier JSON
     * dans BSData/wh40k-11e. Sert aussi de liste blanche côté serveur.
     */
    public const FACTION_FILES = [
        'Space Marines' => 'Imperium - Space Marines.json',
        'Blood Angels' => 'Imperium - Blood Angels.json',
        'Dark Angels' => 'Imperium - Dark Angels.json',
        'Space Wolves' => 'Imperium - Space Wolves.json',
        'Grey Knights' => 'Imperium - Grey Knights.json',
        'Deathwatch' => 'Imperium - Deathwatch.json',
        'Adeptus Custodes' => 'Imperium - Adeptus Custodes.json',
        'Sisters of Battle' => 'Imperium - Adepta Sororitas.json',
        'Astra Militarum' => 'Imperium - Astra Militarum.json',
        'Adeptus Mechanicus' => 'Imperium - Adeptus Mechanicus.json',
        'Imperial Knights' => 'Imperium - Imperial Knights.json',
        'Chaos Space Marines' => 'Chaos - Chaos Space Marines.json',
        'Death Guard' => 'Chaos - Death Guard.json',
        'Thousand Sons' => 'Chaos - Thousand Sons.json',
        'World Eaters' => 'Chaos - World Eaters.json',
        "Emperor's Children" => "Chaos - Emperor's Children.json",
        'Chaos Knights' => 'Chaos - Chaos Knights.json',
        'Daemons' => 'Chaos - Chaos Daemons.json',
        'Orks' => 'Orks.json',
        'Eldar' => 'Aeldari - Craftworlds.json',
        'Drukhari' => 'Aeldari - Drukhari.json',
        'Tyranids' => 'Tyranids.json',
        'Genestealer Cults' => 'Genestealer Cults.json',
        'Tau' => "T'au Empire.json",
        'Necrons' => 'Necrons.json',
        'Leagues of Votann' => 'Leagues of Votann.json',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Récupère le SHA du dernier commit ayant modifié ce fichier, via l'API GitHub.
     * Ne télécharge pas le contenu — juste les métadonnées, pour rester léger.
     */
    public function getLatestCommitSha(string $sourceFile): ?string
    {
        $response = $this->httpClient->request('GET', sprintf(
            'https://api.github.com/repos/%s/commits',
            self::REPO
        ), [
            'query' => [
                'path' => $sourceFile,
                'sha' => self::BRANCH,
                'per_page' => 1,
            ],
            'headers' => [
                'User-Agent' => 'SprueHub-ArmyBuilder',
                'Accept' => 'application/vnd.github+json',
            ],
        ]);

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $data = $response->toArray(false);

        return $data[0]['sha'] ?? null;
    }

    /**
     * Télécharge et décode le catalogue JSON brut d'une faction.
     */
    public function fetchFactionCatalogue(string $sourceFile): array
    {
        $response = $this->httpClient->request('GET', sprintf(
            'https://raw.githubusercontent.com/%s/%s/%s',
            self::REPO,
            self::BRANCH,
            rawurlencode($sourceFile)
        ), [
            'headers' => ['User-Agent' => 'SprueHub-ArmyBuilder'],
        ]);

        $status = $response->getStatusCode();
        if ($status !== 200) {
            throw new \RuntimeException(sprintf('Téléchargement de "%s" impossible (HTTP %d).', $sourceFile, $status));
        }

        return $response->toArray(false);
    }

    /**
     * Extrait les unités réellement sélectionnables via les entryLinks de premier niveau
     * (c'est la liste que BattleScribe présente au joueur), pas via sharedSelectionEntries
     * directement — sinon on rate les persos nommés et véhicules solo (type "model").
     *
     * @return array<int, array{bsdataId: string, name: string, category: ?string, points: int}>
     */
    public function extractUnits(array $catalogueJson): array
    {
        $cat = $catalogueJson['catalogue'] ?? [];
        $entriesById = [];
        foreach ($cat['sharedSelectionEntries'] ?? [] as $entry) {
            $entriesById[$entry['id']] = $entry;
        }

        $units = [];
        foreach ($cat['entryLinks'] ?? [] as $link) {
            if (($link['type'] ?? null) !== 'selectionEntry' || ($link['hidden'] ?? false)) {
                continue;
            }

            $target = $entriesById[$link['targetId'] ?? null] ?? null;
            if (!$target || !in_array($target['type'] ?? null, ['unit', 'model'], true)) {
                continue;
            }

            // Unités du sous-système narratif "Crucible of Battle" — hors périmètre pour l'instant
            if (str_contains($link['name'], '[Crucible]')) {
                continue;
            }

            $points = 0;
            foreach ($target['costs'] ?? [] as $cost) {
                if (($cost['name'] ?? null) === 'pts') {
                    $points = (int) $cost['value'];
                    break;
                }
            }

            $category = $this->pickCategory($target['categoryLinks'] ?? []);

            $units[] = [
                'bsdataId' => $target['id'],
                'name' => $link['name'],
                'category' => $category,
                'points' => $points,
                'statsData' => $this->extractUnitDetails($target, $catalogueJson),
            ];
        }

        return $units;
    }

    /**
     * Extrait la liste des détachements disponibles pour une faction.
     *
     * @return array<int, array{bsdataId: string, name: string}>
     */
    public function extractDetachments(array $catalogueJson): array
    {
        $cat = $catalogueJson['catalogue'] ?? [];
        $entriesById = [];
        foreach ($cat['sharedSelectionEntries'] ?? [] as $entry) {
            $entriesById[$entry['id']] = $entry;
        }

        // Le lien "Detachment" pointe vers une entrée "upgrade" qui contient
        // un unique selectionEntryGroup listant les détachements disponibles.
        $detachmentLink = null;
        foreach ($cat['entryLinks'] ?? [] as $link) {
            if (($link['name'] ?? null) === 'Detachment') {
                $detachmentLink = $link;
                break;
            }
        }

        if (!$detachmentLink) {
            return [];
        }

        $target = $entriesById[$detachmentLink['targetId']] ?? null;
        if (!$target) {
            return [];
        }

        $detachments = [];
        foreach ($target['selectionEntryGroups'] ?? [] as $group) {
            foreach ($group['selectionEntries'] ?? [] as $entry) {
                $detachments[] = [
                    'bsdataId' => $entry['id'],
                    'name' => $entry['name'],
                ];
            }
        }

        return $detachments;
    }

    /**
     * Parcourt récursivement l'arbre d'une unité pour en extraire stats, armes et capacités.
     * Suit aussi les entryLinks (armes référencées plutôt qu'embarquées directement)
     * en résolvant vers sharedSelectionEntries ou sharedSelectionEntryGroups.
     */
    private const EXCLUDED_LINK_NAMES = ['Crusade', 'Enhancements'];

    /**
     * Catégorie enregistrée d'une unité = mot-clé déterminant selon UnitCategory::KEYWORD_PRIORITY
     * (liste unique, partagée avec le regroupement des listes d'armée) : le flag `primary` du JSON
     * peut désigner un rôle tactique plutôt que le type physique de l'unité.
     */
    private function pickCategory(array $categoryLinks): ?string
    {
        $names = array_values(array_filter(array_column($categoryLinks, 'name'), 'is_string'));

        $primary = UnitCategory::primaryKeyword($names);
        if ($primary !== null) {
            return $primary;
        }

        // Repli : première catégorie non liée à la faction, si aucune priorité connue ne matche
        foreach ($names as $name) {
            if (!str_starts_with($name, 'Faction:')) {
                return $name;
            }
        }

        return null;
    }

    private function extractKeywords(array $categoryLinks): array
    {
        $keywords = [];
        foreach ($categoryLinks as $catLink) {
            $name = $catLink['name'] ?? '';
            if (!str_starts_with($name, 'Faction:')) {
                $keywords[] = $name;
            }
        }
        return $keywords;
    }

    private function cleanRuleText(string $text): string
    {
        // Retire les marqueurs de mise en forme BattleScribe (**gras**, ^^mots-clés^^)
        $text = str_replace(['^^', '**'], '', $text);
        return trim($text);
    }

    private function isDefaultRoleVariant(string $entryName, string $baseModelName): bool
    {
        $normalizedBase = rtrim($baseModelName, 's');
        return str_starts_with($entryName, $normalizedBase);
    }

    private function walkNode(
        array $node,
        ?int $currentModelIndex,
        ?int $currentRoleIndex,
        array &$models,
        array &$abilities,
        array &$roles,
        array &$seenAbilities,
        array &$seenRoleAbilities,
        array $visited,
        array $entriesById,
        array $groupsById,
        array $profilesById
    ): void {
        // Détection d'un rôle spécial : entrée "model" sans profil de stats propre,
        // dont le nom n'est pas une simple variante d'armement du modèle de base.
        if (($node['type'] ?? null) === 'model' && $currentModelIndex !== null) {
            $hasOwnStats = false;
            foreach ($node['profiles'] ?? [] as $p) {
                if (($p['typeName'] ?? null) === 'Unit') {
                    $hasOwnStats = true;
                    break;
                }
            }
            if (!$hasOwnStats) {
                $entryName = $node['name'] ?? '';
                $baseName = $models[$currentModelIndex]['name'];
                if ($entryName && !$this->isDefaultRoleVariant($entryName, $baseName)) {
                    $roles[] = ['name' => $entryName, 'weapons' => [], 'abilities' => []];
                    $currentRoleIndex = count($roles) - 1;
                    $seenRoleAbilities[$currentRoleIndex] = [];
                }
            }
        }

        foreach ($node['profiles'] ?? [] as $profile) {
            $typeName = $profile['typeName'] ?? null;
            $chars = [];
            foreach ($profile['characteristics'] ?? [] as $c) {
                $chars[$c['name']] = $c['$text'] ?? '';
            }

            if ($typeName === 'Unit') {
                $models[] = ['name' => $profile['name'], 'stats' => $chars, 'weapons' => []];
                $currentModelIndex = count($models) - 1;
            } elseif ($typeName === 'Abilities') {
                $name = $profile['name'];
                if ($currentRoleIndex !== null) {
                    if (!isset($seenRoleAbilities[$currentRoleIndex][$name])) {
                        $seenRoleAbilities[$currentRoleIndex][$name] = true;
                        $roles[$currentRoleIndex]['abilities'][] = [
                            'name' => $name,
                            'description' => $this->cleanRuleText($chars['Description'] ?? ''), // ← modifié
                        ];
                    }
                } elseif (!isset($seenAbilities[$name])) {
                    $seenAbilities[$name] = true;
                    $abilities[] = [
                        'name' => $name,
                        'description' => $this->cleanRuleText($chars['Description'] ?? ''), // ← modifié
                    ];
                }
            } elseif (in_array($typeName, ['Ranged Weapons', 'Melee Weapons'], true)) {
                $weaponName = $profile['name'];
                $weaponData = array_merge(['name' => $weaponName, 'weaponType' => $typeName], $chars);

                if ($currentRoleIndex !== null) {
                    $exists = false;
                    foreach ($roles[$currentRoleIndex]['weapons'] as $w) {
                        if ($w['name'] === $weaponName) { $exists = true; break; }
                    }
                    if (!$exists) {
                        $roles[$currentRoleIndex]['weapons'][] = $weaponData;
                    }
                } elseif ($currentModelIndex !== null) {
                    $exists = false;
                    foreach ($models[$currentModelIndex]['weapons'] as $w) {
                        if ($w['name'] === $weaponName) { $exists = true; break; }
                    }
                    if (!$exists) {
                        $models[$currentModelIndex]['weapons'][] = $weaponData;
                    }
                }
            }
        }

        foreach ($node['infoLinks'] ?? [] as $il) {
            if (($il['type'] ?? null) === 'profile' && isset($profilesById[$il['targetId']])) {
                $this->walkNode(
                    ['profiles' => [$profilesById[$il['targetId']]]],
                    $currentModelIndex, $currentRoleIndex, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $visited,
                    $entriesById, $groupsById, $profilesById
                );
            }
        }

        foreach ($node['selectionEntries'] ?? [] as $child) {
            $this->walkNode($child, $currentModelIndex, $currentRoleIndex, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $visited, $entriesById, $groupsById, $profilesById);
        }

        foreach ($node['selectionEntryGroups'] ?? [] as $group) {
            $this->walkNode($group, $currentModelIndex, $currentRoleIndex, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $visited, $entriesById, $groupsById, $profilesById);
        }

        foreach ($node['entryLinks'] ?? [] as $link) {
            if (in_array($link['name'] ?? null, self::EXCLUDED_LINK_NAMES, true)) {
                continue;
            }

            // $visited est passé par valeur : il ne protège que contre les cycles sur le
            // chemin courant. Une même cible partagée (ex : arme commune à plusieurs
            // variantes d'armement) doit pouvoir être visitée depuis plusieurs branches.
            $targetId = $link['targetId'] ?? null;
            if ($targetId && !isset($visited[$targetId])) {
                $pathVisited = $visited;
                $pathVisited[$targetId] = true;

                if (($link['type'] ?? null) === 'selectionEntry' && isset($entriesById[$targetId])) {
                    $this->walkNode($entriesById[$targetId], $currentModelIndex, $currentRoleIndex, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $pathVisited, $entriesById, $groupsById, $profilesById);
                } elseif (($link['type'] ?? null) === 'selectionEntryGroup' && isset($groupsById[$targetId])) {
                    $this->walkNode($groupsById[$targetId], $currentModelIndex, $currentRoleIndex, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $pathVisited, $entriesById, $groupsById, $profilesById);
                }
            }

            $this->walkNode($link, $currentModelIndex, $currentRoleIndex, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $visited, $entriesById, $groupsById, $profilesById);
        }
    }

    /**
     * @return array{keywords: array, models: array, abilities: array, roles: array}
     */
    public function extractUnitDetails(array $target, array $catalogueJson): array
    {
        
        $cat = $catalogueJson['catalogue'] ?? [];

        $entriesById = [];
        foreach ($cat['sharedSelectionEntries'] ?? [] as $e) {
            $entriesById[$e['id']] = $e;
        }
        $groupsById = [];
        foreach ($cat['sharedSelectionEntryGroups'] ?? [] as $g) {
            $groupsById[$g['id']] = $g;
        }
        $profilesById = [];
        foreach ($cat['sharedProfiles'] ?? [] as $p) {
            $profilesById[$p['id']] = $p;
        }

        $models = [];
        $abilities = [];
        $roles = [];
        $seenAbilities = [];
        $seenRoleAbilities = [];
        $visited = [];

        $this->walkNode($target, null, null, $models, $abilities, $roles, $seenAbilities, $seenRoleAbilities, $visited, $entriesById, $groupsById, $profilesById);

        $sortWeapons = fn(array &$weapons) => usort($weapons, fn($a, $b) =>
            ($a['weaponType'] === 'Melee Weapons' ? 1 : 0) <=> ($b['weaponType'] === 'Melee Weapons' ? 1 : 0)
        );

        // Fusionne les "modèles" qui ont en réalité le même nom et les mêmes stats
        // (cas où le fichier BSData redéclare un profil identique pour chaque
        // variante d'armement au lieu de le factoriser — ex: Cthonian Beserks).
        $normalizeStats = function (array $stats): array {
            ksort($stats);
            return $stats;
        };

        $mergedModels = [];
        foreach ($models as $model) {
            $matchIndex = null;
            foreach ($mergedModels as $idx => $existing) {
                if ($existing['name'] === $model['name']
                    && $normalizeStats($existing['stats']) === $normalizeStats($model['stats'])
                ) {
                    $matchIndex = $idx;
                    break;
                }
            }

            if ($matchIndex === null) {
                $mergedModels[] = $model;
            } else {
                foreach ($model['weapons'] as $weapon) {
                    $exists = false;
                    foreach ($mergedModels[$matchIndex]['weapons'] as $w) {
                        if ($w['name'] === $weapon['name']) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $mergedModels[$matchIndex]['weapons'][] = $weapon;
                    }
                }
            }
        }
        $models = $mergedModels;

        foreach ($models as &$model) {
            $sortWeapons($model['weapons']);
        }
        unset($model);

        foreach ($roles as &$role) {
            $sortWeapons($role['weapons']);
        }
        unset($role);

        return [
            'keywords' => $this->extractKeywords($target['categoryLinks'] ?? []),
            'models' => $models,
            'abilities' => $abilities,
            'roles' => $roles,
        ];
    }
}