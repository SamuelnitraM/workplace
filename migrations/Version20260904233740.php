<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260904233740 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE gamification_activity (id INT AUTO_INCREMENT NOT NULL, activity_key VARCHAR(180) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_97B556A7A76ED395 (user_id), UNIQUE INDEX UNIQ_GAMIFICATION_ACTIVITY (user_id, activity_key), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE gamification_activity ADD CONSTRAINT FK_97B556A7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE gamification_activity DROP FOREIGN KEY FK_97B556A7A76ED395');
        $this->addSql('DROP TABLE gamification_activity');
    }
}
