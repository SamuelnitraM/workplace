<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904205733 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE experience_award DROP FOREIGN KEY `FK_E90952F1A76ED395`');
        $this->addSql('DROP TABLE experience_award');
        $this->addSql('ALTER TABLE user DROP experience, DROP last_daily_login_at, DROP login_streak, DROP profile_bonus_awarded');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE experience_award (id INT AUTO_INCREMENT NOT NULL, action_key VARCHAR(150) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_general_ci`, amount INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_EXPERIENCE_AWARD_KEY (user_id, action_key), INDEX IDX_E90952F1A76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_general_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE experience_award ADD CONSTRAINT `FK_E90952F1A76ED395` FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD experience INT NOT NULL, ADD last_daily_login_at DATETIME DEFAULT NULL, ADD login_streak INT NOT NULL, ADD profile_bonus_awarded TINYINT NOT NULL');
    }
}
