<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Hand-written migration.
 *
 * Appeals of suspended members (appeal): message, sanction appealed against (reason and end captured when sent),
 * decision of the moderation (lifted or upheld) and answer sent to the member.
 */
final class Version20261031100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Réclamations des membres sanctionnés';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE appeal (id INT AUTO_INCREMENT NOT NULL, message LONGTEXT NOT NULL, suspension_reason LONGTEXT DEFAULT NULL, suspended_until DATETIME DEFAULT NULL, status VARCHAR(10) NOT NULL, created_at DATETIME NOT NULL, handled_at DATETIME DEFAULT NULL, response LONGTEXT DEFAULT NULL, member_id INT NOT NULL, handled_by_id INT DEFAULT NULL, INDEX IDX_967943517597D3FE (member_id), INDEX IDX_96794351FE65AF40 (handled_by_id), INDEX idx_appeal_status_created (status, created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE appeal ADD CONSTRAINT FK_967943517597D3FE FOREIGN KEY (member_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE appeal ADD CONSTRAINT FK_96794351FE65AF40 FOREIGN KEY (handled_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE appeal DROP FOREIGN KEY FK_967943517597D3FE');
        $this->addSql('ALTER TABLE appeal DROP FOREIGN KEY FK_96794351FE65AF40');
        $this->addSql('DROP TABLE appeal');
    }
}
