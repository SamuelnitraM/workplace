<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904175611 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE badge (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(80) NOT NULL, name VARCHAR(120) NOT NULL, category VARCHAR(30) NOT NULL, description LONGTEXT NOT NULL, xp_reward INT NOT NULL, UNIQUE INDEX UNIQ_BADGE_CODE (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE experience_award (id INT AUTO_INCREMENT NOT NULL, action_key VARCHAR(150) NOT NULL, amount INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_E90952F1A76ED395 (user_id), UNIQUE INDEX UNIQ_EXPERIENCE_AWARD_KEY (user_id, action_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_badge (id INT AUTO_INCREMENT NOT NULL, unlocked_at DATETIME NOT NULL, user_id INT NOT NULL, badge_id INT NOT NULL, INDEX IDX_1C32B345A76ED395 (user_id), INDEX IDX_1C32B345F7A2C2FC (badge_id), UNIQUE INDEX UNIQ_USER_BADGE (user_id, badge_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE experience_award ADD CONSTRAINT FK_E90952F1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_badge ADD CONSTRAINT FK_1C32B345A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_badge ADD CONSTRAINT FK_1C32B345F7A2C2FC FOREIGN KEY (badge_id) REFERENCES badge (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user ADD experience INT NOT NULL, ADD last_daily_login_at DATETIME DEFAULT NULL, ADD login_streak INT NOT NULL, ADD profile_bonus_awarded TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE experience_award DROP FOREIGN KEY FK_E90952F1A76ED395');
        $this->addSql('ALTER TABLE user_badge DROP FOREIGN KEY FK_1C32B345A76ED395');
        $this->addSql('ALTER TABLE user_badge DROP FOREIGN KEY FK_1C32B345F7A2C2FC');
        $this->addSql('DROP TABLE badge');
        $this->addSql('DROP TABLE experience_award');
        $this->addSql('DROP TABLE user_badge');
        $this->addSql('ALTER TABLE user DROP experience, DROP last_daily_login_at, DROP login_streak, DROP profile_bonus_awarded');
    }
}
