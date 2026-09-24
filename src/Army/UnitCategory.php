<?php

namespace App\Army;

/**
 * Groupes d'unités d'une liste d'armée (Warhammer 40 000, 10e édition) : SOURCE DE VÉRITÉ UNIQUE.
 *
 * Utilisé par :
 *  - ArmyListController (JSON du catalogue, unités initiales de l'édition, page de la liste) ;
 *  - assets/controllers/army_form_controller.js, qui ne fait que trier/grouper selon `group` / `groupOrder`
 *    fournis par le serveur (repli « autres ») ;
 *  - App\BsData\UnitExtractor::pickCategory(), pour la catégorie enregistrée lors de la synchronisation BSData.
 *
 * Résolution d'une unité (resolve()) :
 *  1. Si ses mots-clés sont connus (statsData.keywords, copiés de BSData) :
 *     a. mots-clés « hors roster » (Spore Mines, Mucolid Spores) → Autres ;
 *     b. sinon le PREMIER mot-clé présent dans KEYWORD_PRIORITY l'emporte :
 *        Epic Hero > Character > Battleline > Dedicated Transport > Fortification > Aircraft > Monster
 *        > Vehicle > Mounted > Beast > Swarm > Infantry.
 *        Ex. : Character + Infantry → Personnages ; Battleline + Infantry → Troupes de ligne ;
 *        Vehicle + Aircraft → Aéronefs ; Monster + Character → Personnages.
 *        Le simple mot-clé « Transport » (véhicule pouvant embarquer, ex. Land Raider) ne suffit pas :
 *        seul « Dedicated Transport » classe en Transports assignés.
 *  2. Sinon, la catégorie enregistrée (faction_unit.category / army_unit.category) via CATEGORY_ALIASES
 *     (ex. « Transport » → Transports assignés, « Harpy » → Monstres).
 *  3. Sinon → Autres.
 * Le regroupement ne dépend donc pas d'une nouvelle synchronisation : il se recalcule à l'affichage.
 */
final class UnitCategory
{
    public const OTHER = 'autres';

    /**
     * Groupes, dans l'ordre d'affichage. icon = nom d'icône du design system (templates/_partials/_icon.html.twig).
     *
     * @var array<string, array{label: string, plural: string, icon: string}>
     */
    public const GROUPS = [
        'heros-epiques' => ['label' => 'Héros épique', 'plural' => 'Héros épiques', 'icon' => 'crown'],
        'personnages' => ['label' => 'Personnage', 'plural' => 'Personnages', 'icon' => 'user'],
        'troupes-de-ligne' => ['label' => 'Troupe de ligne', 'plural' => 'Troupes de ligne', 'icon' => 'shield'],
        'infanterie' => ['label' => 'Infanterie', 'plural' => 'Infanterie', 'icon' => 'users'],
        'montes' => ['label' => 'Unité montée', 'plural' => 'Unités montées', 'icon' => 'bike'],
        'betes' => ['label' => 'Bête', 'plural' => 'Bêtes', 'icon' => 'paw-print'],
        'nuees' => ['label' => 'Nuée', 'plural' => 'Nuées', 'icon' => 'bug'],
        'monstres' => ['label' => 'Monstre', 'plural' => 'Monstres', 'icon' => 'skull'],
        'vehicules' => ['label' => 'Véhicule', 'plural' => 'Véhicules', 'icon' => 'truck'],
        'aeronefs' => ['label' => 'Aéronef', 'plural' => 'Aéronefs', 'icon' => 'plane'],
        'transports' => ['label' => 'Transport assigné', 'plural' => 'Transports assignés', 'icon' => 'bus'],
        'fortifications' => ['label' => 'Fortification', 'plural' => 'Fortifications', 'icon' => 'castle'],
        self::OTHER => ['label' => 'Autre', 'plural' => 'Autres', 'icon' => 'layout-grid'],
    ];

    /** Mot-clé BSData → groupe, par ordre de priorité décroissante (le premier présent l'emporte). */
    public const KEYWORD_PRIORITY = [
        'Epic Hero' => 'heros-epiques',
        'Character' => 'personnages',
        'Battleline' => 'troupes-de-ligne',
        'Dedicated Transport' => 'transports',
        'Fortification' => 'fortifications',
        'Aircraft' => 'aeronefs',
        'Monster' => 'monstres',
        'Vehicle' => 'vehicules',
        'Mounted' => 'montes',
        'Beast' => 'betes',
        'Swarm' => 'nuees',
        'Infantry' => 'infanterie',
    ];

    /** Mots-clés d'entrées qui ne sont pas de vraies unités de roster (profils générés par d'autres unités). */
    public const OTHER_KEYWORDS = ['Spore Mines', 'Mucolid Spores'];

    /** Catégories enregistrées (hors KEYWORD_PRIORITY) → groupe, pour le repli sans mots-clés. */
    private const CATEGORY_ALIASES = [
        'Transport' => 'transports',
        'Harpy' => 'monstres',
    ];

    /**
     * Mot-clé déterminant parmi $keywords (celui qui fixe le groupe), ou null si aucun n'est reconnu.
     * Sert aussi de catégorie enregistrée à la synchronisation BSData.
     *
     * @param list<string> $keywords
     */
    public static function primaryKeyword(array $keywords): ?string
    {
        foreach (self::OTHER_KEYWORDS as $keyword) {
            if (in_array($keyword, $keywords, true)) {
                return $keyword;
            }
        }
        foreach (array_keys(self::KEYWORD_PRIORITY) as $keyword) {
            if (in_array($keyword, $keywords, true)) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * Clé de groupe d'une unité, d'après ses mots-clés (prioritaires) ou sa catégorie enregistrée.
     *
     * @param array<mixed> $keywords
     */
    public static function resolve(?string $category, array $keywords = []): string
    {
        $keywords = array_values(array_filter($keywords, 'is_string'));
        if ($keywords !== []) {
            $primary = self::primaryKeyword($keywords);
            if ($primary !== null) {
                return self::KEYWORD_PRIORITY[$primary] ?? self::OTHER;
            }
        }

        if ($category !== null && $category !== '') {
            if (in_array($category, self::OTHER_KEYWORDS, true)) {
                return self::OTHER;
            }

            return self::KEYWORD_PRIORITY[$category] ?? self::CATEGORY_ALIASES[$category] ?? self::OTHER;
        }

        return self::OTHER;
    }

    /** Groupe d'une unité à partir de sa catégorie et de son statsData (tableau BSData ou null). */
    public static function resolveFromStats(?string $category, ?array $statsData): string
    {
        $keywords = $statsData['keywords'] ?? [];

        return self::resolve($category, is_array($keywords) ? $keywords : []);
    }

    /** Position du groupe dans l'ordre d'affichage (0 = premier). */
    public static function order(string $group): int
    {
        $index = array_search($group, array_keys(self::GROUPS), true);

        return $index === false ? count(self::GROUPS) - 1 : $index;
    }

    /** Libellé (pluriel) d'un groupe, tel qu'affiché en en-tête de section. */
    public static function label(string $group): string
    {
        return (self::GROUPS[$group] ?? self::GROUPS[self::OTHER])['plural'];
    }

    /**
     * Groupes sous forme de liste ordonnée (pour le JS / les templates).
     *
     * @return list<array{key: string, label: string, plural: string, icon: string, order: int}>
     */
    public static function all(): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $group) {
            $groups[] = ['key' => $key, 'order' => count($groups)] + $group;
        }

        return $groups;
    }
}
