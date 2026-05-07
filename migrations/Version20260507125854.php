<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260507125854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE group_invitation (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, invited_by_id INT NOT NULL, invited_user_id INT NOT NULL, usergroup_id INT NOT NULL, INDEX IDX_26D00010A7B4A7E3 (invited_by_id), INDEX IDX_26D00010C58DAD6E (invited_user_id), INDEX IDX_26D00010D2112630 (usergroup_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE group_invitation ADD CONSTRAINT FK_26D00010A7B4A7E3 FOREIGN KEY (invited_by_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE group_invitation ADD CONSTRAINT FK_26D00010C58DAD6E FOREIGN KEY (invited_user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE group_invitation ADD CONSTRAINT FK_26D00010D2112630 FOREIGN KEY (usergroup_id) REFERENCES `group` (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE group_invitation DROP FOREIGN KEY FK_26D00010A7B4A7E3');
        $this->addSql('ALTER TABLE group_invitation DROP FOREIGN KEY FK_26D00010C58DAD6E');
        $this->addSql('ALTER TABLE group_invitation DROP FOREIGN KEY FK_26D00010D2112630');
        $this->addSql('DROP TABLE group_invitation');
    }
}
