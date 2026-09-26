<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Specific specifications, lot 1 "Finishing":
 *  - category.read_only / category.icon: read-only categories (administrators publish, source of the "Actualités" feed filter)
 *    and optional icon of a category;
 *  - army_list.view_count / export_count / duplication_count: audience of army lists by other members;
 *  - user.cover: banner image of the profile;
 *  - member_daily_activity: one row per member and per active day (activity statistics).
 */
final class Version20261010100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Lot 1 : catégories en lecture seule et icônes, compteurs des listes d\'armée, bannière de profil, activité quotidienne';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE category ADD read_only TINYINT DEFAULT 0 NOT NULL, ADD icon VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE army_list ADD view_count INT DEFAULT 0 NOT NULL, ADD export_count INT DEFAULT 0 NOT NULL, ADD duplication_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user ADD cover VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE member_daily_activity (id INT AUTO_INCREMENT NOT NULL, day DATE NOT NULL, user_id INT NOT NULL, INDEX IDX_F296073DA76ED395 (user_id), INDEX idx_member_daily_activity_day (day), UNIQUE INDEX uniq_member_daily_activity (user_id, day), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE member_daily_activity ADD CONSTRAINT FK_F296073DA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE member_daily_activity DROP FOREIGN KEY FK_F296073DA76ED395');
        $this->addSql('DROP TABLE member_daily_activity');
        $this->addSql('ALTER TABLE user DROP cover');
        $this->addSql('ALTER TABLE army_list DROP view_count, DROP export_count, DROP duplication_count');
        $this->addSql('ALTER TABLE category DROP read_only, DROP icon');
    }
}
