<?php

namespace App\Gamification;

/**
 * Catalogue des badges : SOURCE DE VÉRITÉ UNIQUE.
 *
 * La table `badge` n'en est qu'une projection (clés étrangères de user_badge, affichage EasyAdmin) :
 * elle est remplie par la migration Version20260927100000 puis maintenue par
 * `php bin/console app:gamification:sync-badges` (upsert par code, suppression des codes obsolètes).
 * Toute modification d'un badge (texte, XP, seuil, icône…) se fait ICI, puis on lance la commande.
 *
 * Paliers (couleur sur le profil), calculés depuis la récompense XP :
 * bronze 0–100, argent 101–200, or 201–300, premium > 300.
 */
final class BadgeCatalog
{
    public const CATEGORY_FORUM = 'Forum';
    public const CATEGORY_EXPLORATION = 'Exploration';
    public const CATEGORY_LEVEL = 'Niveau';
    public const CATEGORY_SPECIAL = 'Spécial';

    /** Ordre d'affichage des catégories. */
    public const CATEGORIES = [self::CATEGORY_FORUM, self::CATEGORY_EXPLORATION, self::CATEGORY_LEVEL, self::CATEGORY_SPECIAL];

    // Types de règles (chacun correspond à un compteur calculé par GamificationService::collectStats())
    public const RULE_THREADS = 'threads';                 // sujets publiés
    public const RULE_POSITIVE_VOTES = 'positive_votes';   // votes positifs reçus (d'autres membres)
    public const RULE_HELPFUL_VOTES = 'helpful_votes';     // votes « aide » reçus (d'autres membres)
    public const RULE_MASTER_THREADS = 'master_threads';   // sujets ayant >= MASTER_MIN_REPLIES réponses d'autres membres
    public const RULE_LEVEL = 'level';                     // niveau atteint
    public const RULE_SECTIONS = 'sections';               // rubriques visitées (SECTIONS)
    public const RULE_LEGAL = 'legal';                     // pages légales visitées (LEGAL_ROUTES)
    public const RULE_ARCHAEOLOGIST = 'archaeologist';     // activité « a ouvert un sujet de plus d'un an »
    public const RULE_EARLY_MEMBER = 'early_member';       // rang d'inscription

    /** Nombre minimal de réponses d'autres membres pour qu'un sujet compte pour « Maître forgeron ». */
    public const MASTER_MIN_REPLIES = 10;

    /** Âge minimal d'un sujet pour « Archéologue ». */
    public const ARCHAEOLOGIST_MIN_AGE = '-1 year';

    /** Clé d'activité enregistrée à l'ouverture d'un sujet de plus d'un an. */
    public const ARCHAEOLOGIST_ACTIVITY = 'archaeologist';

    /**
     * Rubriques principales du site (badge « Curieux ») : clé d'activité => libellé.
     * Une visite = un GET d'une page HTML de la rubrique en étant connecté (activité « visit:<route> »).
     * Le profil ne compte que pour SON PROPRE profil : activité dédiée OWN_PROFILE_ACTIVITY.
     */
    public const OWN_PROFILE_ACTIVITY = 'visit:own_profile';
    public const SECTIONS = [
        'visit:app_home' => 'Accueil',
        'visit:app_forum_index' => 'Forum',
        'visit:app_group_index' => 'Groupes',
        'visit:app_todo_index' => 'Mes tâches',
        'visit:app_army_index' => 'Listes d\'armée',
        'visit:app_friendship_list' => 'Amis',
        'visit:app_message_index' => 'Messages',
        'visit:app_notification_index' => 'Notifications',
        self::OWN_PROFILE_ACTIVITY => 'Mon profil',
    ];

    /** Pages légales (badge « Juriste ») : clé d'activité => libellé. */
    public const LEGAL_ROUTES = [
        'visit:app_legal' => 'Mentions légales',
        'visit:app_terms' => 'Conditions d\'utilisation',
    ];

    /** Nombre d'inscrits concernés par « Avant-garde ». */
    public const EARLY_MEMBER_LIMIT = 1000;

