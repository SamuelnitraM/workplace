<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\PostVote;
use App\Entity\Thread;
use App\Entity\User;
use App\Gamification\BadgeCatalog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Gamification : XP, niveaux et badges.
 *
 * GRAND LIVRE D'XP — chaque gain d'XP est une ligne experience_award avec une clé déterministe
 * unique par utilisateur (UNIQ_EXPERIENCE_AWARD_KEY) : « daily:2026-09-23 », « streak:2026-09-23 »,
 * « badge:pioneer_1 ». L'insertion (INSERT … ON DUPLICATE KEY) et l'incrément de user.experience
 * (UPDATE … experience = experience + n) sont faits en SQL dans UNE transaction : le gain est
 * idempotent et sûr face aux requêtes concurrentes (le perdant n'insère rien et ne crédite rien),
 * sans exception de contrainte d'unicité donc sans EntityManager fermé, et sans « lost update »
 * de l'XP. L'objet User en mémoire est ensuite resynchronisé (et marqué propre pour l'ORM).
 * user.experience = somme du grand livre (vérifiable / réparable : app:gamification:recompute --resum).
 *
 * ÉVALUATION CIBLÉE — appels explicites depuis les contrôleurs / le subscriber, chacun ne vérifie
 * que les règles concernées (et ne lance aucune requête si les badges de ces règles sont déjà acquis) :
 *  - onThreadCreated()   : auteur → Pionnier I-III, Maître forgeron
 *  - onPostCreated()     : réponse d'un AUTRE membre → Maître forgeron de l'auteur du sujet
 *  - onVoteReceived()    : vote ajouté → Populaire / Dévoué de l'auteur de la réponse
 *  - gain d'XP           : badges de niveau (en boucle : l'XP d'un badge peut débloquer le suivant)
 *  - recordVisit()       : Curieux (rubriques), Juriste (pages légales)
 *  - onThreadViewed()    : Archéologue
 *  - recordDailyLogin()  : +20 XP / jour (+100 tous les 7 jours de série ; série protégée
 *                          jusqu'à 3 jours d'absence consécutifs), puis Avant-garde
 * Filet de sécurité : syncAllBadges() (toutes les règles), appelé par GamificationSubscriber au plus
 * toutes les 5 minutes par session, et par la commande app:gamification:recompute.
 *
 * Les badges ne sont JAMAIS retirés (vote supprimé, sujet supprimé…).
 */
class GamificationService
{
    public const TIMEZONE = 'Europe/Paris';
    public const DAILY_LOGIN_XP = 20;
    public const STREAK_BONUS_XP = 100;
    /** XP de l'auteur d'une réponse choisie comme solution d'un sujet. */
    public const SOLUTION_XP = 50;
    public const STREAK_BONUS_EVERY = 7;
    /** Protection de série : nombre maximal de jours calendaires consécutifs sans connexion tolérés. */
    public const STREAK_MAX_MISSED_DAYS = 3;

    /** @var array<int, array<string, \DateTimeImmutable>> badges acquis par utilisateur (cache de requête) */
    private array $grantedCache = [];

    /** @var array<string, int>|null code => id de la table badge */
    private ?array $badgeIds = null;

    private bool $notificationsPending = false;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationService $notifications,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
    ) {}

    // ------------------------------------------------------------------
    // Points d'entrée (événements)
    // ------------------------------------------------------------------

    /**
     * Connexion quotidienne : 1 fois par jour calendaire (Europe/Paris), quel que soit le mode
     * d'authentification (formulaire, remember-me, session existante). +20 XP, et +100 XP de bonus
     * chaque fois que la série atteint un multiple de 7 (7e, 14e, 21e… jour de connexion de la série).
     *
     * PROTECTION DE SÉRIE : la série est conservée si le membre s'est absenté au plus
     * STREAK_MAX_MISSED_DAYS (3) jours calendaires consécutifs, soit un écart ≤ 4 jours entre le
     * dernier jour de connexion et aujourd'hui (ex. : lundi puis vendredi → série + 1). Les jours
     * manqués ne comptent pas : la série = nombre de jours de connexion de la période protégée.
     * 4 jours manqués ou plus → la série repart à 1. Voir getStreakStatus() pour l'affichage.
     * Retourne true si la connexion du jour vient d'être comptée.
     */
    public function recordDailyLogin(User $user, ?\DateTimeImmutable $now = null): bool
    {
        if ($user->getId() === null) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $parisNow = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $today = $parisNow->format('Y-m-d');
        $last = self::parisDate($user->getLastDailyLoginAt());
        if ($last !== null && $last >= $today) {
            return false; // chemin rapide : aucune requête
        }

        $streak = $last !== null && self::daysBetween($last, $today) <= self::STREAK_MAX_MISSED_DAYS + 1
            ? $user->getLoginStreak() + 1
            : 1;
        $oldLevel = $user->getLevel();
        $connection = $this->connection();
        $recorded = $connection->transactional(function (Connection $connection) use ($user, $today, $streak, $now): bool {
            if (!$this->insertAward($user, 'daily:' . $today, self::DAILY_LOGIN_XP, $now)) {
                return false; // requête concurrente : la journée est déjà comptée
            }
            $connection->executeStatement(
                'UPDATE `user` SET login_streak = ?, last_daily_login_at = ? WHERE id = ?',
                [$streak, self::dbDateTime($now), $user->getId()],
            );
            if ($streak % self::STREAK_BONUS_EVERY === 0) {
                $this->insertAward($user, 'streak:' . $today, self::STREAK_BONUS_XP, $now);
            }

            return true;
        });

        $this->reloadUserState($user);
        if ($recorded) {
            $this->afterExperienceGain($user, $oldLevel);
            $this->evaluate($user, [BadgeCatalog::RULE_EARLY_MEMBER]);
        }
        $this->flushNotifications();

        return $recorded;
    }

    /**
     * État de la série de connexions, pour l'affichage (aucune requête SQL). Clés du tableau :
     *  - streak          (int)  série en cours ; 0 si elle est perdue (plus de 3 jours d'absence) ou inexistante
     *  - countedToday    (bool) la connexion d'aujourd'hui est déjà comptée
     *  - missedDays      (int)  jours d'absence consécutifs depuis la dernière connexion, aujourd'hui exclu
     *  - daysLeft        (int)  jours d'absence encore tolérés avant de perdre la série (0 à 3 ; 0 si perdue ou
     *                           si le membre doit se connecter aujourd'hui au plus tard)
     *  - atRisk          (bool) la protection est en cours d'utilisation (≥ 1 jour manqué, aujourd'hui pas encore compté)
     *  - expiresOn       (?\DateTimeImmutable) dernier jour (Europe/Paris, 00:00) pour se connecter sans perdre la série
     *  - nextBonusIn     (int)  jours de connexion encore nécessaires pour le prochain bonus (1 à 7)
     *  - nextBonusAt     (int)  palier de série du prochain bonus (multiple de 7)
     *  - bonusXp         (int)  XP du bonus de série
     * Ex. Twig : {% set s = streakStatus %}{{ s.streak }} jour(s), bonus dans {{ s.nextBonusIn }} jour(s)
     *
     * @return array{streak: int, countedToday: bool, missedDays: int, daysLeft: int, atRisk: bool, expiresOn: ?\DateTimeImmutable, nextBonusIn: int, nextBonusAt: int, bonusXp: int}
     */
    public function getStreakStatus(User $user, ?\DateTimeImmutable $now = null): array
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $today = ($now ?? new \DateTimeImmutable())->setTimezone($timezone)->format('Y-m-d');
        $last = self::parisDate($user->getLastDailyLoginAt());
        $gap = $last !== null ? self::daysBetween($last, $today) : null;

        $alive = $gap !== null && $gap <= self::STREAK_MAX_MISSED_DAYS + 1 && $user->getLoginStreak() > 0;
        $streak = $alive ? $user->getLoginStreak() : 0;
        $countedToday = $alive && $gap <= 0;
        $missedDays = $alive ? max(0, $gap - 1) : 0;
        $remainder = $streak % self::STREAK_BONUS_EVERY;
        $nextBonusIn = self::STREAK_BONUS_EVERY - $remainder;

        return [
            'streak' => $streak,
            'countedToday' => $countedToday,
            'missedDays' => $missedDays,
            'daysLeft' => $alive ? max(0, self::STREAK_MAX_MISSED_DAYS - $missedDays) : 0,
            'atRisk' => $alive && !$countedToday && $missedDays > 0,
            'expiresOn' => $alive ? new \DateTimeImmutable($last . ' +' . (self::STREAK_MAX_MISSED_DAYS + 1) . ' days', $timezone) : null,
            'nextBonusIn' => $nextBonusIn,
            'nextBonusAt' => $streak + $nextBonusIn,
            'bonusXp' => self::STREAK_BONUS_XP,
        ];
    }

    /**
     * Instant (fuseau de la base) à partir duquel une dernière connexion garde la série vivante :
     * minuit (Europe/Paris) du jour J - (STREAK_MAX_MISSED_DAYS + 1). Pour filtrer en SQL
     * (last_daily_login_at >= seuil), ex. le classement des séries.
     */
    public static function streakAliveThreshold(?\DateTimeImmutable $now = null): string
    {
        $parisToday = ($now ?? new \DateTimeImmutable())->setTimezone(new \DateTimeZone(self::TIMEZONE))->setTime(0, 0);

        return self::dbDateTime($parisToday->modify('-' . (self::STREAK_MAX_MISSED_DAYS + 1) . ' days'));
    }

    /** Visite d'une page (activité « visit:<route> » ou OWN_PROFILE_ACTIVITY). Retourne true si nouvelle. */
    public function recordVisit(User $user, string $activityKey): bool
    {
        if (!$this->recordActivity($user, $activityKey)) {
            return false;
        }

        $rules = [];
        if (isset(BadgeCatalog::SECTIONS[$activityKey])) {
            $rules[] = BadgeCatalog::RULE_SECTIONS;
        }
        if (isset(BadgeCatalog::LEGAL_ROUTES[$activityKey])) {
            $rules[] = BadgeCatalog::RULE_LEGAL;
        }
        if ($rules) {
            $this->evaluate($user, $rules);
            $this->flushNotifications();
        }

        return true;
    }

    /** Ouverture d'un sujet par un membre connecté (Archéologue si le sujet a plus d'un an). */
    public function onThreadViewed(User $visitor, Thread $thread): void
    {
        if (!$thread->getCreatedAt() || $thread->getCreatedAt() >= new \DateTimeImmutable(BadgeCatalog::ARCHAEOLOGIST_MIN_AGE)) {
            return;
        }
        if ($this->recordActivity($visitor, BadgeCatalog::ARCHAEOLOGIST_ACTIVITY)) {
            $this->evaluate($visitor, [BadgeCatalog::RULE_ARCHAEOLOGIST]);
            $this->flushNotifications();
        }
    }

    /** Sujet créé (après flush) : Pionnier et Maître forgeron de l'auteur. */
    public function onThreadCreated(Thread $thread): void
    {
        if ($author = $thread->getAuthor()) {
            $this->evaluate($author, [BadgeCatalog::RULE_THREADS, BadgeCatalog::RULE_MASTER_THREADS]);
            $this->flushNotifications();
        }
    }

    /** Réponse créée (après flush) : Maître forgeron de l'auteur du sujet, si la réponse vient d'un autre membre. */
    public function onPostCreated(Post $post): void
    {
        $threadAuthor = $post->getThread()?->getAuthor();
        if ($threadAuthor && $post->getAuthor() && $post->getAuthor()->getId() !== $threadAuthor->getId()) {
            $this->evaluate($threadAuthor, [BadgeCatalog::RULE_MASTER_THREADS]);
            $this->flushNotifications();
        }
    }

    /**
     * Réponse choisie comme solution d'un sujet : XP pour son auteur (pas pour l'auteur du sujet lui-même).
     * Une seule fois par membre et par sujet (clé « solution:<id du sujet> ») : changer de solution puis revenir
     * ne recrédite rien, et l'XP n'est pas retirée si la solution est ensuite retirée.
     */
    public function onSolutionChosen(Post $post): bool
    {
        $author = $post->getAuthor();
        $thread = $post->getThread();
        if (!$author || !$thread || $author->getId() === $thread->getAuthor()?->getId()) {
            return false;
        }

        $awarded = $this->awardExperience($author, 'solution:' . $thread->getId(), self::SOLUTION_XP);
        $this->flushNotifications();

        return $awarded;
    }

    /** Vote ajouté (après flush) : Populaire / Dévoué de l'auteur de la réponse. */
    public function onVoteReceived(Post $post, string $type): void
    {
        $author = $post->getAuthor();
        if (!$author) {
            return;
        }
        $rule = $type === PostVote::TYPE_HELPFUL ? BadgeCatalog::RULE_HELPFUL_VOTES : BadgeCatalog::RULE_POSITIVE_VOTES;
        $this->evaluate($author, [$rule]);
        $this->flushNotifications();
    }

    /** Évaluation complète (filet de sécurité). Retourne true si au moins un badge a été obtenu. */
    public function syncAllBadges(User $user): bool
    {
        $granted = $this->evaluate($user, array_unique(array_column(BadgeCatalog::all(), 'rule')));
        $this->flushNotifications();

        return $granted !== [];
    }

    // ------------------------------------------------------------------
    // Moteur
    // ------------------------------------------------------------------

    /**
     * Attribue les badges des règles $rules dont l'utilisateur remplit la condition.
     *
     * @param list<string> $rules
     * @return list<string> codes obtenus
     */
    public function evaluate(User $user, array $rules): array
    {
        if ($user->getId() === null) {
            return [];
        }

        $granted = $this->grantedBadges($user);
        $pending = array_filter(BadgeCatalog::byRules(...$rules), static fn (array $badge) => !isset($granted[$badge['code']]));
        if (!$pending) {
            return []; // tout est déjà acquis : aucune requête
        }

        $stats = $this->collectStats($user, array_values(array_unique(array_column($pending, 'rule'))));
        $obtained = [];
        foreach ($pending as $code => $badge) {
            if ($this->isEligible($badge, $stats) && $this->grantBadge($user, $code)) {
                $obtained[] = $code;
            }
        }

        return $obtained;
    }

    /**
     * Compteurs de l'utilisateur pour les règles demandées (une requête par famille de règles).
     *
     * @param list<string> $rules
     * @return array<string, mixed>
     */
    public function collectStats(User $user, array $rules): array
    {
        $connection = $this->connection();
        $userId = $user->getId();
        $stats = [];
        $wants = static fn (string ...$candidates): bool => (bool) array_intersect($candidates, $rules);

        if ($wants(BadgeCatalog::RULE_THREADS)) {
            $stats[BadgeCatalog::RULE_THREADS] = (int) $connection->fetchOne('SELECT COUNT(*) FROM thread WHERE author_id = ?', [$userId]);
        }

        if ($wants(BadgeCatalog::RULE_POSITIVE_VOTES, BadgeCatalog::RULE_HELPFUL_VOTES)) {
            // Seuls les votes d'AUTRES membres comptent (anti-farming)
            $votes = $connection->fetchAllKeyValue(
                'SELECT v.type, COUNT(*) FROM post_vote v INNER JOIN post p ON p.id = v.post_id
                 WHERE p.author_id = ? AND v.user_id <> p.author_id GROUP BY v.type',
                [$userId],
            );
            $stats[BadgeCatalog::RULE_POSITIVE_VOTES] = (int) ($votes[PostVote::TYPE_POSITIVE] ?? 0);
            $stats[BadgeCatalog::RULE_HELPFUL_VOTES] = (int) ($votes[PostVote::TYPE_HELPFUL] ?? 0);
        }

        if ($wants(BadgeCatalog::RULE_MASTER_THREADS)) {
            $stats[BadgeCatalog::RULE_MASTER_THREADS] = (int) $connection->fetchOne(
                'SELECT COUNT(*) FROM (
                    SELECT t.id FROM thread t INNER JOIN post p ON p.thread_id = t.id AND p.author_id <> t.author_id
                    WHERE t.author_id = ? GROUP BY t.id HAVING COUNT(p.id) >= ?
                 ) master_threads',
                [$userId, BadgeCatalog::MASTER_MIN_REPLIES],
            );
        }

        if ($wants(BadgeCatalog::RULE_LEVEL)) {
            $stats[BadgeCatalog::RULE_LEVEL] = $user->getLevel();
        }

        if ($wants(BadgeCatalog::RULE_SECTIONS, BadgeCatalog::RULE_LEGAL, BadgeCatalog::RULE_ARCHAEOLOGIST)) {
            $keys = array_merge(array_keys(BadgeCatalog::SECTIONS), array_keys(BadgeCatalog::LEGAL_ROUTES), [BadgeCatalog::ARCHAEOLOGIST_ACTIVITY]);
            $done = array_flip($connection->fetchFirstColumn(
                'SELECT activity_key FROM gamification_activity WHERE user_id = ? AND activity_key IN (?)',
                [$userId, $keys],
                [ParameterType::INTEGER, ArrayParameterType::STRING],
            ));
            $stats['activities'] = $done;
            $stats[BadgeCatalog::RULE_SECTIONS] = count(array_intersect_key(BadgeCatalog::SECTIONS, $done));
            $stats[BadgeCatalog::RULE_LEGAL] = count(array_intersect_key(BadgeCatalog::LEGAL_ROUTES, $done));
            $stats[BadgeCatalog::RULE_ARCHAEOLOGIST] = isset($done[BadgeCatalog::ARCHAEOLOGIST_ACTIVITY]) ? 1 : 0;
        }

        if ($wants(BadgeCatalog::RULE_EARLY_MEMBER)) {
            // Rang d'inscription = ordre des identifiants (auto-incrément), parmi les comptes existants
            $stats[BadgeCatalog::RULE_EARLY_MEMBER] = 1 + (int) $connection->fetchOne('SELECT COUNT(*) FROM `user` WHERE id < ?', [$userId]);
        }

        return $stats;
    }

    public function isEligible(array $badge, array $stats): bool
    {
        $value = $stats[$badge['rule']] ?? null;
        if ($value === null) {
            return false;
        }

        return $badge['rule'] === BadgeCatalog::RULE_EARLY_MEMBER
            ? $value <= $badge['threshold']
            : $value >= $badge['threshold'];
    }

    /**
     * Attribue un badge (idempotent) : user_badge + gain d'XP « badge:<code> » dans une transaction,
     * puis notification, et badges de niveau si l'XP a fait monter de niveau.
     */
    public function grantBadge(User $user, string $code, ?\DateTimeImmutable $now = null): bool
    {
        $badge = BadgeCatalog::get($code);
        $badgeId = $this->badgeIds()[$code] ?? null;
        if (!$badge || $user->getId() === null || isset($this->grantedBadges($user)[$code])) {
            return false;
        }
        if ($badgeId === null) {
            $this->logger->warning('Badge {code} absent de la table badge : lancez app:gamification:sync-badges.', ['code' => $code]);

            return false;
        }

        $now ??= new \DateTimeImmutable();
        $oldLevel = $user->getLevel();
        $inserted = $this->connection()->transactional(function (Connection $connection) use ($user, $code, $badge, $badgeId, $now): bool {
            $rows = $connection->executeStatement(
                'INSERT INTO user_badge (user_id, badge_id, unlocked_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
                [$user->getId(), $badgeId, self::dbDateTime($now)],
            );
            if ($rows !== 1) {
                return false; // déjà obtenu (requête concurrente)
            }
            $this->insertAward($user, 'badge:' . $code, $badge['xp'], $now);

            return true;
        });

        $this->grantedCache[$user->getId()][$code] = $now;
        if (!$inserted) {
            return false;
        }

        $this->notify($user, Notification::TYPE_BADGE_EARNED, ['badge' => $badge['name'], 'badgeIcon' => $badge['icon'], 'badgeCode' => $code], 'badges');
        if ($badge['xp'] > 0) {
            $this->reloadUserState($user);
            $this->afterExperienceGain($user, $oldLevel);
        }

        return true;
    }

    /** Gain d'XP idempotent (clé unique par utilisateur). Retourne true si l'XP a été créditée. */
    public function awardExperience(User $user, string $actionKey, int $amount, ?\DateTimeImmutable $now = null): bool
    {
        if ($user->getId() === null) {
            return false;
        }

        $oldLevel = $user->getLevel();
        $inserted = $this->connection()->transactional(fn (): bool => $this->insertAward($user, $actionKey, $amount, $now ?? new \DateTimeImmutable()));
        if ($inserted) {
            $this->reloadUserState($user);
            $this->afterExperienceGain($user, $oldLevel);
        }

        return $inserted;
    }

    /** Enregistre une activité (idempotent, sans exception en cas de requête concurrente). Retourne true si nouvelle. */
    public function recordActivity(User $user, string $key): bool
    {
        if ($user->getId() === null) {
            return false;
        }
        $connection = $this->connection();
        if ($connection->fetchOne('SELECT 1 FROM gamification_activity WHERE user_id = ? AND activity_key = ?', [$user->getId(), $key])) {
            return false;
        }

        return 1 === $connection->executeStatement(
            'INSERT INTO gamification_activity (user_id, activity_key, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$user->getId(), $key, self::dbDateTime(new \DateTimeImmutable())],
        );
    }

    /** Enregistre les notifications en attente (badge, niveau). */
    public function flushNotifications(): void
    {
        if ($this->notificationsPending && $this->entityManager->isOpen()) {
            $this->notificationsPending = false;
            $this->entityManager->flush();
        }
    }

    /** Oublie les caches de requête (commande longue, tests). */
    public function clearCache(): void
    {
        $this->grantedCache = [];
        $this->badgeIds = null;
    }

    /** @return array<string, \DateTimeImmutable> code => date d'obtention */
    public function grantedBadges(User $user): array
    {
        $userId = (int) $user->getId();
        if (!isset($this->grantedCache[$userId])) {
            $rows = $this->connection()->fetchAllKeyValue(
                'SELECT b.code, ub.unlocked_at FROM user_badge ub INNER JOIN badge b ON b.id = ub.badge_id WHERE ub.user_id = ?',
                [$userId],
            );
            $this->grantedCache[$userId] = array_map(static fn (string $date) => new \DateTimeImmutable($date), $rows);
        }

        return $this->grantedCache[$userId];
    }

    // ------------------------------------------------------------------
    // Profil
    // ------------------------------------------------------------------

    /**
     * Badges du profil, groupés par catégorie dans l'ordre du catalogue.
     * $withProgress (propriétaire du profil uniquement) : compteurs et détails de progression
     * (5 requêtes au plus ; sinon une seule requête pour les badges obtenus).
     *
     * @return array{categories: array<string, list<array>>, unlocked: int, total: int}
     */
    public function getProfileBadges(User $user, bool $withProgress): array
    {
        $granted = $user->getId() !== null ? $this->grantedBadges($user) : [];
        $stats = [];
        if ($withProgress && $user->getId() !== null) {
            $lockedRules = array_column(array_filter(BadgeCatalog::all(), static fn (array $b) => !isset($granted[$b['code']])), 'rule');
            $stats = $lockedRules ? $this->collectStats($user, array_values(array_unique($lockedRules))) : [];
        }

        $categories = array_fill_keys(BadgeCatalog::CATEGORIES, []);
        foreach (BadgeCatalog::all() as $code => $badge) {
            $unlockedAt = $granted[$code] ?? null;
            $categories[$badge['category']][] = $badge + [
                'tierLabel' => BadgeCatalog::tierLabel($badge['tier']),
                'unlocked' => $unlockedAt !== null,
                'unlockedAt' => $unlockedAt,
                'progress' => $unlockedAt === null && $withProgress ? $this->progress($badge, $stats) : null,
            ];
        }

        return [
            'categories' => array_filter($categories),
            'unlocked' => count(array_intersect_key($granted, BadgeCatalog::all())),
            'total' => count(BadgeCatalog::all()),
        ];
    }

    /** @return array{current: int, target: int, percent: int, label: string, items: array<string, bool>}|null */
    private function progress(array $badge, array $stats): ?array
    {
        $value = $stats[$badge['rule']] ?? null;
        if ($value === null || in_array($badge['rule'], [BadgeCatalog::RULE_EARLY_MEMBER, BadgeCatalog::RULE_ARCHAEOLOGIST], true)) {
            return null;
        }

        $items = [];
        $list = match ($badge['rule']) {
            BadgeCatalog::RULE_SECTIONS => BadgeCatalog::SECTIONS,
            BadgeCatalog::RULE_LEGAL => BadgeCatalog::LEGAL_ROUTES,
            default => [],
        };
        foreach ($list as $key => $label) {
            $items[$label] = isset($stats['activities'][$key]);
        }

        $target = $badge['threshold'];
        $current = min((int) $value, $target);

        return [
            'current' => $current,
            'target' => $target,
            'percent' => (int) floor($current / max(1, $target) * 100),
            'label' => $badge['rule'] === BadgeCatalog::RULE_LEVEL
                ? sprintf('Niveau %d / %d', $current, $target)
                : sprintf('%d / %d %s', $current, $target, $badge['unit']),
            'items' => $items,
        ];
    }

    // ------------------------------------------------------------------
    // Recalcul (commande app:gamification:recompute)
    // ------------------------------------------------------------------

    /**
     * Rattrapage de la connexion quotidienne du dernier jour connu (last_daily_login_at) si elle
     * n'a pas été créditée (connexions antérieures à ce système). Retourne l'XP créditée.
     */
    public function backfillLastDailyLogin(User $user, bool $dryRun = false): int
    {
        $day = self::parisDate($user->getLastDailyLoginAt());
        if ($day === null) {
            return 0;
        }

        $awards = ['daily:' . $day => self::DAILY_LOGIN_XP];
        if ($user->getLoginStreak() > 0 && $user->getLoginStreak() % self::STREAK_BONUS_EVERY === 0) {
            $awards['streak:' . $day] = self::STREAK_BONUS_XP;
        }

        $total = 0;
        foreach ($awards as $key => $amount) {
            if ($dryRun) {
                $total += $this->hasAward($user, $key) ? 0 : $amount;
            } elseif ($this->awardExperience($user, $key, $amount, $user->getLastDailyLoginAt())) {
                $total += $amount;
            }
        }
        $this->flushNotifications();

        return $total;
    }

    /**
     * Répare les badges obtenus sans ligne « badge:<code> » dans le grand livre (anciennes versions).
     *
     * @return list<string> codes réparés
     */
    public function repairBadgeAwards(User $user, bool $dryRun = false): array
    {
        $repaired = [];
        foreach ($this->grantedBadges($user) as $code => $unlockedAt) {
            $badge = BadgeCatalog::get($code);
            if (!$badge || $this->hasAward($user, 'badge:' . $code)) {
                continue;
            }
            if ($dryRun || $this->awardExperience($user, 'badge:' . $code, $badge['xp'], $unlockedAt)) {
                $repaired[] = $code;
            }
        }
        $this->flushNotifications();

        return $repaired;
    }

    /**
     * Simulation (--dry-run) : badges que l'utilisateur obtiendrait, cascade des badges de niveau comprise.
     *
     * @return list<string>
     */
    public function simulateBadges(User $user, int $extraXp = 0): array
    {
        $granted = $this->grantedBadges($user);
        $stats = $this->collectStats($user, array_values(array_unique(array_column(BadgeCatalog::all(), 'rule'))));
        $simulated = (new User())->setExperience($user->getExperience() + $extraXp); // simple calculatrice de niveau
        $codes = [];
        do {
            $new = false;
            $stats[BadgeCatalog::RULE_LEVEL] = $simulated->getLevel();
            foreach (BadgeCatalog::all() as $code => $badge) {
                if (!isset($granted[$code]) && !in_array($code, $codes, true) && $this->isEligible($badge, $stats)) {
                    $codes[] = $code;
                    $simulated->setExperience($simulated->getExperience() + $badge['xp']);
                    $new = true;
                }
            }
        } while ($new);

        return $codes;
    }

    /** Somme du grand livre pour l'utilisateur. */
    public function ledgerTotal(User $user): int
    {
        return (int) $this->connection()->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM experience_award WHERE user_id = ?', [$user->getId()]);
    }

    /** Aligne user.experience sur la somme du grand livre. Retourne l'ancienne valeur. */
    public function resetExperienceToLedger(User $user): int
    {
        $old = $user->getExperience();
        $oldLevel = $user->getLevel();
        $this->connection()->executeStatement('UPDATE `user` SET experience = ? WHERE id = ?', [$this->ledgerTotal($user), $user->getId()]);
        $this->reloadUserState($user);
        if ($user->getLevel() > $oldLevel) {
            $this->afterExperienceGain($user, $oldLevel);
            $this->flushNotifications();
        }

        return $old;
    }

    // ------------------------------------------------------------------
    // Interne
    // ------------------------------------------------------------------

    /**
     * Insère la ligne du grand livre et crédite l'XP (à appeler dans une transaction).
     * Retourne false si la clé existe déjà (aucun crédit).
     */
    private function insertAward(User $user, string $actionKey, int $amount, \DateTimeImmutable $now): bool
    {
        $connection = $this->connection();
        $rows = $connection->executeStatement(
            'INSERT INTO experience_award (user_id, action_key, amount, created_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = id',
            [$user->getId(), $actionKey, max(0, $amount), self::dbDateTime($now)],
        );
        if ($rows !== 1) {
            return false;
        }
        if ($amount > 0) {
            $connection->executeStatement('UPDATE `user` SET experience = experience + ? WHERE id = ?', [$amount, $user->getId()]);
        }

        return true;
    }

    /** Après un gain d'XP : notification de niveau, puis badges de niveau (la récursion via grantBadge gère la cascade). */
    private function afterExperienceGain(User $user, int $oldLevel): void
    {
        $level = $user->getLevel();
        if ($level > $oldLevel) {
            $this->notify($user, Notification::TYPE_LEVEL_UP, ['level' => $level], null);
        }
        $this->evaluate($user, [BadgeCatalog::RULE_LEVEL]);
    }

    private function notify(User $user, string $type, array $data, ?string $groupKey): void
    {
        if ($user->getId() === null || $user->getUsername() === null) {
            return;
        }

        $this->notifications->notify(
            $user,
            $type,
            null,
            $data,
            $this->urlGenerator->generate('app_profil_show', ['username' => $user->getUsername()]),
            $groupKey,
            flush: false,
        );
        $this->notificationsPending = true;
    }

    /**
     * Recharge experience / login_streak / last_daily_login_at depuis la base (écrits en SQL) et les
     * déclare comme valeurs d'origine à l'ORM : un flush ultérieur ne les réécrira pas (pas d'écrasement).
     */
    private function reloadUserState(User $user): void
    {
        $row = $this->connection()->fetchAssociative('SELECT experience, login_streak, last_daily_login_at FROM `user` WHERE id = ?', [$user->getId()]);
        if (!$row) {
            return;
        }

        $lastDaily = $row['last_daily_login_at'] !== null ? new \DateTimeImmutable($row['last_daily_login_at']) : null;
        $user->setExperience((int) $row['experience']);
        $user->setLoginStreak((int) $row['login_streak']);
        $user->setLastDailyLoginAt($lastDaily);

        if ($this->entityManager->contains($user)) {
            $unitOfWork = $this->entityManager->getUnitOfWork();
            $oid = spl_object_id($user);
            $unitOfWork->setOriginalEntityProperty($oid, 'experience', $user->getExperience());
            $unitOfWork->setOriginalEntityProperty($oid, 'loginStreak', $user->getLoginStreak());
            $unitOfWork->setOriginalEntityProperty($oid, 'lastDailyLoginAt', $lastDaily);
        }
    }

    private function hasAward(User $user, string $key): bool
    {
        return (bool) $this->connection()->fetchOne('SELECT 1 FROM experience_award WHERE user_id = ? AND action_key = ?', [$user->getId(), $key]);
    }

    /** @return array<string, int> */
    private function badgeIds(): array
    {
        return $this->badgeIds ??= array_map('intval', $this->connection()->fetchAllKeyValue('SELECT code, id FROM badge'));
    }

    private function connection(): Connection
    {
        return $this->entityManager->getConnection();
    }

    private static function parisDate(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('Y-m-d');
    }

    /** Nombre de jours calendaires entre deux dates « Y-m-d » (négatif si $to < $from). */
    private static function daysBetween(string $from, string $to): int
    {
        $utc = new \DateTimeZone('UTC');

        return (int) (new \DateTimeImmutable($from, $utc))->diff(new \DateTimeImmutable($to, $utc))->format('%r%a');
    }

    /** Format DATETIME dans le fuseau par défaut de l'application (celui utilisé par Doctrine à la lecture). */
    private static function dbDateTime(\DateTimeImmutable $date): string
    {
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
    }
}
