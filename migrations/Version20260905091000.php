<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260905091000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore the XP columns and award ledger removed by an obsolete rollback migration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD experience INT NOT NULL, ADD last_daily_login_at DATETIME DEFAULT NULL, ADD login_streak INT NOT NULL, ADD profile_bonus_awarded TINYINT(1) NOT NULL');
        $this->addSql('CREATE TABLE experience_award (id INT AUTO_INCREMENT NOT NULL, action_key VARCHAR(150) NOT NULL, amount INT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_EXPERIENCE_AWARD_KEY (user_id, action_key), INDEX IDX_E90952F1A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE experience_award ADD CONSTRAINT FK_E90952F1A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE experience_award DROP FOREIGN KEY FK_E90952F1A76ED395');
        $this->addSql('DROP TABLE experience_award');
        $this->addSql('ALTER TABLE user DROP experience, DROP last_daily_login_at, DROP login_streak, DROP profile_bonus_awarded');
    }
}