    public const TIER_BRONZE = 'bronze';
    public const TIER_SILVER = 'silver';
    public const TIER_GOLD = 'gold';
    public const TIER_PREMIUM = 'premium';
    /** Honorary badges (no XP reward) have a colour of their own. */
    public const TIER_HONORARY = 'honorary';

    public const TIER_LABELS = [
        self::TIER_BRONZE => 'Bronze',
        self::TIER_SILVER => 'Argent',
        self::TIER_GOLD => 'Or',
        self::TIER_PREMIUM => 'Premium',
        self::TIER_HONORARY => 'Honorifique',
    ];

    /**
     * Définitions, dans l'ordre d'affichage.
     * rule / threshold : condition ; unit : libellé de la progression (« 12 / 50 sujets »).
     *
     * @var array<string, array{name: string, category: string, description: string, icon: string, xp: int, rule: string, threshold: int, unit: string}>
     */
    private const BADGES = [
        // Forum
        'pioneer_1' => ['name' => 'Pionnier I', 'category' => self::CATEGORY_FORUM, 'icon' => '📝', 'xp' => 50, 'rule' => self::RULE_THREADS, 'threshold' => 1, 'unit' => 'sujet(s)', 'description' => 'Publier votre premier sujet.'],
        'pioneer_2' => ['name' => 'Pionnier II', 'category' => self::CATEGORY_FORUM, 'icon' => '📜', 'xp' => 150, 'rule' => self::RULE_THREADS, 'threshold' => 50, 'unit' => 'sujets', 'description' => 'Publier 50 sujets.'],
        'pioneer_3' => ['name' => 'Pionnier III', 'category' => self::CATEGORY_FORUM, 'icon' => '📚', 'xp' => 300, 'rule' => self::RULE_THREADS, 'threshold' => 100, 'unit' => 'sujets', 'description' => 'Publier 100 sujets.'],
        'popular_1' => ['name' => 'Populaire I', 'category' => self::CATEGORY_FORUM, 'icon' => '👍', 'xp' => 50, 'rule' => self::RULE_POSITIVE_VOTES, 'threshold' => 1, 'unit' => 'vote(s) positif(s)', 'description' => 'Recevoir votre premier vote positif.'],
        'popular_2' => ['name' => 'Populaire II', 'category' => self::CATEGORY_FORUM, 'icon' => '🌟', 'xp' => 150, 'rule' => self::RULE_POSITIVE_VOTES, 'threshold' => 50, 'unit' => 'votes positifs', 'description' => 'Recevoir 50 votes positifs.'],
        'popular_3' => ['name' => 'Populaire III', 'category' => self::CATEGORY_FORUM, 'icon' => '👑', 'xp' => 300, 'rule' => self::RULE_POSITIVE_VOTES, 'threshold' => 100, 'unit' => 'votes positifs', 'description' => 'Recevoir 100 votes positifs.'],
        'devoted_1' => ['name' => 'Dévoué I', 'category' => self::CATEGORY_FORUM, 'icon' => '🤝', 'xp' => 50, 'rule' => self::RULE_HELPFUL_VOTES, 'threshold' => 1, 'unit' => 'vote(s) « aide »', 'description' => 'Recevoir votre premier vote « aide ».'],
        'devoted_2' => ['name' => 'Dévoué II', 'category' => self::CATEGORY_FORUM, 'icon' => '🛡️', 'xp' => 150, 'rule' => self::RULE_HELPFUL_VOTES, 'threshold' => 50, 'unit' => 'votes « aide »', 'description' => 'Recevoir 50 votes « aide ».'],
        'devoted_3' => ['name' => 'Dévoué III', 'category' => self::CATEGORY_FORUM, 'icon' => '💠', 'xp' => 300, 'rule' => self::RULE_HELPFUL_VOTES, 'threshold' => 100, 'unit' => 'votes « aide »', 'description' => 'Recevoir 100 votes « aide ».'],
        'master_blacksmith' => ['name' => 'Maître forgeron', 'category' => self::CATEGORY_FORUM, 'icon' => '⚒️', 'xp' => 300, 'rule' => self::RULE_MASTER_THREADS, 'threshold' => 10, 'unit' => 'sujets à 10 réponses', 'description' => 'Créer 10 sujets ayant chacun au moins 10 réponses écrites par d\'autres membres.'],
        // Exploration
        'curious' => ['name' => 'Curieux', 'category' => self::CATEGORY_EXPLORATION, 'icon' => '🧭', 'xp' => 50, 'rule' => self::RULE_SECTIONS, 'threshold' => 9, 'unit' => 'rubriques', 'description' => 'Visiter toutes les rubriques du site : accueil, forum, groupes, tâches, listes d\'armée, amis, messages, notifications et votre profil.'],
        'jurist' => ['name' => 'Juriste', 'category' => self::CATEGORY_EXPLORATION, 'icon' => '⚖️', 'xp' => 50, 'rule' => self::RULE_LEGAL, 'threshold' => 2, 'unit' => 'pages', 'description' => 'Consulter les mentions légales et les conditions d\'utilisation.'],
        'archaeologist' => ['name' => 'Archéologue', 'category' => self::CATEGORY_EXPLORATION, 'icon' => '🏺', 'xp' => 50, 'rule' => self::RULE_ARCHAEOLOGIST, 'threshold' => 1, 'unit' => '', 'description' => 'Ouvrir un sujet datant de plus d\'un an.'],
        // Niveau
        'heroic' => ['name' => 'Héroïque', 'category' => self::CATEGORY_LEVEL, 'icon' => '⚔️', 'xp' => 200, 'rule' => self::RULE_LEVEL, 'threshold' => 10, 'unit' => 'niveau', 'description' => 'Atteindre le niveau 10.'],
        'legendary' => ['name' => 'Légendaire', 'category' => self::CATEGORY_LEVEL, 'icon' => '🐉', 'xp' => 400, 'rule' => self::RULE_LEVEL, 'threshold' => 25, 'unit' => 'niveau', 'description' => 'Atteindre le niveau 25.'],
        'immortal' => ['name' => 'Immortel', 'category' => self::CATEGORY_LEVEL, 'icon' => '💀', 'xp' => 0, 'rule' => self::RULE_LEVEL, 'threshold' => 50, 'unit' => 'niveau', 'description' => 'Atteindre le niveau 50, le niveau maximum.'],
        // Spécial
        'vanguard' => ['name' => 'Avant-garde', 'category' => self::CATEGORY_SPECIAL, 'icon' => '🚩', 'xp' => 0, 'rule' => self::RULE_EARLY_MEMBER, 'threshold' => self::EARLY_MEMBER_LIMIT, 'unit' => '', 'description' => 'Faire partie des 1000 premiers inscrits.'],
    ];

