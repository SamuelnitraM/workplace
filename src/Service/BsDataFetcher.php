<?php

namespace App\Service;

use App\BsData\CatalogueGraph;
use App\BsData\UnitExtractor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Accès aux données BSData (dépôt GitHub BSData/wh40k-11e, catalogues JSON) :
 *  - liste des factions jouables (SOURCE DE VÉRITÉ UNIQUE : liste blanche serveur + menu déroulant) ;
 *  - arbre du dépôt en UN appel API (SHA de blob de tous les fichiers : détection de changements sans
 *    dépasser la limite de 60 requêtes/h de l'API GitHub non authentifiée) ;
 *  - téléchargement des fichiers bruts (raw.githubusercontent.com, hors quota API), avec cache disque
 *    adressé par SHA de blob (un fichier inchangé n'est jamais retéléchargé) et cache mémoire par exécution
 *    (les bibliothèques partagées par plusieurs factions ne sont décodées qu'une fois par fichier) ;
 *  - construction du graphe de catalogues d'une faction (catalogue principal + catalogues liés + système de jeu).
 */
class BsDataFetcher
{
    private const REPO = 'BSData/wh40k-11e';
    private const BRANCH = 'main';
    public const GAME_SYSTEM_FILE = 'Warhammer 40,000.json';

    /**
     * Factions jouables => fichier du catalogue principal dans BSData/wh40k-11e.
     * Clés = valeurs enregistrées dans army_list.faction / faction_unit.faction (ne pas renommer).
     * Non proposés : les bibliothèques (« … Library », « Library - … ») et « Unaligned Forces »
     * (fortifications communes, importées par les catalogues qui y ont droit).
     */
    public const FACTION_FILES = [
        'Space Marines' => 'Imperium - Space Marines.json',
        'Ultramarines' => 'Imperium - Ultramarines.json',
        'Blood Angels' => 'Imperium - Blood Angels.json',
        'Dark Angels' => 'Imperium - Dark Angels.json',
        'Space Wolves' => 'Imperium - Space Wolves.json',
        'Black Templars' => 'Imperium - Black Templars.json',
        'Imperial Fists' => 'Imperium - Imperial Fists.json',
        'Iron Hands' => 'Imperium - Iron Hands.json',
        'Raven Guard' => 'Imperium - Raven Guard.json',
        'Salamanders' => 'Imperium - Salamanders.json',
        'White Scars' => 'Imperium - White Scars.json',
        'Deathwatch' => 'Imperium - Deathwatch.json',
        'Grey Knights' => 'Imperium - Grey Knights.json',
        'Adeptus Custodes' => 'Imperium - Adeptus Custodes.json',
        'Sisters of Battle' => 'Imperium - Adepta Sororitas.json',
        'Astra Militarum' => 'Imperium - Astra Militarum.json',
        'Adeptus Mechanicus' => 'Imperium - Adeptus Mechanicus.json',
        'Imperial Knights' => 'Imperium - Imperial Knights.json',
        'Agents of the Imperium' => 'Imperium - Agents of the Imperium.json',
        'Adeptus Titanicus' => 'Imperium - Adeptus Titanicus.json',
        'Chaos Space Marines' => 'Chaos - Chaos Space Marines.json',
        'Death Guard' => 'Chaos - Death Guard.json',
        'Thousand Sons' => 'Chaos - Thousand Sons.json',
        'World Eaters' => 'Chaos - World Eaters.json',
        "Emperor's Children" => "Chaos - Emperor's Children.json",
        'Chaos Knights' => 'Chaos - Chaos Knights.json',
        'Daemons' => 'Chaos - Chaos Daemons.json',
        'Titanicus Traitoris' => 'Chaos - Titanicus Traitoris.json',
        'Orks' => 'Orks.json',
        'Eldar' => 'Aeldari - Craftworlds.json',
        'Drukhari' => 'Aeldari - Drukhari.json',
        'Tyranids' => 'Tyranids.json',
        'Genestealer Cults' => 'Genestealer Cults.json',
        'Tau' => "T'au Empire.json",
        'Necrons' => 'Necrons.json',
        'Leagues of Votann' => 'Leagues of Votann.json',
    ];

    /**
     * Mots-clés de faction (« Faction: X » dans BSData) des unités propres à chaque armée : les unités
     * importées d'autres catalogues sans l'un de ces mots-clés sont des alliés, écartés (voir UnitExtractor).
     */
    public const FACTION_KEYWORDS = [
        'Space Marines' => ['Adeptus Astartes'],
        'Ultramarines' => ['Adeptus Astartes', 'Ultramarines'],
        'Blood Angels' => ['Adeptus Astartes', 'Blood Angels'],
        'Dark Angels' => ['Adeptus Astartes', 'Dark Angels'],
        'Space Wolves' => ['Adeptus Astartes', 'Space Wolves'],
        'Black Templars' => ['Adeptus Astartes', 'Black Templars'],
        'Imperial Fists' => ['Adeptus Astartes', 'Imperial Fists'],
        'Iron Hands' => ['Adeptus Astartes', 'Iron Hands'],
        'Raven Guard' => ['Adeptus Astartes', 'Raven Guard'],
        'Salamanders' => ['Adeptus Astartes', 'Salamanders'],
        'White Scars' => ['Adeptus Astartes', 'White Scars'],
        'Deathwatch' => ['Adeptus Astartes', 'Deathwatch'],
        'Grey Knights' => ['Grey Knights'],
        'Adeptus Custodes' => ['Adeptus Custodes'],
        'Sisters of Battle' => ['Adepta Sororitas'],
        'Astra Militarum' => ['Astra Militarum'],
        'Adeptus Mechanicus' => ['Adeptus Mechanicus'],
        'Imperial Knights' => ['Imperial Knights'],
        'Agents of the Imperium' => ['Agents of the Imperium'],
        'Adeptus Titanicus' => ['Adeptus Titanicus'],
        'Chaos Space Marines' => ['Heretic Astartes'],
        'Death Guard' => ['Death Guard'],
        'Thousand Sons' => ['Thousand Sons'],
        'World Eaters' => ['World Eaters'],
        "Emperor's Children" => ["Emperor's Children"],
        'Chaos Knights' => ['Chaos Knights'],
        'Daemons' => ['Legiones Daemonica'],
        'Titanicus Traitoris' => ['Titanicus Traitoris'],
        'Orks' => ['Orks'],
        'Eldar' => ['Asuryani', 'Harlequins', 'Ynnari'],
        'Drukhari' => ['Drukhari'],
        'Tyranids' => ['Tyranids'],
        'Genestealer Cults' => ['Genestealer Cults'],
        'Tau' => ["T'au Empire"],
        'Necrons' => ['Necrons'],
        'Leagues of Votann' => ['Leagues of Votann'],
    ];

    /** Regroupement du menu déroulant (optgroup => factions, dans l'ordre d'affichage). */
    public const FACTION_GROUPS = [
        'Space marines' => [
            'Space Marines', 'Ultramarines', 'Blood Angels', 'Dark Angels', 'Space Wolves', 'Black Templars',
            'Imperial Fists', 'Iron Hands', 'Raven Guard', 'Salamanders', 'White Scars', 'Deathwatch', 'Grey Knights',
        ],
        'Imperium' => [
            'Adeptus Custodes', 'Sisters of Battle', 'Astra Militarum', 'Adeptus Mechanicus', 'Imperial Knights',
            'Agents of the Imperium', 'Adeptus Titanicus',
        ],
        'Chaos' => [
            'Chaos Space Marines', 'Death Guard', 'Thousand Sons', 'World Eaters', "Emperor's Children",
            'Chaos Knights', 'Daemons', 'Titanicus Traitoris',
        ],
        'Xenos' => ['Orks', 'Eldar', 'Drukhari', 'Tyranids', 'Genestealer Cults', 'Tau', 'Necrons', 'Leagues of Votann'],
    ];

    /** Factions mises en avant dans le menu (couleur « success »). */
    public const FEATURED_FACTIONS = [
        'Space Marines', 'Adeptus Custodes', 'Chaos Space Marines', 'Orks', 'Tyranids', 'Tau', 'Necrons', 'Leagues of Votann',
    ];

    /** @var array<string, string>|null chemin => SHA de blob (arbre du dépôt, mis en cache pour l'exécution) */
    private ?array $tree = null;
    private bool $treeFetched = false;

    /** @var array<string, array> fichier => JSON décodé (cache mémoire de l'exécution) */
    private array $decoded = [];

    /** @var array<string, string> id de catalogue => fichier */
    private array $catalogueIdToFile = [];

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%kernel.cache_dir%/bsdata')]
        private string $cacheDir,
    ) {
    }

    /**
     * Groupes pour le menu déroulant : list<{label, factions: list<{value, featured}>}>.
     *
     * @return list<array{label: string, factions: list<array{value: string, featured: bool}>}>
     */
    public static function factionGroups(): array
    {
        $groups = [];
        foreach (self::FACTION_GROUPS as $label => $factions) {
            $groups[] = [
                'label' => $label,
                'factions' => array_map(fn(string $f) => ['value' => $f, 'featured' => in_array($f, self::FEATURED_FACTIONS, true)], $factions),
            ];
        }

        return $groups;
    }

    /**
     * Arbre du dépôt : chemin => SHA de blob, en UN appel à l'API GitHub. null si l'API est indisponible
     * (limite de requêtes atteinte, réseau…).
     *
     * @return array<string, string>|null
     */
    public function getRepoTree(): ?array
    {
        if ($this->treeFetched) {
            return $this->tree;
        }
        $this->treeFetched = true;

        try {
            $response = $this->httpClient->request('GET', sprintf('https://api.github.com/repos/%s/git/trees/%s', self::REPO, self::BRANCH), [
                'headers' => ['User-Agent' => 'SprueHub-ArmyBuilder', 'Accept' => 'application/vnd.github+json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $data = $response->toArray(false);
        } catch (\Throwable) {
            return null;
        }

        $tree = [];
        foreach ($data['tree'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'blob' && str_ends_with((string) $item['path'], '.json')) {
                $tree[$item['path']] = $item['sha'];
            }
        }

        return $this->tree = $tree;
    }

    /**
     * Empreinte combinée des fichiers donnés (SHA de blob de chacun), ou null si l'arbre est indisponible
     * ou qu'un fichier est absent du dépôt.
     *
     * @param list<string> $files
     */
    public function combinedHash(array $files): ?string
    {
        $tree = $this->getRepoTree();
        if ($tree === null) {
            return null;
        }
        $files = array_values(array_unique($files));
        sort($files);
        $parts = [];
        foreach ($files as $file) {
            if (!isset($tree[$file])) {
                return null;
            }
            $parts[] = $file . ':' . $tree[$file];
        }

        return sha1(implode("\n", $parts));
    }

    /**
     * Télécharge (ou relit depuis le cache) et décode un fichier JSON du dépôt.
     */
    public function fetchFile(string $file): array
    {
        if (isset($this->decoded[$file])) {
            return $this->decoded[$file];
        }

        $sha = $this->getRepoTree()[$file] ?? null;
        $cachePath = $sha !== null ? sprintf('%s/%s.json', $this->cacheDir, $sha) : null;
        $raw = $cachePath !== null && is_file($cachePath) ? file_get_contents($cachePath) : false;

        if ($raw === false) {
            $response = $this->httpClient->request('GET', sprintf(
                'https://raw.githubusercontent.com/%s/%s/%s',
                self::REPO,
                self::BRANCH,
                rawurlencode($file)
            ), [
                'headers' => ['User-Agent' => 'SprueHub-ArmyBuilder'],
            ]);
            $status = $response->getStatusCode();
            if ($status !== 200) {
                throw new \RuntimeException(sprintf('Téléchargement de "%s" impossible (HTTP %d).', $file, $status));
            }
            $raw = $response->getContent();
            if ($cachePath !== null) {
                if (!is_dir($this->cacheDir)) {
                    @mkdir($this->cacheDir, 0775, true);
                }
                @file_put_contents($cachePath, $raw);
            }
        }

        try {
            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Fichier "%s" illisible : %s', $file, $e->getMessage()));
        }
        $data = $json['catalogue'] ?? $json['gameSystem'] ?? null;
        if (!is_array($data) || !isset($data['id'])) {
            throw new \RuntimeException(sprintf('Fichier "%s" invalide (clé "catalogue"/"gameSystem" absente).', $file));
        }

        $this->catalogueIdToFile[$data['id']] = $file;

        return $this->decoded[$file] = $data;
    }

    /** Libère le cache mémoire (les fichiers restent en cache disque). */
    public function clearMemoryCache(): void
    {
        $this->decoded = [];
    }

    /**
     * Graphe de catalogues d'une faction : catalogue principal, catalogues liés (transitivement,
     * en largeur) et système de jeu.
     */
    public function loadGraph(string $mainFile): CatalogueGraph
    {
        $main = $this->fetchFile($mainFile);
        $catalogues = [['file' => $mainFile, 'data' => $main]];
        $seen = [$main['id'] => true];
        /** @var array<string, list<array{0: string, 1: bool}>> $links fichier => [fichier lié, importRootEntries] */
        $links = [];

        $queue = [[$mainFile, $main]];
        while ($queue) {
            [$file, $data] = array_shift($queue);
            foreach ($data['catalogueLinks'] ?? [] as $link) {
                $targetId = $link['targetId'] ?? null;
                if (!is_string($targetId)) {
                    continue;
                }
                $linkedFile = $this->resolveCatalogueFile($targetId, (string) ($link['name'] ?? ''));
                $links[$file][] = [$linkedFile, ($link['importRootEntries'] ?? false) === true];
                if (!isset($seen[$targetId])) {
                    $seen[$targetId] = true;
                    $linked = $this->fetchFile($linkedFile);
                    $catalogues[] = ['file' => $linkedFile, 'data' => $linked];
                    $queue[] = [$linkedFile, $linked];
                }
            }
        }

        // Entrées racines importées transitivement, tant que la chaîne de liens est en importRootEntries=true
        $rootImport = [$mainFile => true];
        $rootImportFiles = [];
        $pending = [$mainFile];
        while ($pending) {
            $file = array_shift($pending);
            foreach ($links[$file] ?? [] as [$linkedFile, $importRoot]) {
                if ($importRoot && !isset($rootImport[$linkedFile])) {
                    $rootImport[$linkedFile] = true;
                    $rootImportFiles[] = $linkedFile;
                    $pending[] = $linkedFile;
                }
            }
        }

        $gameSystem = $this->fetchFile(self::GAME_SYSTEM_FILE);
        if (($main['gameSystemId'] ?? null) !== $gameSystem['id']) {
            throw new \RuntimeException(sprintf('Le catalogue "%s" ne cible pas le système de jeu "%s".', $mainFile, self::GAME_SYSTEM_FILE));
        }
        $catalogues[] = ['file' => self::GAME_SYSTEM_FILE, 'data' => $gameSystem];

        return new CatalogueGraph($mainFile, $catalogues, self::GAME_SYSTEM_FILE, $rootImportFiles);
    }

    /**
     * Unités jouables d'une faction (voir UnitExtractor) : ['units' => …, 'excluded' => …].
     */
    public function extractUnits(CatalogueGraph $graph): array
    {
        return $this->extractor($graph)->extract();
    }

    /** @return list<array{bsdataId: string, name: string}> */
    public function extractDetachments(CatalogueGraph $graph): array
    {
        return $this->extractor($graph)->extractDetachments();
    }

    /**
     * @return list<array{bsdataId: string, name: string, detachment: ?string, detachmentIds: list<string>, points: int, description: ?string}>
     */
    public function extractEnhancements(CatalogueGraph $graph): array
    {
        return $this->extractor($graph)->extractEnhancements();
    }

    /** Extracteur configuré avec les mots-clés de faction de l'armée dont $graph est le catalogue principal. */
    public function extractor(CatalogueGraph $graph): UnitExtractor
    {
        $faction = array_search($graph->mainFile(), self::FACTION_FILES, true);

        return new UnitExtractor($graph, $faction !== false ? self::FACTION_KEYWORDS[$faction] ?? [] : []);
    }

    /**
     * Fichier d'un catalogue lié : d'abord par son nom (« <nom>.json »), vérifié par l'identifiant ;
     * sinon parcours des fichiers du dépôt jusqu'à trouver l'identifiant.
     */
    private function resolveCatalogueFile(string $catalogueId, string $name): string
    {
        if (isset($this->catalogueIdToFile[$catalogueId])) {
            return $this->catalogueIdToFile[$catalogueId];
        }

        $tree = $this->getRepoTree();
        $candidates = [];
        if ($name !== '') {
            $candidates[] = $name . '.json';
        }
        foreach (array_keys($tree ?? []) as $path) {
            if ($path !== self::GAME_SYSTEM_FILE) {
                $candidates[] = $path;
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            if ($tree !== null && !isset($tree[$candidate])) {
                continue;
            }
            try {
                $data = $this->fetchFile($candidate);
            } catch (\RuntimeException) {
                continue;
            }
            if ($data['id'] === $catalogueId) {
                return $candidate;
            }
        }

        throw new \RuntimeException(sprintf('Catalogue lié introuvable dans le dépôt : "%s" (%s).', $name, $catalogueId));
    }
}
