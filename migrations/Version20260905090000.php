<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add the next gamification badge catalogue';
    }

    public function up(Schema $schema): void
    {
        $badges = [
            ['first_upvote', 'Première étincelle', 'Contribution', 'Recevoir son tout premier vote positif (Upvote).', '✨', 50, 0],
            ['treasure_hunter', 'Chasseur de trésors', 'Exploration', 'Visiter toutes les rubriques ou pages principales du site.', '🗺️', 100, 0],
            ['ambassador', 'Ambassadeur', 'Communauté', 'Partager un contenu du site ou parrainer un nouvel utilisateur.', '📣', 100, 0],
            ['collector', 'Collectionneur', 'Collection', 'Débloquer un total de 10 badges différents.', '🧰', 150, 0],
            ['popular_2', 'Populaire II', 'Contribution', 'Recevoir 50 votes positifs au total.', '👍', 100, 0],
            ['popular_3', 'Populaire III', 'Contribution', 'Recevoir 100 votes positifs au total.', '🏆', 150, 0],
            ['prolific_2', 'Prolifique II', 'Contribution', 'Publier 50 contenus.', '✍️', 100, 0],
            ['prolific_3', 'Prolifique III', 'Contribution', 'Publier 100 contenus.', '🖋️', 150, 0],
            ['master_blacksmith', 'Maître forgeron', 'Contribution', 'Créer 50 sujets avec plus de 10 réponses.', '⚒️', 200, 0],
            ['heroic', 'Héroïque', 'Progression', 'Atteindre le niveau 10.', '🛡️', 150, 0],
            ['legendary', 'Légendaire', 'Progression', 'Atteindre le niveau 25.', '👑', 250, 0],
            ['immortal', 'Immortel', 'Progression', 'Atteindre le niveau 50.', '♾️', 500, 0],
            ['jurist', 'Juriste', 'Secrets', 'Visiter les mentions légales ou les conditions d’utilisation jusqu’en bas.', '⚖️', 100, 1],
            ['archaeologist', 'Archéologue', 'Secrets', 'Ouvrir un sujet ou un article datant de plus d’un an.', '🏺', 100, 1],
            ['early_bird', 'Lève-tôt', 'Secrets', 'Se connecter ou poster entre 5 h et 6 h 30.', '🌅', 100, 1],
            ['first_in_class', 'Premier de la classe', 'Secrets', 'Être le premier à commenter un sujet de moins de 24 heures.', '🥇', 150, 1],
            ['minimalist', 'Minimaliste', 'Secrets', 'Laisser sa biographie entièrement vide durant un mois.', '◻️', 150, 1],
        ];

        foreach ($badges as $badge) {
            $this->addSql(
                'INSERT INTO badge (code, name, category, description, hidden_description, icon, hidden, xp_reward) VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE code = code',
                [$badge[0], $badge[1], $badge[2], $badge[3], 'Condition secrète à découvrir', $badge[4], $badge[6], $badge[5]]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM badge WHERE code IN ('first_upvote', 'treasure_hunter', 'ambassador', 'collector', 'popular_2', 'popular_3', 'prolific_2', 'prolific_3', 'master_blacksmith', 'heroic', 'legendary', 'immortal', 'jurist', 'archaeologist', 'early_bird', 'first_in_class', 'minimalist')");
    }
}