    private static ?array $cache = null;

    /** @return array<string, array{code: string, name: string, category: string, description: string, icon: string, xp: int, rule: string, threshold: int, unit: string, position: int, tier: string}> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $badges = [];
        $position = 0;
        foreach (self::BADGES as $code => $definition) {
            // Seuils dérivés des listes (une rubrique ajoutée ne peut pas désynchroniser le seuil)
            $definition['threshold'] = match ($definition['rule']) {
                self::RULE_SECTIONS => count(self::SECTIONS),
                self::RULE_LEGAL => count(self::LEGAL_ROUTES),
                default => $definition['threshold'],
            };
            $badges[$code] = ['code' => $code, 'position' => ++$position, 'tier' => self::tierFor($definition['xp'])] + $definition;
        }

        return self::$cache = $badges;
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::BADGES);
    }

    /** @return array<string, array> badges dont la règle fait partie de $rules */
    public static function byRules(string ...$rules): array
    {
        return array_filter(self::all(), static fn (array $badge) => in_array($badge['rule'], $rules, true));
    }

    public static function tierFor(int $xp): string
    {
        return match (true) {
            $xp === 0 => self::TIER_HONORARY,
            $xp > 300 => self::TIER_PREMIUM,
            $xp > 200 => self::TIER_GOLD,
            $xp > 100 => self::TIER_SILVER,
            default => self::TIER_BRONZE,
        };
    }

    public static function tierLabel(string $tier): string
    {
        return self::TIER_LABELS[$tier] ?? $tier;
    }
}
