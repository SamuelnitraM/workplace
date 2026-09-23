<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Écrite à la main (ne pas régénérer avec doctrine:migrations:diff).
 *
 * Instantané du catalogue App\Gamification\BadgeCatalog au moment de la migration : une base neuve
 * a ainsi des badges débloquables dès `doctrine:migrations:migrate`. Par la suite, la source de
 * vérité reste le code : `php bin/console app:gamification:sync-badges` (idempotent) applique les
 * évolutions du catalogue. La contrainte UNIQ_EXPERIENCE_AWARD_KEY (user_id, action_key), qui rend
 * le grand livre d'XP idempotent, existe déjà (Version20260904175611 / Version20260905091000).
 */
final class Version20260927100000 extends AbstractMigration
{
    private const BADGES = [
        // code, nom, catégorie, description, icône, XP
        ['pioneer_1', 'Pionnier I', 'Forum', 'Publier votre premier sujet.', '📝', 50],
        ['pioneer_2', 'Pionnier II', 'Forum', 'Publier 50 sujets.', '📜', 150],
        ['pioneer_3', 'Pionnier III', 'Forum', 'Publier 100 sujets.', '📚', 300],
        ['popular_1', 'Populaire I', 'Forum', 'Recevoir votre premier vote positif.', '👍', 50],
        ['popular_2', 'Populaire II', 'Forum', 'Recevoir 50 votes positifs.', '🌟', 150],
        ['popular_3', 'Populaire III', 'Forum', 'Recevoir 100 votes positifs.', '👑', 300],
        ['devoted_1', 'Dévoué I', 'Forum', 'Recevoir votre premier vote « aide ».', '🤝', 50],
        ['devoted_2', 'Dévoué II', 'Forum', 'Recevoir 50 votes « aide ».', '🛡️', 150],
        ['devoted_3', 'Dévoué III', 'Forum', 'Recevoir 100 votes « aide ».', '💠', 300],
        ['master_blacksmith', 'Maître forgeron', 'Forum', 'Créer 10 sujets ayant chacun au moins 10 réponses écrites par d\'autres membres.', '⚒️', 300],
        ['curious', 'Curieux', 'Exploration', 'Visiter toutes les rubriques du site : accueil, forum, groupes, todo liste, listes d\'armée, amis, messages, notifications et votre profil.', '🧭', 50],
        ['jurist', 'Juriste', 'Exploration', 'Consulter les mentions légales et les conditions d\'utilisation.', '⚖️', 50],
        ['archaeologist', 'Archéologue', 'Exploration', 'Ouvrir un sujet datant de plus d\'un an.', '🏺', 50],
        ['heroic', 'Héroïque', 'Niveau', 'Atteindre le niveau 10.', '⚔️', 200],
        ['legendary', 'Légendaire', 'Niveau', 'Atteindre le niveau 25.', '🐉', 400],
        ['immortal', 'Immortel', 'Niveau', 'Atteindre le niveau 50, le niveau maximum.', '💀', 0],
        ['vanguard', 'Avant-garde', 'Spécial', 'Faire partie des 1000 premiers inscrits.', '🚩', 0],
    ];

    public function getDescription(): string
    {
        return 'Gamification : catalogue des 17 badges (Forum, Exploration, Niveau, Spécial) dans la table badge, suppression des anciens badges (et de leurs attributions)';
    }

    public function up(Schema $schema): void
    {
        $codes = array_column(self::BADGES, 0);
        // Anciens badges (collector, mentor, early_bird…) : user_badge supprimé par ON DELETE CASCADE
        $this->addSql(
            sprintf('DELETE FROM badge WHERE code NOT IN (%s)', implode(', ', array_fill(0, count($codes), '?'))),
            $codes,
        );

        foreach (self::BADGES as [$code, $name, $category, $description, $icon, $xp]) {
            $this->addSql(
                'INSERT INTO badge (code, name, category, description, hidden_description, icon, hidden, xp_reward)
                 VALUES (?, ?, ?, ?, \'\', ?, 0, ?)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), description = VALUES(description),
                     icon = VALUES(icon), hidden = 0, xp_reward = VALUES(xp_reward)',
                [$code, $name, $category, $description, $icon, $xp],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $codes = array_column(self::BADGES, 0);
        $this->addSql(
            sprintf('DELETE FROM badge WHERE code IN (%s)', implode(', ', array_fill(0, count($codes), '?'))),
            $codes,
        );
    }
}
