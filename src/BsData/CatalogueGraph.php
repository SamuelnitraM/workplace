<?php

namespace App\BsData;

/**
 * Graphe des catalogues BSData nécessaires à UNE faction : son catalogue principal, tous les catalogues
 * liés transitivement par `catalogueLinks` (bibliothèques, catalogues importés) et le système de jeu.
 *
 * Index global des identifiants (entrées, groupes, profils, règles, infoGroups, catégories…), avec une
 * précédence explicite : catalogue principal > catalogues liés (ordre de découverte, en largeur) > système de jeu.
 * Le premier catalogue qui déclare un identifiant l'emporte.
 */
final class CatalogueGraph
{
    /** Clés de premier niveau d'un catalogue dont les éléments peuvent être ciblés par un lien. */
    private const INDEXED_KEYS = [
        'sharedSelectionEntries', 'sharedSelectionEntryGroups', 'sharedProfiles', 'sharedRules', 'sharedInfoGroups',
        'categoryEntries', 'selectionEntries', 'rules', 'profiles', 'forceEntries',
    ];

    /** @var array<string, array> id => élément */
    private array $index = [];

    /** @var array<string, string> id => fichier source */
    private array $sourceOf = [];

    /** @var array<string, string> id de catalogue => fichier */
    private array $catalogueFiles = [];

    /**
     * @param list<array{file: string, data: array}> $catalogues catalogue principal en premier, système de jeu en dernier
     * @param list<string> $rootImportFiles fichiers (hors principal) dont les entrées racines sont importées
     */
    public function __construct(
        private readonly string $mainFile,
        private readonly array $catalogues,
        private readonly string $gameSystemFile,
        private readonly array $rootImportFiles,
    ) {
        foreach ($this->catalogues as ['file' => $file, 'data' => $data]) {
            $this->catalogueFiles[$data['id']] = $file;
            foreach (self::INDEXED_KEYS as $key) {
                foreach ($data[$key] ?? [] as $item) {
                    $id = $item['id'] ?? null;
                    if (is_string($id) && !isset($this->index[$id])) {
                        $this->index[$id] = $item;
                        $this->sourceOf[$id] = $file;
                    }
                    // Sous-forces (ex. « Crusade Army » dans « Crusade Force »)
                    if ($key === 'forceEntries') {
                        foreach ($item['forceEntries'] ?? [] as $sub) {
                            if (isset($sub['id']) && !isset($this->index[$sub['id']])) {
                                $this->index[$sub['id']] = $sub;
                                $this->sourceOf[$sub['id']] = $file;
                            }
                        }
                    }
                }
            }
        }
    }

    public function get(?string $id): ?array
    {
        if ($id === null) {
            return null;
        }
        if (isset($this->index[$id])) {
            return $this->index[$id];
        }

        // Certains liens ciblent une entrée imbriquée (définie dans une autre unité) : index profond, construit à la demande
        if ($this->deepIndex === null) {
            $this->deepIndex = [];
            foreach ($this->catalogues as ['file' => $file, 'data' => $data]) {
                $this->indexDeep($data, $file);
            }
        }

        return $this->deepIndex[$id] ?? null;
    }

    /** @var array<string, array>|null */
    private ?array $deepIndex = null;

    private function indexDeep(array $node, string $file): void
    {
        foreach ($node as $key => $value) {
            if (!is_array($value) || in_array($key, ['modifiers', 'modifierGroups', 'conditions', 'conditionGroups', 'constraints', 'costs', 'characteristics', 'repeats'], true)) {
                continue;
            }
            if (isset($value['id'], $value['name']) && !isset($value['targetId']) && !isset($this->deepIndex[$value['id']])) {
                $this->deepIndex[$value['id']] = $value;
                $this->sourceOf[$value['id']] ??= $file;
            }
            $this->indexDeep($value, $file);
        }
    }

    public function sourceOf(string $id): ?string
    {
        return $this->sourceOf[$id] ?? null;
    }

    public function isGameSystemItem(string $id): bool
    {
        return ($this->sourceOf[$id] ?? null) === $this->gameSystemFile;
    }

    public function isCatalogueId(string $id): bool
    {
        return isset($this->catalogueFiles[$id]);
    }

    public function mainFile(): string
    {
        return $this->mainFile;
    }

    public function main(): array
    {
        return $this->catalogues[0]['data'];
    }

    public function primaryCatalogueId(): string
    {
        return $this->main()['id'];
    }

    public function gameSystem(): array
    {
        foreach ($this->catalogues as ['file' => $file, 'data' => $data]) {
            if ($file === $this->gameSystemFile) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Every shared selection entry group of the graph (main catalogue first).
     *
     * @return list<array{node: array, file: string}>
     */
    public function sharedGroups(): array
    {
        $groups = [];
        foreach ($this->catalogues as ['file' => $file, 'data' => $data]) {
            foreach ($data['sharedSelectionEntryGroups'] ?? [] as $group) {
                $groups[] = ['node' => $group, 'file' => $file];
            }
        }
        return $groups;
    }

    /** @return list<string> tous les fichiers impliqués (principal, liés, système de jeu) */
    public function files(): array
    {
        return array_column($this->catalogues, 'file');
    }

    /**
     * Entrées racines proposées au joueur : celles du catalogue principal, puis celles des catalogues liés
     * avec importRootEntries=true (transitivement).
     *
     * @return list<array{node: array, file: string}>
     */
    public function rootEntries(): array
    {
        $files = array_merge([$this->mainFile], $this->rootImportFiles);
        $roots = [];
        foreach ($this->catalogues as ['file' => $file, 'data' => $data]) {
            if (!in_array($file, $files, true)) {
                continue;
            }
            foreach (['entryLinks', 'selectionEntries'] as $key) {
                foreach ($data[$key] ?? [] as $node) {
                    $roots[] = ['node' => $node, 'file' => $file];
                }
            }
        }

        // Ordre : le catalogue principal d'abord (précédence en cas de doublon de nom)
        usort($roots, fn(array $a, array $b) => array_search($a['file'], $files, true) <=> array_search($b['file'], $files, true));

        return $roots;
    }

    /**
     * Bascules d'affichage BattleScribe (« Show Legends », « Show Khorne Daemons »…) : améliorations
     * partagées dont le nom commence par « Show ».
     *
     * @return array<string, string> id => nom
     */
    public function toggles(): array
    {
        $toggles = [];
        foreach ($this->index as $id => $item) {
            $name = (string) ($item['name'] ?? '');
            if (($item['type'] ?? null) === 'upgrade' && str_starts_with($name, 'Show ') && $name !== 'Show/Hide Options') {
                $toggles[$id] = $name;
            }
        }

        return $toggles;
    }

    /** Nom lisible d'un identifiant (catégorie, entrée…), ou null. */
    public function nameOf(string $id): ?string
    {
        if (isset($this->catalogueFiles[$id])) {
            return $this->catalogueFiles[$id];
        }

        return $this->index[$id]['name'] ?? null;
    }
}
